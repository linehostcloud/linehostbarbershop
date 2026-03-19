<?php

namespace App\Application\DTOs;

use Carbon\CarbonImmutable;

readonly class OperationalWindow
{
    public function __construct(
        public string $label,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $endedAt,
    ) {
    }
}
