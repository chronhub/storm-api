<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Tests\Unit;

use Exception;
use Generator;
use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Chronhub\Storm\Stream\StreamName;
use PHPUnit\Framework\Attributes\Test;
use Chronhub\Storm\Http\Api\DeleteStream;
use Illuminate\Contracts\Validation\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use Chronhub\Storm\Http\Api\Tests\UniTestCase;
use Illuminate\Contracts\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Chronicler\Exceptions\StreamNotFound;

#[CoversClass(DeleteStream::class)]
class DeleteStreamTest extends UniTestCase
{
    private Factory|MockObject $validation;

    private Validator|MockObject $validator;

    private ResponseFactory|MockObject $response;

    private Chronicler|MockObject $chronicler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chronicler = $this->createMock(Chronicler::class);
        $this->validation = $this->createMock(Factory::class);
        $this->validator = $this->createMock(Validator::class);
        $this->response = $this->createMock(ResponseFactory::class);
    }

    #[Test]
    public function it_delete_stream(): void
    {
        $request = Request::create('/api/storm', 'DELETE', ['name' => 'transaction']);

        $this->validation->expects($this->once())
            ->method('make')
            ->with(['name' => 'transaction'])
            ->willReturn($this->validator);

        $this->validator->expects($this->once())
            ->method('fails')
            ->willReturn(false);

        $this->chronicler->expects($this->once())
            ->method('delete')
            ->with(new StreamName('transaction'));

        $this->response->expects($this->once())
            ->method('withStatusCode')
            ->with(204)
            ->willReturn($this->response);

        $deleteStream = new DeleteStream($this->chronicler, $this->validation, $this->response);

        $this->assertSame($this->response, $deleteStream($request));
    }

    #[Test]
    public function it_fail_validate_stream_name(): void
    {
        $request = Request::create('/api/storm', 'DELETE', ['foo' => 'transaction']);

        $this->validation->expects($this->once())
            ->method('make')
            ->with(['foo' => 'transaction'])
            ->willReturn($this->validator);

        $this->validator->expects($this->once())
            ->method('fails')
            ->willReturn(true);

        $errors = new MessageBag(['name' => ['The name field is required.']]);

        $this->validator->expects($this->once())
            ->method('errors')
            ->willReturn($errors);

        $this->response->expects($this->once())
            ->method('withErrors')
            ->with($errors)
            ->willReturn($this->response);

        $this->response->expects($this->once())
            ->method('withStatusCode')
            ->with(400)
            ->willReturn($this->response);

        $this->chronicler->expects($this->never())->method('delete');

        $deleteStream = new DeleteStream($this->chronicler, $this->validation, $this->response);

        $this->assertSame($this->response, $deleteStream($request));
    }

    #[DataProvider('provideException')]
    #[Test]
    public function it_does_not_hold_exception_on_delete_stream(Exception $exception): void
    {
        $this->expectExceptionObject($exception);

        $request = Request::create('/api/storm', 'DELETE', ['name' => 'transaction']);

        $this->validation->expects($this->once())
            ->method('make')
            ->with(['name' => 'transaction'])
            ->willReturn($this->validator);

        $this->validator->expects($this->once())
            ->method('fails')
            ->willReturn(false);

        $this->chronicler->expects($this->once())
            ->method('delete')
            ->with(new StreamName('transaction'))
            ->willThrowException($exception);

        $this->response->expects($this->never())->method('withStatusCode');

        $deleteStream = new DeleteStream($this->chronicler, $this->validation, $this->response);

        $deleteStream($request);
    }

    public static function provideException(): Generator
    {
        yield [new RuntimeException('error')];
        yield [StreamNotFound::withStreamName(new StreamName('transaction'))];
    }
}
