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
use Chronhub\Storm\Http\Api\QueryFilter\FromToStreamPosition;
use Chronhub\Storm\Contracts\Serializer\StreamEventSerializer;

#[
    Get(
        path: '/api/storm/stream/from_to',
        description: 'Retrieve stream events from included position to next position',
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
                name: 'from',
                description: 'from included stream position',
                in: 'query',
                required: true,
                schema: new Schema(type: 'integer', minimum: 1)
            ),
            new Parameter(
                name: 'to',
                description: 'to included stream position, must be greater than from',
                in: 'query',
                required: true,
                schema: new Schema(type: 'integer', minimum: 2)
            ),
            new Parameter(
                name: 'direction',
                description: 'sort stream',
                in: 'query',
                required: true,
                schema: new Schema(type: 'string', enum: ['asc', 'desc'])
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
final readonly class RetrieveFromToStreamPosition extends RetrieveWithQueryFilter
{
    public function __construct(protected Chronicler $chronicler,
                                protected StreamEventSerializer $eventSerializer,
                                protected Factory $validation,
                                protected ResponseFactory $response,
                                protected FromToStreamPosition $query)
    {
    }

    protected function makeValidator(Request $request): Validator
    {
        return $this->validation->make($request->all(), [
            'name' => 'required|string',
            'from' => 'required|integer|min:0|not_in:0',
            'to' => 'required|integer|gt:from',
            'direction' => 'required|string|in:asc,desc',
        ]);
    }

    protected function makeQueryFilter(Request $request): QueryFilter
    {
        $from = (int) $request->get('from');
        $to = (int) $request->get('to');
        $direction = $request->get('direction');

        return $this->query->filter($from, $to, $direction);
    }
}
