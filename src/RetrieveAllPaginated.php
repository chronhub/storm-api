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
use Illuminate\Contracts\Validation\Validator;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Contracts\Chronicler\QueryFilter;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Http\Api\QueryFilter\AllPaginatedStream;
use Chronhub\Storm\Contracts\Serializer\StreamEventSerializer;

#[
    Get(
        path: '/api/storm/stream/paginated',
        description: 'Retrieve paginated stream events per stream name',
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
                name: 'limit',
                description: 'max number of events to retrieve',
                in: 'query',
                required: true,
                schema: new Schema(type: 'integer', minimum: 1)
            ),
            new Parameter(
                name: 'direction',
                description: 'sort direction',
                in: 'query',
                required: true,
                schema: new Schema(type: 'string', enum: ['asc', 'desc'])
            ),
            new Parameter(
                name: 'offset',
                description: 'query offset',
                in: 'query',
                required: true,
                schema: new Schema(type: 'integer', minimum: 1)
            ),
        ],
        responses: [
            new Response(response: 200, description: 'ok', content: new JsonContent(type: 'array', items: new Items(type: 'object'))),
            new Response(ref: '#/components/responses/400', response: 400),
            new Response(ref: '#/components/responses/401', response: 401),
            new Response(ref: '#/components/responses/403', response: 403),
            new Response(ref: '#/components/responses/500', response: 500),
            new Response(response: 404, description: 'Stream not found', content: new JsonContent(ref: '#/components/schemas/Error')),
        ],
    ),
]
final readonly class RetrieveAllPaginated extends RetrieveWithQueryFilter
{
    public function __construct(protected Chronicler $chronicler,
                                protected StreamEventSerializer $eventSerializer,
                                protected Factory $validation,
                                protected ResponseFactory $response,
                                protected AllPaginatedStream $query)
    {
    }

    protected function makeValidator(Request $request): Validator
    {
        return $this->validation->make($request->all(), [
            'name' => 'required|string',
            'limit' => 'required|integer|min:1',
            'direction' => 'required|string|in:asc,desc',
            'offset' => 'integer|min:0|not_in:0',
        ]);
    }

    protected function makeQueryFilter(Request $request): QueryFilter
    {
        $limit = (int) $request->get('limit');
        $offset = (int) $request->get('offset') ?? 0;
        $direction = $request->get('direction');

        return $this->query->filter($limit, $offset, $direction);
    }
}
