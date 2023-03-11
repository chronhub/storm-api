<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use OpenApi\Attributes\Get;
use Illuminate\Http\Request;
use OpenApi\Attributes\Items;
use OpenApi\Attributes\Schema;
use OpenApi\Attributes\Response;
use OpenApi\Attributes\Parameter;
use OpenApi\Attributes\JsonContent;
use Illuminate\Contracts\Validation\Factory;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Contracts\Projector\ProjectorManager;

#[
    Get(
        path: '/api/storm/projection/state',
        description: 'Get state of projection name',
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
            new Response(response: 200, description: 'ok', content: new JsonContent(type: 'array', items: new Items(type: 'object'))),
            new Response(ref: '#/components/responses/400', response: 400),
            new Response(ref: '#/components/responses/401', response: 401),
            new Response(ref: '#/components/responses/403', response: 403),
            new Response(ref: '#/components/responses/500', response: 500),
            new Response(response: 404, description: 'Projection not found', content: new JsonContent(ref: '#/components/schemas/Error')),
        ],
    ),
]
final readonly class RequestProjectionState
{
    public function __construct(protected ProjectorManager $projectorManager,
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
                ->withStatusCode(400, 'Invalid projection name');
        }

        $projectionName = $request->get('name');

        $result = $this->projectorManager->stateOf($projectionName);

        return $this->response
            ->withStatusCode(200)
            ->withData($result);
    }
}
