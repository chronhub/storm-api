<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Support;

use stdClass;
use Generator;
use Chronhub\Storm\Stream\StreamName;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Chronhub\Storm\Chronicler\Exceptions\StreamNotFound;
use Chronhub\Larastorm\Exceptions\ConnectionQueryFailure;
use Chronhub\Storm\Contracts\Serializer\StreamEventSerializer;
use Chronhub\Larastorm\Support\Contracts\StreamEventLoaderConnection;

final readonly class ArrayEventLoader implements StreamEventLoaderConnection
{
    public function __construct(private StreamEventSerializer $eventSerializer)
    {
    }

    public function query(Builder $builder, StreamName $streamName): Generator
    {
        $streamEvents = $this->generateStreamEvents($builder->cursor(), $streamName);

        yield from $streamEvents;

        return $streamEvents->getReturn();
    }

    private function generateStreamEvents(iterable $streamEvents, StreamName $streamName): Generator
    {
        try {
            $count = 0;

            foreach ($streamEvents as $streamEvent) {
                if ($streamEvent instanceof stdClass) {
                    $streamEvent = (array) $streamEvent;
                }

                yield $this->eventSerializer->normalizeContent($streamEvent);

                $count++;
            }

            if (0 === $count) {
                throw StreamNotFound::withStreamName($streamName);
            }

            return $count;
        } catch (QueryException $queryException) {
            if ('00000' !== $queryException->getCode()) {
                throw StreamNotFound::withStreamName($streamName);
            }

            throw ConnectionQueryFailure::fromQueryException($queryException);
        }
    }
}
