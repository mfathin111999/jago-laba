<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MarketplaceCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('report.view') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['store_id' => $this->input('store_id', $this->input('store'))]);
    }

    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:200'],
            'product_status' => ['nullable', 'string', 'max:50'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_start'],
            'format' => ['nullable', 'in:json,csv'],
        ];
    }
}
