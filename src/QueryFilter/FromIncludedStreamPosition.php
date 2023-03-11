<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\QueryFilter;

use Illuminate\Database\Query\Builder;
use Chronhub\Storm\Contracts\Chronicler\QueryFilter;

final readonly class FromIncludedStreamPosition
{
    public function filter(int $position): QueryFilter
    {
        return new class($position) implements QueryFilter
        {
            public function __construct(private readonly int $position)
            {
            }

            public function apply(): callable
            {
                return function (Builder $query): void {
                    $query
                        ->where('no', '>=', $this->position)
                        ->orderBy('no');
                };
            }
        };
    }
}
