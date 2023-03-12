<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use Chronhub\Storm\Http\Api\Tests\UniTestCase;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;

#[CoversClass(ResponseFactory::class)]
class ResponseFactoryTest extends UniTestCase
{
    private Request|\PHPUnit\Framework\MockObject\MockObject $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(Request::class);
    }

    #[Test]
    public function it_can_be_constructed(): void
    {
        $response = new ResponseFactory();

        $jsonResponse = $response->toResponse($this->request);

        $this->assertEmpty($jsonResponse->getData(true));
        $this->assertEquals(200, $jsonResponse->getStatusCode());
    }

    #[Test]
    public function it_set_status_and_message(): void
    {
        $response = new ResponseFactory();
        $response->withStatusCode(201, 'created');

        $jsonResponse = $response->toResponse($this->request);
        $this->assertEquals(['message' => 'created'], $jsonResponse->getData(true));
        $this->assertEquals(201, $jsonResponse->getStatusCode());
    }

    #[Test]
    public function it_set_header(): void
    {
        $response = new ResponseFactory();
        $response->withHeader('x-token', '1234');

        $jsonResponse = $response->toResponse($this->request);
        $this->assertEmpty($jsonResponse->getData(true));
        $this->assertEquals(200, $jsonResponse->getStatusCode());
        $this->assertEquals('1234', $jsonResponse->headers->get('x-token'));
    }

    #[Test]
    public function it_set_data(): void
    {
        $response = new ResponseFactory();
        $response->withData(['foo' => 'bar']);

        $jsonResponse = $response->toResponse($this->request);
        $this->assertEquals(['data' => ['foo' => 'bar']], $jsonResponse->getData(true));
        $this->assertEquals(200, $jsonResponse->getStatusCode());
    }

    #[Test]
    public function it_set_validation_errors(): void
    {
        $response = new ResponseFactory();
        $response->withErrors(new MessageBag(['foo' => 'bar']));

        $jsonResponse = $response->toResponse($this->request);

        $this->assertEquals(['errors' => ['foo' => ['bar']]], $jsonResponse->getData(true));
        $this->assertEquals(200, $jsonResponse->getStatusCode());
    }

    #[Test]
    public function it_set_all(): void
    {
        $response = new ResponseFactory();

        $response->withStatusCode(200, 'ok');
        $response->withHeader('x-token', '1234');
        $response->withData(['some' => 'result']);
        $response->withErrors(new MessageBag(['foo' => 'bar']));

        $jsonResponse = $response->toResponse($this->request);

        $this->assertEquals(
            [
                'errors' => ['foo' => ['bar']],
                'data' => ['some' => 'result'],
                'message' => 'ok',
            ],
            $jsonResponse->getData(true)
        );

        $this->assertEquals('1234', $jsonResponse->headers->get('x-token'));
        $this->assertEquals(200, $jsonResponse->getStatusCode());
    }
}
