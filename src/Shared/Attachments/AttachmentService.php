<?php
/**
 * Validates and stores note-attachment uploads.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Attachments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Config\Variables;
use WP_Error;

/**
 * Gatekeeper for attachment uploads: mime/extension/content checks, safe move
 * into the protected storage tree, DB row creation, and deletion helpers.
 */
final class AttachmentService {
	/**
	 * Every mime type the plugin knows how to handle, with its canonical
	 * extension. Admins can opt-in to a subset for uploads, but downloads
	 * always validate against this full list — disabling a type doesn't
	 * lock readers out of files already in storage.
	 */
	private const SUPPORTED_MIMES = array(
		'application/pdf'                                 => 'pdf',
		'image/jpeg'                                      => 'jpg',
		'image/png'                                       => 'png',
		'image/gif'                                       => 'gif',
		'image/webp'                                      => 'webp',
		'application/msword'                              => 'doc',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'application/vnd.ms-excel'                        => 'xls',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
		'application/vnd.ms-powerpoint'                   => 'ppt',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
		'application/vnd.oasis.opendocument.text'         => 'odt',
		'application/vnd.oasis.opendocument.spreadsheet'  => 'ods',
		'application/vnd.oasis.opendocument.presentation' => 'odp',
		'application/rtf'                                 => 'rtf',
		'text/plain'                                      => 'txt',
		'text/csv'                                        => 'csv',
	);

	/**
	 * Default active set when the option is unset. Intentionally narrow —
	 * just photos and PDFs, which cover most order-update conversations.
	 * Admins opt-in to docs/spreadsheets/text formats explicitly so the
	 * smallest install runs the smallest attack surface by default.
	 */
	public const DEFAULT_ACTIVE_MIMES = array(
		'application/pdf',
		'image/jpeg',
		'image/png',
	);

	/**
	 * Filename fragments that are never safe to host. Matched
	 * case-insensitively anywhere in the original name so double-extension
	 * tricks (`evil.php.jpg`) are caught regardless of the claimed mime.
	 * Stored files are renamed to UUID + canonical extension anyway, but we
	 * reject at the gate so a request with a poisoned filename never sees
	 * disk in the first place.
	 */
	private const BANNED_FILENAME_FRAGMENTS = array(
		// PHP variants.
		'.php',
		'.phtml',
		'.phar',
		'.pht',
		'.php3',
		'.php4',
		'.php5',
		'.php7',
		'.phps',
		// Server-rendered scripts.
		'.html',
		'.htm',
		'.shtml',
		'.shtm',
		'.dhtml',
		'.xhtml',
		// Client scripts.
		'.js',
		'.mjs',
		'.jse',
		'.svg',
		// Other interpreters.
		'.pl',
		'.cgi',
		'.py',
		'.rb',
		'.lua',
		// Native executables / shells.
		'.exe',
		'.bat',
		'.cmd',
		'.com',
		'.scr',
		'.msi',
		'.vbs',
		'.ps1',
		'.sh',
		// Server config files.
		'.htaccess',
		'.htpasswd',
	);

	/**
	 * Text-form signatures — checked case-insensitively against the first
	 * 8 KB of every uploaded file. Catches script content smuggled inside
	 * a file whose mime / filename look benign.
	 */
	private const BANNED_TEXT_SIGNATURES = array(
		'<?php',
		'<?=',
		'<script',
		'<html',
		'<!doctype html',
		'#!/bin/',
		'#!/usr/',
	);

	/**
	 * Binary magic-byte signatures — compared byte-exact against the first
	 * 8 KB of the upload. Catches native executables and bytecode that
	 * wouldn't survive case-insensitive text search.
	 */
	private const BANNED_BINARY_SIGNATURES = array(
		"MZ\x90\x00",       // Windows PE (.exe / .dll).
		"\x7fELF",          // Linux ELF binary.
		"\xCA\xFE\xBA\xBE", // Java class file.
		"\xCF\xFA\xED\xFE", // Mach-O 64-bit.
		"\xFE\xED\xFA\xCE", // Mach-O 32-bit big-endian.
		"\xCA\xFE\xBA\xBF", // Mach-O FAT binary.
	);

	/**
	 * Inject dependencies.
	 *
	 * @param AttachmentsDb $attachments_db Injected dependency.
	 */
	public function __construct( private AttachmentsDb $attachments_db ) {}

	/**
	 * Mime types currently allowed for upload, per admin settings.
	 * Always intersected with SUPPORTED_MIMES so a stale option value
	 * can't widen the surface beyond what the codebase actually handles.
	 *
	 * @return string[]
	 */
	public static function allowed_mime_types(): array {
		$configured = get_option( Constants::ALLOWED_MIMES_OPTION, null );

		if ( ! is_array( $configured ) || empty( $configured ) ) {
			return array_values( array_intersect( self::DEFAULT_ACTIVE_MIMES, array_keys( self::SUPPORTED_MIMES ) ) );
		}

		return array_values( array_intersect( $configured, array_keys( self::SUPPORTED_MIMES ) ) );
	}

