<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Contracts\Validation\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use Chronhub\Storm\Http\Api\Tests\UniTestCase;
use Illuminate\Contracts\Validation\Validator;
use Chronhub\Storm\Http\Api\RequestCategoryNames;
use Chronhub\Storm\Contracts\Chronicler\Chronicler;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;

#[CoversClass(RequestCategoryNames::class)]
class RequestCategoryNamesTest extends UniTestCase
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
    public function it_return_category(): void
    {
        $request = Request::create('/api/storm/categories', 'GET', ['name' => 'transaction-withdraw']);

        $this->validation->expects($this->once())
            ->method('make')
            ->with(['name' => 'transaction-withdraw'])
            ->willReturn($this->validator);

        $this->validator->expects($this->once())
            ->method('fails')
            ->willReturn(false);

        $this->chronicler->expects($this->once())
            ->method('filterCategoryNames')
            ->with('transaction-withdraw')
            ->willReturn(['transaction-withdraw']);

        $this->response->expects($this->once())
            ->method('withStatusCode')
            ->with(200)
            ->willReturn($this->response);

        $this->response->expects($this->once())
            ->method('withData')
            ->with(['transaction-withdraw'])
            ->willReturn($this->response);

        $requestCategoryNames = new RequestCategoryNames($this->chronicler, $this->validation, $this->response);
        $requestCategoryNames($request);
    }

    #[Test]
    public function it_return_categories_separated_by_comma(): void
    {
        $categories = 'transaction-credit,transaction-debit';
        $request = Request::create('/api/storm/categories', 'GET', ['name' => $categories]);

        $this->validation->expects($this->once())
            ->method('make')
            ->with(['name' => $categories])
            ->willReturn($this->validator);

        $this->validator->expects($this->once())
            ->method('fails')
            ->willReturn(false);

        $this->chronicler->expects($this->once())
            ->method('filterCategoryNames')
            ->with('transaction-credit', 'transaction-debit')
            ->willReturn(['transaction-credit', 'transaction-debit']);

        $this->response->expects($this->once())
            ->method('withStatusCode')
            ->with(200)
            ->willReturn($this->response);

        $this->response->expects($this->once())
            ->method('withData')
            ->with(['transaction-credit', 'transaction-debit'])
            ->willReturn($this->response);

        $requestCategoryNames = new RequestCategoryNames($this->chronicler, $this->validation, $this->response);
        $requestCategoryNames($request);
    }

    #[Test]
    public function it_fails_on_validate_name(): void
    {
        $request = Request::create('/api/storm/categories', 'GET', ['foo' => 'transaction-withdraw']);
        $errors = new MessageBag(['name' => 'The name field is required.']);

        $this->validation->expects($this->once())
            ->method('make')
            ->with(['foo' => 'transaction-withdraw'])
            ->willReturn($this->validator);

        $this->validator->expects($this->once())
            ->method('fails')
            ->willReturn(true);

        $this->validator->expects($this->once())
            ->method('errors')
            ->willReturn($errors);

        $this->response->expects($this->once())
            ->method('withErrors')
            ->with($errors)
            ->willReturn($this->response);

        $this->response->expects($this->once())
            ->method('withData')
            ->with(['extra' => 'Require one or many category names separated by comma'])
            ->willReturn($this->response);

        $this->response->expects($this->once())
            ->method('withStatusCode')
            ->with(400)
            ->willReturn($this->response);

        $this->chronicler->expects($this->never())->method('filterCategoryNames');

        $requestCategoryNames = new RequestCategoryNames($this->chronicler, $this->validation, $this->response);
        $requestCategoryNames($request);
    }
}
