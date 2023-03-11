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
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use function trim;
use function explode;
use function array_filter;
use function str_contains;

#[
    Get(
        path: '/api/storm/stream/categories',
        description: 'Get category name(s)',
        tags: ['Stream'],
        parameters: [
            new Parameter(
                name: 'name',
                description: 'Get or or many category name(s) separated by comma',
                in: 'query',
                required: true,
                schema: new Schema(type: 'array', items: new Items(type: 'string'))
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
final readonly class RequestCategoryNames
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
                ->withData(['extra' => 'Require one or many category names separated by comma'])
                ->withStatusCode(400, 'Invalid category names');
        }

        $result = $this->chronicler->filterCategoryNames(...$this->extractCategoryNames($request));

        return $this->response
            ->withStatusCode(200)
            ->withData($result);
    }

    private function extractCategoryNames(Request $request): array
    {
        $names = $request->get('name');

        if (! str_contains($names, ',')) {
            return [trim($names)];
        }

        return array_filter(explode(',', $names));
    }
}
