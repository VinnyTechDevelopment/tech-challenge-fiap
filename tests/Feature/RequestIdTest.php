<?php

namespace Tests\Feature;

use Tests\TestCase;

class RequestIdTest extends TestCase
{
    public function test_response_includes_a_generated_request_id_header(): void
    {
        $response = $this->getJson('/api/customer/me');

        $response->assertHeader('X-Request-Id');
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_response_reuses_an_incoming_request_id_header(): void
    {
        $response = $this->withHeader('X-Request-Id', 'meu-id-de-correlacao')
            ->getJson('/api/customer/me');

        $response->assertHeader('X-Request-Id', 'meu-id-de-correlacao');
    }
}