	/**
	 * Mime types the plugin can read back from storage, regardless of
	 * the current upload setting. Use this when serving an existing file.
	 *
	 * @return string[]
	 */
	public static function supported_mime_types(): array {
		return array_keys( self::SUPPORTED_MIMES );
	}

	/**
	 * Resolve the canonical extension for a stored mime, or empty string
	 * if the type is unknown to the plugin entirely.
	 *
	 * @param string $mime Mime type.
	 */
	public static function extension_for_mime( string $mime ): string {
		return (string) ( self::SUPPORTED_MIMES[ $mime ] ?? '' );
	}

	/** Maximum allowed upload size in bytes. */
	public static function max_bytes(): int {
		return Variables::getMaxAttachmentBytes();
	}

	/**
	 * Store an uploaded file and insert a DB row.
	 *
	 * @param array{name:string,tmp_name:string,type:string,size:int,error:int}              $file    PHP $_FILES entry.
	 * @param array{order_id:int,update_id:int,note_id:int,note_type:string,uploaded_by:int} $context Where the file belongs.
	 * @return array|WP_Error
	 */
	public function store_upload( array $file, array $context ): array|WP_Error {
		$handler = apply_filters( 'order_updates_for_woo_attachment_storage_handler', null, $file, $context );

		if ( is_callable( $handler ) ) {
			$result = $handler( $file, $context );

			if ( is_wp_error( $result ) || is_array( $result ) ) {
				return $result;
			}
		}

		$upload_error = isset( $file['error'] ) ? (int) $file['error'] : -1;

		if ( UPLOAD_ERR_OK !== $upload_error ) {
			if ( UPLOAD_ERR_INI_SIZE === $upload_error || UPLOAD_ERR_FORM_SIZE === $upload_error ) {
				$php_max = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
				$limit   = $php_max > 0 ? ' ' . sprintf( /* translators: %s: formatted file size */ __( '(server limit: %s)', 'order-updates-for-woo' ), size_format( $php_max ) ) : '';
				return new WP_Error( 'order_updates_for_woo_attachment_too_large', __( 'File exceeds the server upload limit.', 'order-updates-for-woo' ) . $limit, array( 'status' => 413 ) );
			}

			return new WP_Error( 'order_updates_for_woo_upload_failed', __( 'Upload failed.', 'order-updates-for-woo' ), array( 'status' => 400 ) );
		}

		$size = (int) ( $file['size'] ?? 0 );

		if ( $size <= 0 || $size > Variables::getMaxAttachmentBytes() ) {
			return new WP_Error( 'order_updates_for_woo_attachment_too_large', __( 'File is too large.', 'order-updates-for-woo' ), array( 'status' => 413 ) );
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'order_updates_for_woo_upload_invalid', __( 'Invalid upload.', 'order-updates-for-woo' ), array( 'status' => 400 ) );
		}

		// Reject script / executable filenames before they ever touch
		// downstream sanitisation. Catches double-extension tricks like
		// `evil.php.jpg` regardless of what the mime claim is.
		if ( ! self::is_filename_safe( (string) ( $file['name'] ?? '' ) ) ) {
			return new WP_Error( 'order_updates_for_woo_attachment_unsupported_type', __( 'File name contains an extension that is not permitted.', 'order-updates-for-woo' ), array( 'status' => 415 ) );
		}

		// Magic-byte scan — looks at the actual bytes for PHP open tags,
		// scripts, native executables, etc. Independent of the claimed
		// mime so a script smuggled inside a fake-JPEG still fails the gate.
		if ( ! self::is_file_content_safe( $tmp ) ) {
			return new WP_Error( 'order_updates_for_woo_attachment_unsupported_type', __( 'File content was rejected as potentially executable.', 'order-updates-for-woo' ), array( 'status' => 415 ) );
		}

		$mime = $this->detect_mime( $tmp, (string) ( $file['name'] ?? '' ) );

		// Active list, not the full supported catalog — admin-disabled types
		// are rejected at upload but still readable when downloaded.
		if ( ! in_array( $mime, self::allowed_mime_types(), true ) ) {
			return new WP_Error( 'order_updates_for_woo_attachment_unsupported_type', __( 'Unsupported file type.', 'order-updates-for-woo' ), array( 'status' => 415 ) );
		}

		$order_id  = (int) $context['order_id'];
		$update_id = (int) $context['update_id'];
		$note_id   = (int) $context['note_id'];
		$note_type = (string) $context['note_type'];

