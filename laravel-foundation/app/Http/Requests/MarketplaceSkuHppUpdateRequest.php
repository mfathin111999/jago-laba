<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class MarketplaceSkuHppUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $hpp = $this->input('hpp');
        $hpp = is_scalar($hpp) ? str_replace(['Rp', '.', ' ', ','], ['', '', '', '.'], (string) $hpp) : $hpp;

        $this->merge([
            'sku' => trim((string) ($this->input('sku') ?: '')),
            'hpp' => $hpp,
            'product_variant_id' => $this->input('product_variant_id') ? trim((string) $this->input('product_variant_id')) : null,
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
            'sku' => ['required', 'string', 'max:100'],
            'hpp' => ['required', 'numeric', 'gt:0'],
            'product_variant_id' => ['nullable', 'string', 'max:100'],
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
