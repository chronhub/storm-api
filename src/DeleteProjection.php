<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Contracts\Projector\ProjectorManager;

/**
 * @OA\Delete (
 *     path="/api/storm/projection",
 *     tags={"Projection"},
 *     description="Delete projection by stream name",
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
 *     name="include_events",
 *     in="query",
 *     description="with emitted events",
 *     required=true,
 *
 *     @OA\Schema(type="boolean")
 *     ),
 *
 *     @OA\Response(
 *          response=200,
 *          description="Projection deleted",
 *     )
 * )
 */
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
