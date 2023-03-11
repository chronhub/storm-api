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
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Contracts\Projector\ProjectorManager;

#[
    Get(
        path: '/api/storm/projection/reset',
        description: 'Reset projection by name',
        tags: ['Projection'],
        parameters: [
            new Parameter(
                name: 'name',
                description: 'Projection name',
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
            new Response(response: 404, description: 'Projection not found', content: new JsonContent(ref: '#/components/schemas/Error')),
        ],
    ),
]
final readonly class ResetProjection
{
    public function __construct(private ProjectorManager $projectorManager,
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

        $projectionName = new StreamName($request->get('name'));

        $this->projectorManager->reset($projectionName->toString());

        return $this->response->withStatusCode(204);
    }
}
