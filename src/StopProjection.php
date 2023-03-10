<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use Illuminate\Contracts\Validation\Factory;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Contracts\Projector\ProjectorManager;

/**
 * @OA\Get (
 *     path="/api/storm/projection/stop",
 *     tags={"Projection"},
 *     description="Stop projection by stream name",
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
 *     @OA\Response(
 *          response=200,
 *          description="ok",
 *     )
 * )
 */
final readonly class StopProjection
{
    public function __construct(private ProjectorManager $projectorManager,
                                private Factory $validation,
                                private ResponseFactory $response)
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
                ->withStatusCode(400);
        }

        $projectionName = $request->get('projection_name');

        $this->projectorManager->stop($projectionName);

        return $this->response->withStatusCode(204);
    }
}
