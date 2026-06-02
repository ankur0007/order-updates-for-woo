<?php
/**
 * Registers every REST endpoint on `rest_api_init`.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\API\Contracts\Registrable;

/**
 * Collects the endpoint objects and registers their routes when WordPress
 * boots the REST API.
 */
final class RestApiRegistrar {
	/** @var Registrable[] */
	private array $endpoints;

	/**
	 * @param Registrable ...$endpoints Endpoint objects to register.
	 */
	public function __construct( Registrable ...$endpoints ) {
		$this->endpoints = $endpoints;
	}

	/** Hook route registration onto rest_api_init. */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/** Register every endpoint's routes. */
	public function register_routes(): void {
		foreach ( $this->endpoints as $endpoint ) {
			$endpoint->register();
		}
	}
}
