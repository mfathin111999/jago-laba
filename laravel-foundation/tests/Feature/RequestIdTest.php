<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Context;
use Tests\TestCase;

final class RequestIdTest extends TestCase
{
    public function test_a_request_without_an_id_gets_one_in_the_response_header_and_the_log_context(): void
    {
        $response = $this->get('/api/health.php');

        $id = $response->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $id);
        $this->assertSame($id, Context::get('request_id'));
    }

    public function test_a_valid_client_id_is_kept(): void
    {
        $this->get('/api/health.php', ['X-Request-Id' => 'browser-request-001'])
            ->assertHeader('X-Request-Id', 'browser-request-001');

        $this->assertSame('browser-request-001', Context::get('request_id'));
    }

    public function test_an_unsafe_client_id_is_replaced(): void
    {
        $response = $this->get('/api/health.php', ['X-Request-Id' => "bad id\twith spaces"]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $response->headers->get('X-Request-Id'));
    }
}
