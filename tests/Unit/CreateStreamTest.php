<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Tests\Unit;

use RuntimeException;
use Illuminate\Http\Request;
use Chronhub\Storm\Stream\Stream;
use Illuminate\Support\MessageBag;
use Chronhub\Storm\Stream\StreamName;
use PHPUnit\Framework\Attributes\Test;
use Chronhub\Storm\Http\Api\CreateStream;
use Illuminate\Contracts\Validation\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use Chronhub\Storm\Http\Api\Tests\UniTestCase;
use Illuminate\Contracts\Validation\Validator;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Contracts\Chronicler\TransactionalChronicler;

#[CoversClass(CreateStream::class)]
class CreateStreamTest extends UniTestCase
{
    private Factory|MockObject $validation;

    private Validator|MockObject $validator;

    private ResponseFactory|MockObject $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validation = $this->createMock(Factory::class);
        $this->validator = $this->createMock(Validator::class);
        $this->response = $this->createMock(ResponseFactory::class);
    }

    #[Test]
    public function it_create_stream(): void
    {
        $request = Request::create('/api/storm/stream', 'POST', ['name' => 'test']);

        $chronicler = $this->createMock(Chronicler::class);

        $this->validation->expects($this->once())
            ->method('make')
            ->with($request->all(), [
                'name' => 'required|string',
            ])
            ->willReturn($this->validator);

        $this->validator->expects($this->once())
            ->method('fails')
            ->willReturn(false);

        $chronicler->expects($this->once())
            ->method('firstCommit')
            ->with(new Stream(new StreamName('test')));

        $this->response->expects($this->once())
            ->method('withStatusCode')
            ->with(204)
            ->willReturn($this->response);

        $createStream = new CreateStream($chronicler, $this->validation, $this->response);

        $this->assertSame($this->response, $createStream($request));
    }

    #[Test]
    public function it_create_stream_in_transaction(): void
    {
        $request = Request::create('/api/storm/stream', 'POST', ['name' => 'test']);

        $chronicler = $this->createMock(TransactionalChronicler::class);
        $chronicler->expects($this->once())
            ->method('beginTransaction');

        $chronicler->expects($this->once())
            ->method('commitTransaction');

        $chronicler->expects($this->never())
            ->method('rollbackTransaction');

        $this->validation->expects($this->once())
            ->method('make')
            ->with($request->all(), [
                'name' => 'required|string',
            ])
            ->willReturn($this->validator);

        $this->validator->expects($this->once())
            ->method('fails')
            ->willReturn(false);

        $chronicler->expects($this->once())
            ->method('firstCommit')
            ->with(new Stream(new StreamName('test')));

        $this->response->expects($this->once())
            ->method('withStatusCode')
            ->with(204)
            ->willReturn($this->response);

        $createStream = new CreateStream($chronicler, $this->validation, $this->response);

        $this->assertSame($this->response, $createStream($request));
    }

    #[Test]
    public function it_rollback_on_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('foo');

        $request = Request::create('/api/storm/stream', 'POST', ['name' => 'test']);

        $chronicler = $this->createMock(TransactionalChronicler::class);
        $chronicler->expects($this->once())->method('beginTransaction');

        $chronicler->expects($this->never())->method('commitTransaction');

        $chronicler->expects($this->once())->method('rollbackTransaction');

        $this->validation->expects($this->once())
            ->method('make')
            ->with($request->all(), [
                'name' => 'required|string',
            ])
            ->willReturn($this->validator);

        $this->validator->expects($this->once())
            ->method('fails')
            ->willReturn(false);

        $chronicler->expects($this->once())
            ->method('firstCommit')
            ->with(new Stream(new StreamName('test')))
            ->willThrowException(new RuntimeException('foo'));

        $this->response->expects($this->never())->method('withStatusCode');

        $createStream = new CreateStream($chronicler, $this->validation, $this->response);
        $createStream($request);
    }

    #[Test]
    public function it_fails_validate_stream_name(): void
    {
        $request = Request::create('/api/storm/stream', 'POST', ['foo' => 'test']);

        $chronicler = $this->createMock(Chronicler::class);

        $this->validation->expects($this->once())
            ->method('make')
            ->with($request->all(), [
                'name' => 'required|string',
            ])
            ->willReturn($this->validator);

        $this->validator->expects($this->once())
            ->method('fails')
            ->willReturn(true);

        $errors = new MessageBag(['name' => 'required']);

        $this->validator->expects($this->once())
            ->method('errors')
            ->willReturn($errors);

        $chronicler->expects($this->never())->method('firstCommit');

        $this->response->expects($this->once())
            ->method('withErrors')
            ->with($errors)
            ->willReturn($this->response);

        $this->response->expects($this->once())
            ->method('withStatusCode')
            ->with(400)
            ->willReturn($this->response);

        $createStream = new CreateStream($chronicler, $this->validation, $this->response);

        $this->assertSame($this->response, $createStream($request));
    }
}
