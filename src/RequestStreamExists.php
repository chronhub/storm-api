<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

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

#[
    Get(
        path: '/api/storm/stream/exists',
        description: 'Check if stream exists',
        tags: ['Stream'],
        parameters: [
            new Parameter(
                name: 'name',
                description: 'Stream name',
                in: 'query',
                required: true,
                schema: new Schema(type: 'string')
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
final readonly class RequestStreamExists
{
    public function __construct(protected Chronicler $chronicler,
                                protected Factory $validation,
                                protected ResponseFactory $response)
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

        $hasStream = $this->chronicler->hasStream($streamName);

        $result = [$streamName->toString() => $hasStream];

        return $this->response
            ->withStatusCode($hasStream ? 200 : 404)
            ->withData($result);
    }
}
