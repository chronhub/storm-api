<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture;

/** The write under test: what a Command-as-DTO looks like from the bridge. */
final readonly class ShelveBook
{
    public function __construct(
        public string $id,
    ) {}
}
