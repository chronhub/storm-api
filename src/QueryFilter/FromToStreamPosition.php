<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\QueryFilter;

use Illuminate\Database\Query\Builder;
use Chronhub\Storm\Contracts\Chronicler\QueryFilter;

final readonly class FromToStreamPosition
{
    public function __invoke(int $from, int $to, string $direction): QueryFilter
    {
        return new class($from, $to, $direction) implements QueryFilter
        {
            public function __construct(private readonly int $from,
                                        private readonly int $to,
                                        private readonly string $direction)
            {
            }

            public function apply(): callable
            {
                return function (Builder $query): void {
                    $query->whereBetween('no', [$this->from, $this->to]);
                    $query->orderBy('no', $this->direction);
                };
            }
        };
    }
}
