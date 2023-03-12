<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Throwable;
use OpenApi\Attributes\Put;
use Illuminate\Http\Request;
use OpenApi\Attributes\Schema;
use OpenApi\Attributes\Response;
use Chronhub\Storm\Stream\Stream;
use OpenApi\Attributes\Parameter;
use Illuminate\Support\MessageBag;
use OpenApi\Attributes\JsonContent;
use Chronhub\Storm\Stream\StreamName;
use Illuminate\Contracts\Validation\Factory;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Contracts\Message\MessageFactory;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Contracts\Chronicler\TransactionalChronicler;

#[
    Put(
        path: '/stream',
        operationId: 'postStream',
        description: 'Post stream events for one stream',
        tags: ['Stream'],
        parameters: [
            new Parameter(
                name: 'name',
                description: 'Stream name',
                in: 'query',
                required: true,
                schema: new Schema(type: 'string'),
            ),
        ],
        responses: [
            new Response(response: 204, description: 'ok'),
            new Response(ref: '#/components/responses/400', response: 400),
            new Response(ref: '#/components/responses/401', response: 401),
            new Response(ref: '#/components/responses/403', response: 403),
            new Response(ref: '#/components/responses/500', response: 500),
            new Response(response: 404, description: 'Stream not found', content: new JsonContent(ref: '#/components/schemas/Error')),
        ],
    ),
]
final readonly class PostStream
{
    public function __construct(private Chronicler $chronicler,
                                private MessageFactory $messageFactory, // need to be injected with stream event serializer
                                private Factory $validation,
                                private ResponseFactory $response)
    {
    }

    public function __invoke(Request $request): ResponseFactory
    {
        $validateStream = $this->validation->make($request->all(), [
            'name' => 'required|string',
        ]);

        if ($validateStream->failed()) {
            return $this->response
                ->withErrors($validateStream->errors())
                ->withStatusCode(400);
        }

        $payload = $request->json()->all();

        $validatePayload = $this->validation->make($payload, [
            'headers' => 'array',
            'content' => 'array',
        ]);

        if ($validatePayload->failed()) {
            return $this->response
                ->withErrors($validateStream->errors())
                ->withStatusCode(400);
        }

        $streamName = new StreamName($request->get('name'));

        $streamEvents = $this->getEvents($payload);

        if (empty($streamEvents)) {
            return $this->response
                ->withErrors(new MessageBag(['payload' => 'Payload must contain at least one event']))
                ->withStatusCode(400);
        }

        $this->persistStream(new Stream($streamName, $streamEvents));

        return $this->response->withStatusCode(204);
    }

    private function getEvents(array $payload): array
    {
        $events = [];

        foreach ($payload as $DomainEvent) {
            $events[] = ($this->messageFactory)($DomainEvent);
        }

        return $events;
    }

    private function persistStream(Stream $stream): void
    {
        if ($this->chronicler instanceof TransactionalChronicler) {
            $this->chronicler->beginTransaction();
        }

        try {
            $this->chronicler->hasStream($stream->name())
                ? $this->chronicler->firstCommit($stream)
                : $this->chronicler->amend($stream);
        } catch (Throwable $exception) {
            if ($this->chronicler instanceof TransactionalChronicler) {
                $this->chronicler->rollbackTransaction();
            }

            throw $exception;
        }

        if ($this->chronicler instanceof TransactionalChronicler) {
            $this->chronicler->commitTransaction();
        }
    }
}
