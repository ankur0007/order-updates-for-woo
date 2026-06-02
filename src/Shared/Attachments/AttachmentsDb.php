<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Attachments;

use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Config\Variables;

// Direct queries on our own tables. Table names are safe; user input always uses prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.SlowDBQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

final class AttachmentsDb {
	/**
	 * Inject dependencies.
	 *
	 * @param AttachmentsTable $table Injected dependency.
	 */
	public function __construct( private AttachmentsTable $table ) {}

	public function create( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			$this->table->attachments,
			array(
				'order_id'      => (int) $data['order_id'],
				'update_id'     => (int) $data['update_id'],
				'note_id'       => (int) $data['note_id'],
				'note_type'     => (string) $data['note_type'],
				'file_name'     => (string) $data['file_name'],
				'original_name' => (string) $data['original_name'],
				'mime_type'     => (string) $data['mime_type'],
				'file_size'     => (int) $data['file_size'],
				'uploaded_by'   => (int) $data['uploaded_by'],
				'uploaded_at'   => (string) $data['uploaded_at'],
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		$id = (int) $wpdb->insert_id;

		if ( $id ) {
			$this->invalidate_note_cache( (int) $data['note_id'], (string) $data['note_type'] );
		}

		return $id;
	}

	public function get( int $attachment_id ): array {
		global $wpdb;

		if ( ! $attachment_id ) {
			return array();
		}

		$cache_key = "attachment_{$attachment_id}";
		$cached    = wp_cache_get( $cache_key, Constants::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table->attachments} WHERE id = %d LIMIT 1",
				$attachment_id
			),
			ARRAY_A
		);

		$record = is_array( $row ) ? $row : array();

		if ( ! empty( $record ) ) {
			wp_cache_set( $cache_key, $record, Constants::CACHE_GROUP, Variables::getUpdateCacheTtl() );
		}

		return $record;
	}

	public function get_for_note( int $note_id, string $note_type ): array {
		global $wpdb;

		if ( ! $note_id ) {
			return array();
		}

		$cache_key = "attachments_note_{$note_id}_{$note_type}";
		$cached    = wp_cache_get( $cache_key, Constants::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table->attachments}
				WHERE note_id = %d AND note_type = %s
				ORDER BY uploaded_at ASC, id ASC",
				$note_id,
				$note_type
			),
			ARRAY_A
		);

		$result = is_array( $rows ) ? $rows : array();
		wp_cache_set( $cache_key, $result, Constants::CACHE_GROUP, Variables::getUpdateCacheTtl() );

		return $result;
	}

	public function get_for_order( int $order_id ): array {
		global $wpdb;

		if ( ! $order_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table->attachments} WHERE order_id = %d",
				$order_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function delete( int $attachment_id ): bool {
		global $wpdb;

		if ( ! $attachment_id ) {
			return false;
		}

		$record = $this->get( $attachment_id );

		$result = false !== $wpdb->delete(
			$this->table->attachments,
			array( 'id' => $attachment_id ),
			array( '%d' )
		);

		if ( $result ) {
			wp_cache_delete( "attachment_{$attachment_id}", Constants::CACHE_GROUP );

			if ( ! empty( $record ) ) {
				$this->invalidate_note_cache(
					(int) ( $record['note_id'] ?? 0 ),
					(string) ( $record['note_type'] ?? '' )
				);
			}
		}

		return $result;
	}

	public function delete_for_order( int $order_id ): int {
		global $wpdb;

		if ( ! $order_id ) {
			return 0;
		}

		$rows = $this->get_for_order( $order_id );

		$deleted = (int) $wpdb->delete(
			$this->table->attachments,
			array( 'order_id' => $order_id ),
			array( '%d' )
		);

		if ( $deleted > 0 ) {
			foreach ( $rows as $row ) {
				wp_cache_delete( 'attachment_' . (int) ( $row['id'] ?? 0 ), Constants::CACHE_GROUP );
				$this->invalidate_note_cache(
					(int) ( $row['note_id'] ?? 0 ),
					(string) ( $row['note_type'] ?? '' )
				);
			}
		}

		return $deleted;
	}

	public function get_for_update( int $update_id ): array {
		global $wpdb;

		if ( ! $update_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table->attachments} WHERE update_id = %d",
				$update_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function delete_for_update( int $update_id ): int {
		global $wpdb;

		if ( ! $update_id ) {
			return 0;
		}

		$rows = $this->get_for_update( $update_id );

		$deleted = (int) $wpdb->delete(
			$this->table->attachments,
			array( 'update_id' => $update_id ),
			array( '%d' )
		);

		if ( $deleted > 0 ) {
			foreach ( $rows as $row ) {
				wp_cache_delete( 'attachment_' . (int) ( $row['id'] ?? 0 ), Constants::CACHE_GROUP );
				$this->invalidate_note_cache(
					(int) ( $row['note_id'] ?? 0 ),
					(string) ( $row['note_type'] ?? '' )
				);
			}
		}

		return $deleted;
	}

	private function invalidate_note_cache( int $note_id, string $note_type ): void {
		if ( $note_id && '' !== $note_type ) {
			wp_cache_delete( "attachments_note_{$note_id}_{$note_type}", Constants::CACHE_GROUP );
		}
	}
}
