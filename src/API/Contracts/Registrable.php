<?php
/**
 * Contract for classes that register WordPress hooks or REST routes.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Contracts;

/**
 * Implemented by endpoints and services that wire themselves into WordPress.
 */
interface Registrable {

	/** Register hooks / REST routes with WordPress. */
	public function register(): void;
}
