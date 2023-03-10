<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api;

use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use Chronhub\Storm\Stream\StreamName;
use Illuminate\Contracts\Validation\Factory;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use function explode;
use function array_map;
use function array_filter;

/**
 * @OA\Get(
 *     path="/api/storm/stream/names",
 *     tags={"Stream"},
 *     description="Get stream names",
 *
 *      @OA\Parameter(
 *          name="names",
 *          in="query",
 *          description="Filter stream names ordered by ascendant name",
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
final readonly class RequestStreamNames
{
    public function __construct(protected Chronicler $chronicler,
                                protected Factory $validation,
                                protected ResponseFactory $response)
    {
    }

    public function __invoke(Request $request): ResponseFactory
    {
        $validator = $this->validation->make($request->all(), [
            'names' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->response
                ->withErrors($validator->errors())
                ->withData(['extra' => 'Require one or many stream names separated by comma'])
                ->withStatusCode(400, 'Invalid stream names');
        }

        $streamNames = $this->chronicler->filterStreamNames(
            ...$this->convertStreamNamesFromRequest($request)
        );

        $streamNames = array_map(fn (StreamName $streamName): string => $streamName->name, $streamNames);

        return $this->response
            ->withData($streamNames)
            ->withStatusCode(200);
    }

    private function convertStreamNamesFromRequest(Request $request): array
    {
        $streamNames = array_filter(explode(',', $request->get('names')));

        return array_map(function (string $streamName): StreamName {
            return new StreamName($streamName);
        }, $streamNames);
    }
}