		if ( ! $order_id || ! $update_id || ! $note_id ) {
			return new WP_Error( 'order_updates_for_woo_attachment_invalid_context', __( 'Missing order/update/note reference.', 'order-updates-for-woo' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $note_type, array( Constants::NOTE_TYPE_INTERNAL, Constants::NOTE_TYPE_CUSTOMER ), true ) ) {
			return new WP_Error( 'order_updates_for_woo_attachment_invalid_note_type', __( 'Invalid note type.', 'order-updates-for-woo' ), array( 'status' => 400 ) );
		}

		$dir           = AttachmentStorage::ensure_note_dir( $order_id, $update_id, $note_id );
		$original_name = sanitize_file_name( (string) ( $file['name'] ?? 'file' ) );
		$extension     = self::SUPPORTED_MIMES[ $mime ];
		$stored_name   = wp_generate_uuid4() . '.' . $extension;

		$moved = $this->move_via_wp_handle_upload( $file, $dir, $stored_name );

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$destination = $moved;
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.chmod_chmod -- WP_Filesystem method, not the PHP chmod() function.
		AttachmentStorage::filesystem()?->chmod( $destination, FS_CHMOD_FILE );

		$id = $this->attachments_db->create(
			array(
				'order_id'      => $order_id,
				'update_id'     => $update_id,
				'note_id'       => $note_id,
				'note_type'     => $note_type,
				'file_name'     => $stored_name,
				'original_name' => $original_name,
				'mime_type'     => $mime,
				'file_size'     => $size,
				'uploaded_by'   => (int) $context['uploaded_by'],
				'uploaded_at'   => current_time( 'mysql', true ),
			) 
		);

		if ( ! $id ) {
			AttachmentStorage::delete_file( $destination );
			return new WP_Error( 'order_updates_for_woo_attachment_db_failed', __( 'Could not record the attachment.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		return array_merge( $this->attachments_db->get( $id ), array( 'absolute_path' => $destination ) );
	}

	/**
	 * Delete an attachment's file from disk and its DB row. Returns false if
	 * the record is not found. Fires the before-delete action and allows addons
	 * to handle the filesystem removal via the `order_updates_for_woo_attachment_delete` filter.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function delete( int $attachment_id ): bool {
		$record = $this->attachments_db->get( $attachment_id );

		if ( empty( $record ) ) {
			return false;
		}

		do_action( 'order_updates_for_woo_attachment_before_delete', $record, $attachment_id );

		$handled = (bool) apply_filters( 'order_updates_for_woo_attachment_delete', false, $record, $attachment_id );

		if ( ! $handled ) {
			$note_dir = AttachmentStorage::note_dir(
				(int) $record['order_id'],
				(int) $record['update_id'],
				(int) $record['note_id']
			);

			AttachmentStorage::delete_file( $note_dir . '/' . $record['file_name'] );

			// Walk up from the note dir, pruning anything that's now empty.
			// Removing one attachment can leave the note/update/order dirs
			// as hollow shells containing only their index.html guard.
			AttachmentStorage::prune_empty_ancestor_dirs( $note_dir );
		}

		return $this->attachments_db->delete( $attachment_id );
	}

	/**
	 * Remove all attachment DB rows and the full filesystem directory tree for an order.
	 *
	 * @param int $order_id Order id.
	 */
	public function delete_all_for_order( int $order_id ): void {
		if ( ! $order_id ) {
			return;
		}

		$this->attachments_db->delete_for_order( $order_id );
		AttachmentStorage::delete_order_dir( $order_id );
	}

	/**
	 * Remove all attachment DB rows and the update's filesystem directory.
	 *
	 * @param int $order_id  Order id.
	 * @param int $update_id Update id.
	 */
	public function delete_all_for_update( int $order_id, int $update_id ): void {
		if ( ! $order_id || ! $update_id ) {
			return;
		}

		$this->attachments_db->delete_for_update( $update_id );
		AttachmentStorage::delete_update_dir( $order_id, $update_id );

		// The update dir is gone now; if it was the last update under this
		// order, the order dir is left as `orders/{id}/index.html` only —
		// prune it so deleted updates don't leave hollow folders behind.
		AttachmentStorage::prune_empty_ancestor_dirs( AttachmentStorage::order_dir( $order_id ) );
	}

	/**
	 * Resolve the absolute filesystem path for an attachment DB record.
	 * Required keys: order_id, update_id, note_id, file_name.
	 *
	 * @param array $record Attachment DB record.
	 */
	public function absolute_path_for( array $record ): string {
		return AttachmentStorage::note_dir(
			(int) $record['order_id'],
			(int) $record['update_id'],
			(int) $record['note_id']
		) . '/' . (string) $record['file_name'];
	}

	/**
	 * Move the uploaded file into the plugin's attachments tree via wp_handle_upload.
	 * The upload_dir filter redirects WordPress's destination so the file lands inside
	 * our protected dir instead of wp-content/uploads. Returns the absolute path on
	 * success, or a WP_Error on failure.
	 *
	 * @param array  $file        PHP $_FILES entry.
	 * @param string $dir         Destination directory.
	 * @param string $stored_name Final stored file name.
	 */
	private function move_via_wp_handle_upload( array $file, string $dir, string $stored_name ): string|WP_Error {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$override_dir = static function ( array $dirs ) use ( $dir ): array {
			$dirs['path']   = $dir;
			$dirs['url']    = '';
			$dirs['subdir'] = '';
			return $dirs;
		};

		$override_name = static fn(): string => $stored_name;

		add_filter( 'upload_dir', $override_dir, PHP_INT_MAX );

		$result = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				// wp_handle_upload expects `[ ext_regex => mime ]`, NOT
				// `[ mime => ext ]`. Wrong shape rejects every upload.
				'mimes'                    => self::wp_handle_upload_mimes(),
				'unique_filename_callback' => $override_name,
			)
		);

		remove_filter( 'upload_dir', $override_dir, PHP_INT_MAX );

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'order_updates_for_woo_attachment_move_failed', (string) $result['error'], array( 'status' => 500 ) );
		}

