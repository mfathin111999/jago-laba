<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MarketplacePilotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('report.view') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'store' => ['nullable', 'string', 'max:120'],
            'store_id' => ['nullable', 'string', 'max:120'],
            'tab' => ['nullable', 'string', 'in:dashboard'],
            'period' => ['nullable', 'string', 'in:today,yesterday,week,month,last_month,custom'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'finance_mode' => ['nullable', 'string', 'in:final,estimated'],
        ];
    }

    public function storeId(): ?string
    {
        $value = $this->input('store_id', $this->input('store'));

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return array_filter([
            'period' => (string) ($this->input('period') ?: 'month'),
            'date_start' => (string) ($this->input('date_start') ?: ''),
            'date_end' => (string) ($this->input('date_end') ?: ''),
            'finance_mode' => (string) ($this->input('finance_mode') ?: 'final'),
        ], static fn (string $value): bool => $value !== '');
    }
}
