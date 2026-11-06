<?php

namespace App\Http\Requests;

use App\Enums\StockStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MarketplaceStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('report.view') ?? false;
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
            'status' => ['nullable', Rule::enum(StockStatus::class)],
            'warehouse' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:name,stock,sold'],
            'format' => ['nullable', 'in:json'],
        ];
    }
}
