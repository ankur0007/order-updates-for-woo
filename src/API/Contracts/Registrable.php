<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Contracts;

interface Registrable {
	public function register(): void;
}
