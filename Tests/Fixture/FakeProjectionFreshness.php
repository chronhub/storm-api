<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture;

use Storm\Contracts\Chronicler\Position;
use Storm\Contracts\Projector\ProjectionFreshness;

/**
 * A hand-rolled port double: answers what the test scripted and records what it was asked;
 * catch-up is a scripted countdown so a wait loop can be observed converging.
 */
final class FakeProjectionFreshness implements ProjectionFreshness
{
    /** @var list<array{string, float, int}> */
    public array $headWaits = [];

    public function __construct(
        private int $pollsUntilAtHead = 0,
    ) {}

    public function isCaughtUp(string $name, Position $token): bool
    {
        return $this->pollsUntilAtHead <= 0;
    }

    public function waitFor(string $name, Position $token, float $timeoutSeconds = 5.0, int $pollMs = 50): bool
    {
        return $this->pollsUntilAtHead <= 0;
    }

    public function isAtHead(string $name): bool
    {
        return $this->pollsUntilAtHead-- <= 0;
    }

    public function waitForHead(string $name, float $timeoutSeconds = 5.0, int $pollMs = 50): bool
    {
        $this->headWaits[] = [$name, $timeoutSeconds, $pollMs];

        return $this->pollsUntilAtHead-- <= 0;
    }

    public function lag(string $name): int
    {
        return max(0, $this->pollsUntilAtHead);
    }
}
