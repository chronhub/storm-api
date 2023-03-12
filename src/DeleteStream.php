<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Illuminate\Http\Request;
use OpenApi\Attributes\Delete;
use OpenApi\Attributes\Schema;
use OpenApi\Attributes\Response;
use OpenApi\Attributes\Parameter;
use OpenApi\Attributes\JsonContent;
use Chronhub\Storm\Stream\StreamName;
use Illuminate\Contracts\Validation\Factory;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;

#[
    Delete(
        path: '/stream',
        operationId: 'deleteStream',
        description: 'Delete stream by stream name',
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
final readonly class DeleteStream
{
    public function __construct(private Chronicler $chronicler,
                                private Factory $validation,
                                private ResponseFactory $response)
    {
    }

    public function __invoke(Request $request): ResponseFactory
    {
        $validator = $this->validation->make($request->all(), [
            'name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->response
                ->withErrors($validator->errors())
                ->withStatusCode(400);
        }

        $streamName = new StreamName($request->get('name'));

        $this->chronicler->delete($streamName);

        return $this->response->withStatusCode(204);
    }
}
