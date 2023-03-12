<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Throwable;
use Illuminate\Http\Request;
use OpenApi\Attributes\Post;
use OpenApi\Attributes\Schema;
use OpenApi\Attributes\Response;
use Chronhub\Storm\Stream\Stream;
use OpenApi\Attributes\Parameter;
use OpenApi\Attributes\JsonContent;
use Chronhub\Storm\Stream\StreamName;
use Illuminate\Contracts\Validation\Factory;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Contracts\Chronicler\TransactionalChronicler;

#[
    Post(
        path: '/api/storm/stream',
        description: 'Create a new stream',
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
            new Response(response: 419, description: 'Stream already exists', content: new JsonContent(ref: '#/components/schemas/Error')),
        ],
    ),
]
final readonly class CreateStream
{
    public function __construct(private Chronicler $chronicler,
                                private Factory $validation,
                                private ResponseFactory $response)
    {
    }

    public function __invoke(Request $request): ResponseFactory
    {
        $validateStream = $this->validation->make($request->all(), [
            'name' => 'required|string',
        ]);

        if ($validateStream->fails()) {
            return $this->response
                ->withErrors($validateStream->errors())
                ->withStatusCode(400);
        }

        $streamName = new StreamName($request->get('name'));

        $this->persistStream(new Stream($streamName));

        return $this->response->withStatusCode(204);
    }

    private function persistStream(Stream $stream): void
    {
        if ($this->chronicler instanceof TransactionalChronicler) {
            $this->chronicler->beginTransaction();
        }

        try {
            $this->chronicler->firstCommit($stream);
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
