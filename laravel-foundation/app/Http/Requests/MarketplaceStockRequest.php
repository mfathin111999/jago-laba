<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MarketplaceStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['store_id' => $this->input('store_id', $this->input('store'))]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'string', 'max:120'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_start'],
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'in:safe,low,out,unknown,unconfigured'],
            'warehouse' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:name,stock,sold'],
            'format' => ['nullable', 'in:json'],
        ];
    }
}
