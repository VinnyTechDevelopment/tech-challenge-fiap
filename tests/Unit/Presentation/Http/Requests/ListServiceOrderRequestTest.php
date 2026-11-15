<?php

namespace Tests\Unit\Presentation\Http\Requests;

use App\Presentation\Http\Requests\ListServiceOrderRequest;
use PHPUnit\Framework\TestCase;

class ListServiceOrderRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $request = new ListServiceOrderRequest;

        $this->assertTrue($request->authorize());
    }

    public function test_rules_returns_expected_validation_rules(): void
    {
        $request = new ListServiceOrderRequest;

        $this->assertSame([
            'page' => 'integer|min:1',
            'perPage' => 'integer|min:1',
        ], $request->rules());
    }
}
