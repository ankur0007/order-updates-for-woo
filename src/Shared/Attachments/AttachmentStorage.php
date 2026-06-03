<?php
/**
 * Filesystem layout + safe file/dir operations for note attachments.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Attachments;

use OrderUpdatesForWoo\Shared\Config\Constants;

/**
 * Resolves attachment directory paths and handles protected create/delete of
 * the per-order / per-update / per-note folder tree under wp-uploads.
 */
final class AttachmentStorage {
	/** Absolute path to the attachments root directory. */
	public static function attachments_dir(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . Constants::ATTACHMENTS_ROOT_DIR;
	}

	/** Public URL of the attachments root directory. */
	public static function root_url(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( (string) ( $uploads['baseurl'] ?? '' ) ) . Constants::ATTACHMENTS_ROOT_DIR;
	}

	/**
	 * Absolute path to an order's attachment directory.
	 *
	 * @param int $order_id Order id.
	 */
	public static function order_dir( int $order_id ): string {
		return self::attachments_dir() . '/orders/' . $order_id;
	}

	/**
	 * Absolute path to an update's attachment directory.
	 *
	 * @param int $order_id  Order id.
	 * @param int $update_id Update id.
	 */
	public static function update_dir( int $order_id, int $update_id ): string {
		return self::order_dir( $order_id ) . '/' . $update_id;
	}

	/**
	 * Absolute path to a note's attachment directory.
	 *
	 * @param int $order_id  Order id.
	 * @param int $update_id Update id.
	 * @param int $note_id   Note id.
	 */
	public static function note_dir( int $order_id, int $update_id, int $note_id ): string {
		return self::update_dir( $order_id, $update_id ) . '/' . $note_id;
	}

	/**
	 * Ensures the root directory exists and is protected from direct web access.
	 * Writes .htaccess (Apache) and index.html (directory listing fallback) to root.
	 */
	public static function ensure_root_protected(): void {
		$root = self::attachments_dir();

		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}

		$fs = self::filesystem();

		if ( $fs && ! $fs->exists( $root . '/.htaccess' ) ) {
			$fs->put_contents( $root . '/.htaccess', "deny from all\n", FS_CHMOD_FILE );
		}

