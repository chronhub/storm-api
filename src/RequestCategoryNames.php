<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use Illuminate\Contracts\Validation\Factory;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use function explode;
use function array_filter;

/**
 * @OA\Get(
 *  path="/api/storm/stream/categories",
 *  tags={"Stream"},
 *  description="Get category names delimited by comma",
 *
 *      @OA\Parameter(
 *          name="categories",
 *          in="query",
 *          description="Filter category names ordered by ascendant name",
 *          required=true,
 *
 *          @OA\Schema(
 *              type="array",
 *
 *          @OA\Items(type="string")
 *        ),
 *     ),
 *
 *     @OA\Response(
 *          response=200,
 *          description="ok",
 *     )
 * )
 */
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
            'categories' => 'required|string',
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
        $categoryNames = explode(',', $request->get('categories', []));

        return array_filter($categoryNames);
    }
}
