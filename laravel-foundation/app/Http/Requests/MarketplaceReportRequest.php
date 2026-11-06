<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarketplaceReportRequest extends FormRequest
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
            'store_id' => ['required', 'string', 'max:120'],
            'format' => ['nullable', 'in:json,csv'],
            'search' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_start'],
            'ref_type' => ['nullable', 'string', 'max:100'],
            'warehouse' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', 'in:name,stock,sold'],
            'date_basis' => ['nullable', 'in:requested,completed'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
