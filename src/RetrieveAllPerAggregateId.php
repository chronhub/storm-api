<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Generator;
use OpenApi\Attributes\Get;
use Illuminate\Http\Request;
use OpenApi\Attributes\Schema;
use OpenApi\Attributes\Response;
use OpenApi\Attributes\Parameter;
use OpenApi\Attributes\JsonContent;
use Chronhub\Storm\Stream\StreamName;
use Illuminate\Contracts\Validation\Factory;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Http\Api\Support\GenericAggregateId;
use Chronhub\Storm\Contracts\Serializer\StreamEventSerializer;
use function is_array;

#[
    Get(
        path: '/stream',
        operationId: 'retrieveAllPerAggregateId',
        description: 'Retrieve all stream events per stream name and aggregate id',
        tags: ['Stream'],
        parameters: [
            new Parameter(
                name: 'name',
                description: 'Stream name',
                in: 'query',
                required: true,
                schema: new Schema(type: 'string')
            ),
            new Parameter(
                name: 'id',
                description: 'Aggregate id',
                in: 'query',
                required: true,
                schema: new Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new Response(response: 200, description: 'ok', content: new JsonContent(ref: '#/components/schemas/StreamEvents', type: 'object')),
            new Response(ref: '#/components/responses/400', response: 400),
            new Response(ref: '#/components/responses/401', response: 401),
            new Response(ref: '#/components/responses/403', response: 403),
            new Response(ref: '#/components/responses/StreamNotFound', response: 404),
            new Response(ref: '#/components/responses/500', response: 500),
        ],
    ),
]
final readonly class RetrieveAllPerAggregateId
{
    public function __construct(private Chronicler $chronicler,
                                private StreamEventSerializer $eventSerializer,
                                private Factory $validation,
                                private ResponseFactory $response)
    {
    }

    public function __invoke(Request $request): ResponseFactory
    {
        $validator = $this->validation->make($request->all(), [
            'name' => 'required|string',
            'id' => 'required|string|uuid',
        ]);

        if ($validator->fails()) {
            return $this->response
                ->withErrors($validator->errors())
                ->withStatusCode(400);
        }

        $streamName = new StreamName($request->get('name'));

        $aggregateId = GenericAggregateId::fromString($request->get('id'));

        $streamEvents = $this->chronicler->retrieveAll($streamName, $aggregateId);

        return $this->response
            ->withData($this->convertStreamEvents($streamEvents))
            ->withStatusCode(200);
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
}
