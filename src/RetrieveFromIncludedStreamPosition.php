<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use OpenApi\Attributes\Get;
use Illuminate\Http\Request;
use OpenApi\Attributes\Schema;
use OpenApi\Attributes\Response;
use OpenApi\Attributes\Parameter;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Contracts\Chronicler\QueryFilter;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Contracts\Serializer\StreamEventSerializer;
use Chronhub\Storm\Http\Api\QueryFilter\FromIncludedStreamPosition;

#[
    Get(
        path: '/stream/from',
        operationId: 'retrieveFromIncludedStreamPosition',
        description: 'Retrieve stream events from included position',
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
        ],
        responses: [
            new Response(ref: '#/components/responses/StreamEvents', response: 200),
            new Response(ref: '#/components/responses/400', response: 400),
            new Response(ref: '#/components/responses/401', response: 401),
            new Response(ref: '#/components/responses/403', response: 403),
            new Response(ref: '#/components/responses/StreamNotFound', response: 404),
            new Response(ref: '#/components/responses/500', response: 500),
        ],
    ),
]
final readonly class RetrieveFromIncludedStreamPosition extends RetrieveWithQueryFilter
{
    public function __construct(protected Chronicler $chronicler,
                                protected StreamEventSerializer $eventSerializer,
                                protected Factory $validation,
                                protected ResponseFactory $response,
                                protected FromIncludedStreamPosition $query)
    {
    }

    protected function makeValidator(Request $request): Validator
    {
        return $this->validation->make($request->all(), [
            'name' => 'required|string',
            'from' => 'required|integer|min:0|not_in:0',
        ]);
    }

    protected function makeQueryFilter(Request $request): QueryFilter
    {
        $position = (int) $request->get('from');

        return $this->query->filter($position);
    }
}
