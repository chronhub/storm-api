<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use Chronhub\Storm\Stream\StreamName;
use Illuminate\Contracts\Validation\Factory;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;

/**
 * @OA\Get(
 *     path="/api/storm/stream/exists",
 *     tags={"Stream"},
 *     description="Check if stream exists by stream name",
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
 *     @OA\Response(
 *          response=200,
 *          description="ok",
 *     )
 * )
 */
final readonly class RequestStreamExists
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
                ->withStatusCode(400);
        }

        $streamName = new StreamName($request->get('name'));

        $hasStream = $this->chronicler->hasStream($streamName);

        $result = [$streamName->toString() => $hasStream];

        return $this->response
            ->withStatusCode($hasStream ? 200 : 404)
            ->withData($result);
    }
}
