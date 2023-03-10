<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use Illuminate\Contracts\Validation\Validator;
use Chronhub\Storm\Contracts\Chronicler\QueryFilter;

/**
 * @OA\Get(
 *     path="/api/storm/stream/from",
 *     tags={"Stream"},
 *     description="Get stream position by stream name",
 *
 *     @OA\Parameter(
 *     name="name",
 *     in="query",
 *     description="Stream name",
 *     required=true,
 *
 *     @OA\Schema(type="string")
 *     ),
 *
 *     @OA\Parameter(
 *     name="position",
 *     in="query",
 *     description="Stream position",
 *     required=true,
 *
 *     @OA\Schema(type="string")
 *     ),
 *
 *     @OA\Response(
 *          response=200,
 *          description="ok",
 *     )
 * )
 */
final readonly class RetrieveFromIncludedStreamPosition extends RetrieveWithQueryFilter
{
    protected function makeValidator(Request $request): Validator
    {
        return $this->validation->make($request->all(), [
            'name' => 'required|string',
            'position' => 'required|integer|min:0|not_in:0',
        ]);
    }

    protected function makeQueryFilter(Request $request): QueryFilter
    {
        $position = (int) $request->get('position');

        return ($this->queryFilter)($position);
    }
}
