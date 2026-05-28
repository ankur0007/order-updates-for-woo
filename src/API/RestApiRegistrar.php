<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\API\Contracts\Registrable;

final class RestApiRegistrar {
	/** @var Registrable[] */
	private array $endpoints;

	public function __construct( Registrable ...$endpoints ) {
		$this->endpoints = $endpoints;
	}

	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		foreach ( $this->endpoints as $endpoint ) {
			$endpoint->register();
		}
	}
}
