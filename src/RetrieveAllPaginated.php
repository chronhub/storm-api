<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use Illuminate\Contracts\Validation\Validator;
use Chronhub\Storm\Contracts\Chronicler\QueryFilter;

/**
 * @OA\Get(
 *     path="/api/storm/stream/paginated",
 *     tags={"Stream"},
 *     description="Get paginated stream events by stream name and aggregate id",
 *
 *     @OA\Parameter(
 *     name="name",
 *     in="query",
 *     description="Projection name",
 *     required=true,
 *
 *     @OA\Schema(type="string")
 *     ),
 *
 *     @OA\Parameter(
 *     name="limit",
 *     in="query",
 *     description="Limit the number of stream events",
 *     required=true,
 *
 *     @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Parameter(
 *     name="direction",
 *     in="query",
 *     description="Sort directioon by ascendant or descendant",
 *     required=true,
 *
 *     @OA\Schema(type="string")
 *     ),
 *
 *     @OA\Parameter(
 *     name="offest",
 *     in="query",
 *     description="Query offset",
 *     required=true,
 *
 *     @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(
 *          response=200,
 *          description="ok",
 *     )
 * )
 */
final readonly class RetrieveAllPaginated extends RetrieveWithQueryFilter
{
    protected function makeValidator(Request $request): Validator
    {
        return $this->validation->make($request->all(), [
            'name' => 'required|string',
            'limit' => 'required|integer',
            'direction' => 'required|string|in:asc,desc',
            'offset' => 'integer|min:0|not_in:0',
        ]);
    }

    protected function makeQueryFilter(Request $request): QueryFilter
    {
        $limit = (int) $request->get('limit');
        $offset = (int) $request->get('offset') ?? 0;
        $direction = $request->get('direction');

        return ($this->queryFilter)($limit, $offset, $direction);
    }
}
