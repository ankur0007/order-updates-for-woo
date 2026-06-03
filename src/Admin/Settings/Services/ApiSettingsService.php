<?php
/**
 * API Endpoints — read-only directory of REST routes for developers
 * connecting external tools.
 *
 * Pulls the live route map from `rest_get_server()`, filters to the
 * plugin's namespace, extracts path parameters from each route's regex
 * and body parameters from any `args` map declared on the route. The
 * `order_updates_for_woo_api_endpoint_params` filter lets endpoints (or
 * addons) document body params they didn't pass to register_rest_route().
 *
 * Each endpoint also produces a copy-paste curl template — replace the
 * URL placeholders, fill in basic-auth creds, paste into Postman or any
 * HTTP client and it fires on the first attempt.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Services;

use OrderUpdatesForWoo\Shared\Config\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings fields and values for the api section.
 */
final class ApiSettingsService {
	public const SECTION_ID = 'api';

	/**
	 * Human-readable section label for the nav.
	 */
	public function label(): string {
		return __( 'API Endpoints', 'order-updates-for-woo' );
	}

	/**
	 * No persisted fields — this section is a read-only API directory.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array();
	}

	/** The plugin's REST namespace. */
	public function namespace(): string {
		return Constants::REST_NAMESPACE;
	}

	/** Base URL for the plugin's REST namespace. */
	public function base_url(): string {
		return rest_url( Constants::REST_NAMESPACE );
	}

	/**
	 * Return the registered REST routes that belong to this plugin, shaped
	 * for the Views layer.
	 *
	 * @return array<int, array{
	 *     path:string,
	 *     methods:string,
	 *     method_list:string[],
	 *     url:string,
	 *     params:array<int, array{name:string, source:string, type:string, required:bool, description:string}>,
	 *     curl:string
	 * }>
	 */
	public function endpoints(): array {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return array();
		}

		$routes  = rest_get_server()->get_routes();
		$prefix  = '/' . Constants::REST_NAMESPACE;
		$listing = array();

		foreach ( $routes as $path => $handlers ) {
			if ( ! str_starts_with( (string) $path, $prefix ) ) {
				continue;
			}

			$methods   = array();
			$body_args = array();

			foreach ( $handlers as $handler ) {
				if ( ! empty( $handler['methods'] ) && is_array( $handler['methods'] ) ) {
					foreach ( array_keys( $handler['methods'] ) as $method ) {
						$methods[ strtoupper( (string) $method ) ] = true;
					}
				}

				if ( ! empty( $handler['args'] ) && is_array( $handler['args'] ) ) {
					$body_args = array_merge( $body_args, $handler['args'] );
				}
			}

			if ( empty( $methods ) ) {
				continue;
			}

			$method_list  = array_keys( $methods );
			$raw_path     = (string) $path;
			$readable     = $this->display_path( $raw_path );
			$readable_url = $this->display_path( esc_url_raw( rest_url( ltrim( $raw_path, '/' ) ) ) );
			$params       = $this->params_for( $raw_path, $method_list, $body_args );
			$summary      = $this->summary_for( $raw_path, $method_list );

			$listing[] = array(
				'path'        => $readable,
				'methods'     => implode( ', ', $method_list ),
				'method_list' => $method_list,
				'url'         => $readable_url,
				'summary'     => $summary,
				'params'      => $params,
				'curl'        => $this->curl_template_for( $readable_url, $method_list, $params ),
			);
		}

		usort( $listing, static fn( array $a, array $b ): int => strcmp( $a['path'], $b['path'] ) );

