<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\QueryFilter;

use Illuminate\Database\Query\Builder;
use Chronhub\Storm\Contracts\Chronicler\QueryFilter;

final readonly class AllPaginatedStream
{
    private function queryFilter(int $limit,
                                 int $offset,
                                 string $direction): QueryFilter
    {
        return new class($limit, $offset, $direction) implements QueryFilter
        {
            public function __construct(private readonly int $limit,
                                        private readonly int $offset,
                                        private readonly string $direction)
            {
            }

            public function apply(): callable
            {
                return function (Builder $query): void {
                    $query->orderBy('no', $this->direction);

                    $query->limit($this->limit);

                    if (0 !== $this->offset) {
                        $query->offset($this->offset);
                    }
                };
            }
        };
    }
}
