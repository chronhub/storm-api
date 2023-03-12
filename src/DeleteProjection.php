<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Illuminate\Http\Request;
use OpenApi\Attributes\Delete;
use OpenApi\Attributes\Schema;
use OpenApi\Attributes\Response;
use OpenApi\Attributes\Parameter;
use OpenApi\Attributes\JsonContent;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Contracts\Projector\ProjectorManager;

#[
    Delete(
        path: '/projection',
        operationId: 'deleteProjection',
        description: 'Delete projection by stream name',
        tags: ['Projection'],
        parameters: [
            new Parameter(
                name: 'name',
                description: 'Projection name',
                in: 'query',
                required: true,
                schema: new Schema(type: 'string'),
            ),
            new Parameter(
                name: 'include_events',
                description: 'With emitted events',
                in: 'query',
                required: true,
                schema: new Schema(type: 'boolean'),
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
final readonly class DeleteProjection
{
    public function __construct(private ProjectorManager $projectorManager,
                                private Factory $validation,
                                private ResponseFactory $response)
    {
    }

    public function __invoke(Request $request): ResponseFactory
    {
        $validator = $this->makeValidatorFor($request);

        if ($validator->fails()) {
            return $this->response
                ->withErrors($validator->errors())
                ->withStatusCode(400);
        }

        $projectionName = $request->get('name');
        $includeEvents = (bool) $request->get('include_events');

        $this->projectorManager->delete($projectionName, $includeEvents);

        return $this->response->withStatusCode(204);
    }

    private function makeValidatorFor(Request $request): Validator
    {
        return $this->validation->make($request->all(), [
            'name' => 'required|string',
            'include_events' => 'required|boolean',
        ]);
    }
}