		return $listing;
	}

	/**
	 * Build a copy-paste curl command for one endpoint. The result is
	 * meant to drop straight into Postman's "Import → Raw text" or any
	 * shell. Path params are substituted with `<NAME>` placeholders so
	 * the developer can see at a glance what to fill in; body params with
	 * defaults derived from their declared type so the JSON is parseable
	 * as-is.
	 *
	 * @param string                                                                                        $url     Endpoint URL.
	 * @param string[]                                                                                      $methods HTTP methods the route accepts.
	 * @param array<int, array{name:string, source:string, type:string, required:bool, description:string}> $params  Param definitions.
	 */
	public function curl_template_for( string $url, array $methods, array $params ): string {
		$method   = $this->primary_method( $methods );
		$has_body = ! in_array( $method, array( 'GET', 'DELETE' ), true );

		$lines = array(
			sprintf( 'curl -X %s "%s" \\', $method, $url ),
			'  -u "USERNAME:APP_PASSWORD" \\',
		);

		if ( $has_body ) {
			$lines[] = '  -H "Content-Type: application/json" \\';
			$body    = $this->body_template( $params );

			if ( '' !== $body ) {
				// Use HEREDOC-friendly single-quoted body so JSON inside is
				// safe to paste; replace the trailing line-continuation on
				// the previous line by concatenating in place.
				$lines[ count( $lines ) - 1 ] = rtrim( end( $lines ), '\\ ' ) . ' \\';
				$lines[]                      = "  -d '" . $body . "'";
			} else {
				$lines[ count( $lines ) - 1 ] = rtrim( end( $lines ), '\\ ' );
			}
		} else {
			$lines[ count( $lines ) - 1 ] = rtrim( end( $lines ), '\\ ' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build the parameter list for a route. Auto-extracts path parameters
	 * from the regex; merges in any documented body / query parameters.
	 *
	 * @param string                                                                  $path      Route path.
	 * @param string[]                                                                $methods   HTTP methods the route accepts.
	 * @param array<string, array{type?:string, required?:bool, description?:string}> $body_args Declared body/query args.
	 * @return array<int, array{name:string, source:string, type:string, required:bool, description:string}>
	 */
	private function params_for( string $path, array $methods, array $body_args ): array {
		$params = $this->path_params( $path );

		foreach ( $body_args as $name => $spec ) {
			$params[] = array(
				'name'        => (string) $name,
				'source'      => $this->source_label_for( $methods ),
				'type'        => (string) ( $spec['type'] ?? 'string' ),
				'required'    => (bool) ( $spec['required'] ?? false ),
				'description' => (string) ( $spec['description'] ?? '' ),
			);
		}

		/**
		 * Filter the parameter list shown for an endpoint in the API
		 * directory. Use this to document body / query / header parameters
		 * for routes that don't declare them via register_rest_route()'s
		 * `args` map.
		 *
		 * @param array<int, array{name:string, source:string, type:string, required:bool, description:string}> $params
		 * @param string                                                                                          $path
		 * @param string[]                                                                                        $methods
		 */
		return (array) apply_filters( 'order_updates_for_woo_api_endpoint_params', $params, $path, $methods );
	}

	/**
	 * Resolve a one-line description for an endpoint. Empty by default —
	 * core endpoints document themselves through
	 * `order_updates_for_woo_api_endpoint_summary`, addons can hook the
	 * same filter for their own routes.
	 *
	 * @param string   $path    Route path.
	 * @param string[] $methods HTTP methods the route accepts.
	 */
	private function summary_for( string $path, array $methods ): string {
		/**
		 * Filter the summary line shown above an endpoint's params table.
		 * Return a short sentence (under ~140 chars) describing what the
		 * endpoint does.
		 *
		 * @param string   $summary
		 * @param string   $path
		 * @param string[] $methods
		 */
		return (string) apply_filters( 'order_updates_for_woo_api_endpoint_summary', '', $path, $methods );
	}

	/**
	 * Pull `(?P<name>regex)` named groups out of the route path.
	 *
	 * @param string $path Route path.
	 * @return array<int, array{name:string, source:string, type:string, required:bool, description:string}>
	 */
	private function path_params( string $path ): array {
		if ( ! preg_match_all( '#\\(\\?P<([a-zA-Z_][a-zA-Z0-9_]*)>([^)]+)\\)#', $path, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$params = array();

		foreach ( $matches as $match ) {
			$params[] = array(
				'name'        => (string) $match[1],
				'source'      => __( 'URL', 'order-updates-for-woo' ),
				'type'        => str_contains( (string) $match[2], 'd+' ) ? 'integer' : 'string',
				'required'    => true,
				'description' => '',
			);
		}

		return $params;
	}

	/**
	 * "Query" for read-only routes, "Body" otherwise.
	 *
	 * @param string[] $methods HTTP methods the route accepts.
	 */
	private function source_label_for( array $methods ): string {
		$query_only = array_diff( $methods, array( 'GET', 'DELETE' ) ) === array();

		return $query_only
			? __( 'Query', 'order-updates-for-woo' )
			: __( 'Body', 'order-updates-for-woo' );
	}

	/**
	 * Convert a route's regex segments (`(?P<update_id>\d+)`) into the
	 * standard `{update_id}` placeholder form. Used for both the
	 * displayed path and the URL inside the curl template — Postman
	 * recognises `{var}` as a path variable, so the same string powers
	 * both the human label and a paste-ready URL.
	 *
	 * @param string $url Raw route path or URL.
	 */
	private function display_path( string $url ): string {
		$decoded = urldecode( $url );

		return (string) preg_replace(
			'#\\(\\?P<([a-zA-Z_][a-zA-Z0-9_]*)>[^)]+\\)#',
			'{$1}',
			$decoded
		);
	}

	/**
	 * Build a sample JSON body from a route's body/query params.
	 *
	 * @param array<int, array{name:string, source:string, type:string, required:bool, description:string}> $params Param definitions.
	 */
	private function body_template( array $params ): string {
		$body        = array();
		$body_label  = __( 'Body', 'order-updates-for-woo' );
		$query_label = __( 'Query', 'order-updates-for-woo' );

		foreach ( $params as $param ) {
			$source = (string) ( $param['source'] ?? '' );

			if ( $body_label !== $source && $query_label !== $source ) {
				continue;
			}

			$body[ (string) $param['name'] ] = $this->default_for_type( (string) ( $param['type'] ?? 'string' ) );
		}

		if ( empty( $body ) ) {
			return '';
		}

		return wp_json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * A placeholder default value for a declared param type.
	 *
	 * @param string $type Declared param type.
	 * @return mixed
	 */
	private function default_for_type( string $type ) {
		return match ( strtolower( $type ) ) {
			'integer', 'int', 'number' => 0,
			'boolean', 'bool'          => false,
			'array'                    => array(),
			'object'                   => new \stdClass(),
			default                    => '',
		};
	}

	/**
	 * For a route that supports multiple methods, pick the one that's
	 * most informative for a curl example. POST > PUT > PATCH > DELETE > GET.
	 *
	 * @param string[] $methods HTTP methods the route accepts.
	 */
	private function primary_method( array $methods ): string {
		foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE', 'GET' ) as $candidate ) {
			if ( in_array( $candidate, $methods, true ) ) {
				return $candidate;
			}
		}

		return $methods[0] ?? 'GET';
	}
}
