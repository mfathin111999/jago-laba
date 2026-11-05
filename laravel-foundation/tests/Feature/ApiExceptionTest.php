<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\ResourceNotFound;
use App\Exceptions\StoreForbidden;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiExceptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__test/store-forbidden', fn () => throw new StoreForbidden());
        Route::get('/__test/not-found', fn () => throw new ResourceNotFound('Data tidak ada.', 'THING_NOT_FOUND'));
        Route::get('/__test/idempotency', fn () => throw new IdempotencyConflict('Kunci sudah dipakai.'));
        Route::get('/__test/guarded-failure', fn () => ApiResponse::guard(
            static fn () => throw new \RuntimeException('secret internal detail'),
            'WRITE_FAILED',
            'Belum berhasil disimpan.',
            'Test failure.',
        ));
        Route::get('/__test/guarded-typed', fn () => ApiResponse::guard(static fn () => throw new StoreForbidden('Toko lain.'), 'WRITE_FAILED', 'x', 'x'));
    }

    public function test_store_forbidden_is_rendered_with_the_contract_shape(): void
    {
        $response = $this->getJson('/__test/store-forbidden')->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'Toko marketplace tidak ditemukan dalam perusahaan aktif.',
            'code' => 'STORE_FORBIDDEN',
        ]);

        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('request_id'));
        $this->assertNotEmpty($response->json('request_id'));
    }

    public function test_resource_not_found_carries_its_own_code(): void
    {
        $this->getJson('/__test/not-found')->assertStatus(404)->assertJson(['success' => false, 'code' => 'THING_NOT_FOUND', 'message' => 'Data tidak ada.']);
    }

    public function test_idempotency_conflict_is_409(): void
    {
        $this->getJson('/__test/idempotency')->assertStatus(409)->assertJson(['success' => false, 'code' => 'IDEMPOTENCY_CONFLICT', 'message' => 'Kunci sudah dipakai.']);
    }

    public function test_guard_answers_unexpected_failures_without_leaking_the_message(): void
    {
        $response = $this->getJson('/__test/guarded-failure')->assertStatus(500)->assertJson([
            'success' => false,
            'code' => 'WRITE_FAILED',
            'message' => 'Belum berhasil disimpan.',
        ]);

        $this->assertStringNotContainsString('secret internal detail', $response->getContent());
    }

    public function test_guard_lets_typed_refusals_through_to_the_handler(): void
    {
        $this->getJson('/__test/guarded-typed')->assertStatus(403)->assertJson(['code' => 'STORE_FORBIDDEN', 'message' => 'Toko lain.']);
    }

    public function test_api_exceptions_stay_compatible_with_plain_domain_exception_handling(): void
    {
        $exception = new StoreForbidden();

        $this->assertInstanceOf(\DomainException::class, $exception);
        $this->assertInstanceOf(ApiException::class, $exception);
        $this->assertSame(403, $exception->getCode());
        $this->assertSame(404, (new ResourceNotFound('x', 'Y'))->getCode());
        $this->assertInstanceOf(JsonResponse::class, ApiResponse::error('m', 'C', 422));
    }
}
