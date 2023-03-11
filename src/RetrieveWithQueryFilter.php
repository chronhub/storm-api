<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Generator;
use Illuminate\Http\Request;
use Chronhub\Storm\Stream\StreamName;
use Illuminate\Contracts\Validation\Validator;
use Chronhub\Storm\Contracts\Chronicler\QueryFilter;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use function is_array;

abstract readonly class RetrieveWithQueryFilter
{
    public function __invoke(Request $request): ResponseFactory
    {
        $validator = $this->makeValidator($request);

        if ($validator->fails()) {
            return $this->response
                ->withErrors($validator->errors())
                ->withStatusCode(400);
        }

        $streamName = new StreamName($request->get('name'));

        $streamEvents = $this->chronicler->retrieveFiltered(
            $streamName,
            $this->makeQueryFilter($request)
        );

        return $this->response
            ->withStatusCode(200)
            ->withData($this->convertStreamEvents($streamEvents));
    }

    private function convertStreamEvents(Generator $streamEvents): array
    {
        $events = [];

        foreach ($streamEvents as $streamEvent) {
            if (! is_array($streamEvent)) {
                $streamEvent = $this->eventSerializer->serializeEvent($streamEvent);
            }

            $events[] = $streamEvent;
        }

        return $events;
    }

    abstract protected function makeValidator(Request $request): Validator;

    abstract protected function makeQueryFilter(Request $request): QueryFilter;
}
