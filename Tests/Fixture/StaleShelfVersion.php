<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture;

use RuntimeException;
use Storm\Contracts\Chronicler\ConcurrencyException;

/**
 * What a lost version race looks like from the bridge: any exception carrying the contracted
 * marker; the message deliberately spells out versions, so the tests can prove they never
 * reach the public problem shape.
 */
final class StaleShelfVersion extends RuntimeException implements ConcurrencyException {}