		self::write_index_html( $root );
	}

	/**
	 * Create the full directory chain for a note, protect every level, and
	 * return the note directory's absolute path.
	 *
	 * Intermediate directories (orders/, orders/{id}/, orders/{id}/{update_id}/)
	 * each get an index.html so directory browsing is blocked at every level.
	 *
	 * @param int $order_id  Order id.
	 * @param int $update_id Update id.
	 * @param int $note_id   Note id.
	 */
	public static function ensure_note_dir( int $order_id, int $update_id, int $note_id ): string {
		self::ensure_root_protected();

		$dirs = array(
			self::attachments_dir() . '/orders',
			self::order_dir( $order_id ),
			self::update_dir( $order_id, $update_id ),
			self::note_dir( $order_id, $update_id, $note_id ),
		);

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			self::write_index_html( $dir );
		}

		return end( $dirs );
	}

	/**
	 * Delete one file, but only if it sits inside the attachments root.
	 *
	 * @param string $absolute_path Absolute file path.
	 */
	public static function delete_file( string $absolute_path ): bool {
		if ( ! self::is_inside_attachments_dir( $absolute_path ) ) {
			return false;
		}

		$fs = self::filesystem();

		if ( $fs ) {
			return $fs->exists( $absolute_path ) ? $fs->delete( $absolute_path ) : true;
		}

		if ( ! file_exists( $absolute_path ) ) {
			return true;
		}

		wp_delete_file( $absolute_path );
		return ! file_exists( $absolute_path );
	}

	/**
	 * Delete an order's whole attachment directory.
	 *
	 * @param int $order_id Order id.
	 */
	public static function delete_order_dir( int $order_id ): bool {
		if ( ! $order_id ) {
			return false;
		}

		return self::delete_dir( self::order_dir( $order_id ) );
	}

	/**
	 * Delete an update's whole attachment directory.
	 *
	 * @param int $order_id  Order id.
	 * @param int $update_id Update id.
	 */
	public static function delete_update_dir( int $order_id, int $update_id ): bool {
		if ( ! $order_id || ! $update_id ) {
			return false;
		}

		return self::delete_dir( self::update_dir( $order_id, $update_id ) );
	}

	/**
	 * Walk up the directory tree from $start_dir, removing any directory
	 * whose only remaining entry is the `index.html` listing-protection
	 * file. Stops at `orders/` — that root stays in place even when empty.
	 *
	 * Called after a file/dir delete so we don't leave hollow per-order or
	 * per-update folders cluttering the uploads directory once their
	 * attachments are gone.
	 *
	 * @param string $start_dir Directory to start walking up from.
	 */
	public static function prune_empty_ancestor_dirs( string $start_dir ): void {
		$orders_root_real = realpath( self::attachments_dir() . '/orders' );

		if ( ! $orders_root_real ) {
			return;
		}

		$fs = self::filesystem();

		if ( ! $fs ) {
			return;
		}

		$current = $start_dir;

		while ( true ) {
			$current_real = realpath( $current );

			if ( ! $current_real || $current_real === $orders_root_real ) {
				return;
			}

			if ( ! self::is_inside_attachments_dir( $current_real ) ) {
				return;
			}

			$entries = $fs->dirlist( $current_real );

			if ( ! is_array( $entries ) ) {
				return;
			}

			// `index.html` is just our listing-protection stub — a directory
			// holding only that counts as empty and is safe to prune.
			$meaningful = array_filter(
				array_keys( $entries ),
				static fn( string $name ) => 'index.html' !== $name
			);

			if ( ! empty( $meaningful ) ) {
				return;
			}

			if ( ! self::delete_dir( $current_real ) ) {
				return;
			}

			$current = dirname( $current_real );
		}
	}

	/**
	 * True when $path is inside the attachments folder. Sanity check
	 * before file ops; not a substitute for input validation at the
	 * boundary (callers must already control the path).
	 *
	 * @param string $path Absolute path to check.
	 */
	public static function is_inside_attachments_dir( string $path ): bool {
		$root_dir = realpath( self::attachments_dir() );

		if ( ! $root_dir ) {
			return false;
		}

		// For non-existent paths (new uploads), resolve the parent directory
		// so `..` segments can't slip through unresolved. If even the parent
		// doesn't exist, reject — there's nothing safe to verify against.
		$resolved = realpath( $path );
		if ( ! $resolved ) {
			$parent = realpath( dirname( $path ) );
			if ( ! $parent ) {
				return false;
			}
			$resolved = $parent . DIRECTORY_SEPARATOR . basename( $path );
		}

		return str_starts_with(
			wp_normalize_path( $resolved ),
			wp_normalize_path( trailingslashit( $root_dir ) )
		);
	}

	/**
	 * Returns the initialised WP_Filesystem instance, or null on failure.
	 */
	public static function filesystem(): ?\WP_Filesystem_Base {
		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			WP_Filesystem();
		}

		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Write an empty index.html into $dir if it does not already exist.
	 * Mirrors WooCommerce's approach: an empty HTML file blocks directory
	 * listing on servers where .htaccess is not honoured (e.g. Nginx).
	 *
	 * @param string $dir Directory to write into.
	 */
	private static function write_index_html( string $dir ): void {
		$path = trailingslashit( $dir ) . 'index.html';
		$fs   = self::filesystem();

		if ( $fs && ! $fs->exists( $path ) ) {
			$fs->put_contents( $path, '', FS_CHMOD_FILE );
		}
	}

	/**
	 * Delete a directory and all its contents, with root-boundary safety check.
	 * Uses WP_Filesystem::delete(), which handles recursion natively. When the
	 * filesystem can't initialise (rare — needs FTP/SSH credentials) we skip
	 * rather than drop to raw PHP filesystem calls.
	 *
	 * @param string $dir Directory to delete.
	 */
	private static function delete_dir( string $dir ): bool {
		if ( ! is_dir( $dir ) || ! self::is_inside_attachments_dir( $dir ) ) {
			return false;
		}

		$fs = self::filesystem();

		return $fs ? $fs->delete( $dir, true ) : false;
	}
}
