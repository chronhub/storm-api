<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Generator;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use Chronhub\Storm\Message\Message;
use Chronhub\Storm\Stream\StreamName;
use Illuminate\Contracts\Validation\Factory;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Http\Api\Support\GenericAggregateId;
use Chronhub\Storm\Contracts\Serializer\MessageSerializer;

/**
 * @OA\Get(
 *     path="/api/storm/stream",
 *     tags={"Stream"},
 *     description="Get all stream events by stream name and aggregate id",
 *
 *     @OA\Parameter(
 *     name="name",
 *     in="query",
 *     description="Projection name",
 *     required=true,
 *
 *     @OA\Schema(type="string")
 *     ),
 *
 *     @OA\Parameter(
 *     name="id",
 *     in="query",
 *     description="Aggregate id",
 *     required=true,
 *
 *     @OA\Schema(type="string")
 *     ),
 *
 *     @OA\Response(
 *          response=200,
 *          description="ok",
 *     )
 * )
 */
final readonly class RetrieveAll
{
    public function __construct(private Chronicler $chronicler,
                                private MessageSerializer $messageSerializer,
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

        foreach ($streamEvents as $message) {
            $events[] = $this->messageSerializer->serializeMessage(new Message($message));
        }

        return $events;
    }
}
