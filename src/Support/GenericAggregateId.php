<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Support;

use Chronhub\Storm\Aggregate\HasAggregateIdentity;
use Chronhub\Storm\Contracts\Aggregate\AggregateIdentity;

final readonly class GenericAggregateId implements AggregateIdentity
{
    use HasAggregateIdentity;
}
