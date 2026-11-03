<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class MarketplaceGlobalHppUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $hpp = $this->input('hpp');
        $hpp = is_scalar($hpp) ? str_replace(['Rp', '.', ' ', ','], ['', '', '', '.'], (string) $hpp) : $hpp;

        $this->merge([
            'hpp' => $hpp,
            'sku' => $this->input('sku') ? trim((string) $this->input('sku')) : null,
            'product_id' => $this->input('product_id') ? trim((string) $this->input('product_id')) : null,
            'effective_from' => $this->input('effective_from') ? trim((string) $this->input('effective_from')) : null,
            'apply_all_variants' => $this->input('apply_all_variants') === '1' || $this->boolean('apply_all_variants'),
            'recalculate_history' => $this->input('recalculate_history') === '1' || $this->boolean('recalculate_history'),
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
            'hpp' => ['required', 'numeric', 'gt:0'],
            'sku' => ['nullable', 'string', 'max:100'],
            'product_id' => ['nullable', 'string', 'max:100'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'apply_all_variants' => ['required', 'boolean'],
            'recalculate_history' => ['required', 'boolean'],
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
