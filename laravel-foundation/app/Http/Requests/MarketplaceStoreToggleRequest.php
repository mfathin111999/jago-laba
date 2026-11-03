<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class MarketplaceStoreToggleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $storeId = $this->route('store') ?? $this->input('store_id');

        $this->merge([
            'store_id' => is_scalar($storeId) ? trim((string) $storeId) : '',
            'status' => trim((string) ($this->input('status') ?: 'Nonaktif')),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'string', 'max:50'],
            'status' => ['required', 'string', 'in:Terputus,Nonaktif'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status terhubung hanya dapat ditetapkan setelah verifikasi API resmi.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