		return (string) ( $result['file'] ?? '' );
	}

	/**
	 * Build the `[ ext_pattern => mime ]` map that wp_handle_upload and
	 * wp_check_filetype_and_ext both want. JPEG covers `jpg|jpeg|jpe` so the
	 * common variants all resolve to image/jpeg.
	 *
	 * @return array<string, string>
	 */
	private static function wp_handle_upload_mimes(): array {
		return array(
			'pdf'          => 'application/pdf',
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'doc'          => 'application/msword',
			'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'          => 'application/vnd.ms-excel',
			'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'          => 'application/vnd.ms-powerpoint',
			'pptx'         => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'odt'          => 'application/vnd.oasis.opendocument.text',
			'ods'          => 'application/vnd.oasis.opendocument.spreadsheet',
			'odp'          => 'application/vnd.oasis.opendocument.presentation',
			'rtf'          => 'application/rtf',
			'txt'          => 'text/plain',
			'csv'          => 'text/csv',
		);
	}

	/**
	 * Reject filenames that contain any banned extension fragment, even
	 * embedded mid-name. Case-insensitive.
	 *
	 * @param string $filename Original upload file name.
	 */
	private static function is_filename_safe( string $filename ): bool {
		$lower = strtolower( $filename );

		foreach ( self::BANNED_FILENAME_FRAGMENTS as $fragment ) {
			if ( str_contains( $lower, $fragment ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Inspect the first 8 KB of a file on disk for executable signatures.
	 * Returns false if any banned signature is found. Defense-in-depth on
	 * top of mime detection — a sophisticated attacker can craft a file
	 * whose mime/extension look benign but whose content executes; this
	 * scan rejects those before the file ever reaches the storage path.
	 *
	 * @param string $tmp_path Temp file path to inspect.
	 */
	private static function is_file_content_safe( string $tmp_path ): bool {
		if ( '' === $tmp_path ) {
			return true;
		}

		$fs = AttachmentStorage::filesystem();

		// No filesystem handle (rare) → don't block the upload on a scan we
		// can't run; the mime + extension checks still apply.
		if ( ! $fs || ! $fs->is_readable( $tmp_path ) ) {
			return true;
		}

		// Read through WP_Filesystem and scan the first 8 KB. Uploads are
		// size-capped, so loading the temp file to inspect its head is cheap
		// and runs once per upload.
		$contents = $fs->get_contents( $tmp_path );

		if ( ! is_string( $contents ) || '' === $contents ) {
			return true;
		}

		$head = substr( $contents, 0, 8192 );

		$lower = strtolower( $head );

		foreach ( self::BANNED_TEXT_SIGNATURES as $needle ) {
			if ( str_contains( $lower, $needle ) ) {
				return false;
			}
		}

		foreach ( self::BANNED_BINARY_SIGNATURES as $needle ) {
			if ( str_contains( $head, $needle ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Best-effort mime detection from a file's bytes + name.
	 *
	 * @param string $tmp_path Temp file path.
	 * @param string $filename Original upload file name.
	 */
	private function detect_mime( string $tmp_path, string $filename ): string {
		// Same shape requirement as wp_handle_upload — see comment there.
		$check = wp_check_filetype_and_ext( $tmp_path, $filename, self::wp_handle_upload_mimes() );

		if ( ! empty( $check['type'] ) ) {
			return (string) $check['type'];
		}

		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );

			if ( $finfo ) {
				return (string) finfo_file( $finfo, $tmp_path );
			}
		}

		return '';
	}
}
