<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use Illuminate\Contracts\Validation\Validator;
use Chronhub\Storm\Contracts\Chronicler\QueryFilter;

/**
 * @OA\Get(
 *     path="/api/storm/stream/from_to",
 *     tags={"Stream"},
 *     description="Get stream events by stream name from one position to another position",
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
 *     name="from",
 *     in="query",
 *     description="From stream position",
 *     required=true,
 *
 *     @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Parameter(
 *     name="to",
 *     in="query",
 *     description="To stream position",
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
final readonly class RetrieveFromToStreamPosition extends RetrieveWithQueryFilter
{
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

        return ($this->queryFilter)($from, $to, $direction);
    }
}
