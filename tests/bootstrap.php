<?php
/**
 * PHPUnit bootstrap — unit tests only.
 *
 * Brain\Monkey mocks WP functions at the test level; this file only loads the
 * autoloader and provides the WP_Error class stub that unit tests need.
 *
 * Integration tests that extend WP_UnitTestCase require a separate WP test
 * environment bootstrap (see the integration test README when those land).
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

// Production source files guard against direct access via `if (!defined('ABSPATH')) exit;`.
// Tests load those files via Composer autoload, not WordPress, so we define the
// constant here. Value is irrelevant for the guard (only defined-ness matters),
// but we point at a per-process temp dir so tests that do file operations
// relative to ABSPATH (e.g. AttachmentsTableTest stubbing wp-admin/includes/upgrade.php)
// don't pollute the plugin source tree and ship in dist builds.
if ( ! defined( 'ABSPATH' ) ) {
	$abspath_tmp = sys_get_temp_dir() . '/oufw-tests-' . getmypid() . '/';
	if ( ! is_dir( $abspath_tmp ) ) {
		mkdir( $abspath_tmp, 0777, true );
	}
	define( 'ABSPATH', $abspath_tmp );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Strip `final` from production classes so Mockery can mock them. The VIP rule
// keeps `final` in the source for inheritance hygiene; this only affects the
// test runtime, never shipped code. Called immediately after autoload so the
// stream filter intercepts every subsequent class include.
\DG\BypassFinals::enable();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = '',
			private mixed $data = ''
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message( string $code = '' ): string {
			return $this->message;
		}

		public function get_error_data( string $code = '' ): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * Minimal wpdb stub — tests use Mockery::mock( wpdb::class )->makePartial()
	 * to set per-test expectations and assign the result to $GLOBALS['wpdb'].
	 *
	 * Method bodies are no-ops; Mockery overrides anything the test cares about.
	 */
	class wpdb {
		public string $prefix    = 'wptests_';
		public int    $insert_id = 0;
		public string $users     = 'wptests_users';

		public function prepare( string $query, ...$args ): string { return $query; }
		public function insert( string $table, array $data, $format = null ): int|bool { return 1; }
		public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool { return 1; }
		public function delete( string $table, array $where, $where_format = null ): int|bool { return 1; }
		public function query( string $query ): int|bool { return 0; }
		public function get_results( string $query, $output = ARRAY_A ): array { return []; }
		public function get_row( string $query, $output = ARRAY_A, $y = 0 ): array|null { return null; }
		public function get_var( string $query, $x = 0, $y = 0 ): string|null { return null; }
		public function get_col( string $query, $x = 0 ): array { return []; }
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Minimal WC_Order stub. Tests use Mockery to set per-test expectations on
	 * the methods they need; everything else is a no-op so production code that
	 * type-hints WC_Order accepts the mock.
	 */
	class WC_Order {
		public function get_id(): int { return 0; }
		public function get_billing_first_name(): string { return ''; }
		public function get_billing_last_name(): string { return ''; }
		public function get_billing_email(): string { return ''; }
		public function get_customer_id(): int { return 0; }
		public function get_order_key(): string { return ''; }
		public function get_status(): string { return ''; }
		public function get_meta( string $key, bool $single = true, string $context = 'view' ): mixed { return ''; }
		public function update_meta_data( string $key, mixed $value, mixed $unique_or_id = '' ): void {}
		public function delete_meta_data( string $key ): void {}
		public function save(): int { return 0; }
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int    $ID           = 0;
		public string $display_name = '';
		public string $user_email   = '';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = array();
		public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
		public function set_param( string $key, mixed $value ): void { $this->params[ $key ] = $value; }
		public function get_header( string $key ): ?string { return null; }
	}
}

if ( ! class_exists( 'WC_Email' ) ) {
	/**
	 * Minimal WC_Email stub. Email-class unit tests instantiate the subclasses
	 * to verify their $id wiring without needing the full WooCommerce email
	 * machinery. Methods return safe defaults.
	 */
	class WC_Email {
		// Properties left untyped to match the real WC_Email shipping in WooCommerce —
		// our subclasses can declare typed overrides safely.
		public $id           = '';
		public $title        = '';
		public $description  = '';
		public $customer_email = false;
		public $subject       = '';
		public $heading       = '';
		public $template_html = '';
		public $template_base = '';
		public $email_type    = 'html';
		public $additional_content = '';
		public $form_fields   = array();
		public $object        = null;

		public function __construct() {}
		public function get_option( string $key, mixed $default = '' ): mixed { return $default; }
		public function init_form_fields(): void {}
		public function init_settings(): void {}
		public function get_recipient(): string { return ''; }
		public function send( string $to = '', string $subject = '', string $message = '', string $headers = '', array $attachments = array() ): bool { return true; }
		public function format_string( string $string ): string { return $string; }
	}
}
