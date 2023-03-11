<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Throwable;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use Chronhub\Storm\Stream\Stream;
use Chronhub\Storm\Stream\StreamName;
use Illuminate\Contracts\Validation\Factory;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Contracts\Message\MessageFactory;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Contracts\Chronicler\TransactionalChronicler;

/**
 * @OA\Post(
 *     path="/api/storm/stream",
 *     tags={"Stream"},
 *     description="Post stream",
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
 *     @OA\Response(
 *          response=204,
 *          description="ok",
 *     )
 * )
 */
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
            'payload' => 'array',
        ]);

        if ($validatePayload->failed()) {
            return $this->response
                ->withErrors($validateStream->errors())
                ->withStatusCode(400);
        }

        $streamName = new StreamName($request->get('name'));

        $this->persistStream(
            $this->produceStream($streamName, $payload)
        );

        return $this->response->withStatusCode(204);
    }

    private function produceStream(StreamName $streamName, array $payload): Stream
    {
        $events = [];

        foreach ($payload as $DomainEvent) {
            $events[] = ($this->messageFactory)($DomainEvent);
        }

//        if (empty($messages)) {
//            throw Exception("Messages can not be empty");
//        }

        return new Stream($streamName, $events);
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
