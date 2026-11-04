<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MarketplaceIncomeRequest extends FormRequest
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
            'tab' => ['nullable', 'in:income,payout'],
            'store_id' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'in:Shopee,TikTok Shop'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_start'],
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'in:estimated,final,unknown'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'payout_status' => ['nullable', 'in:succeeded,processing,failed,cancelled,unknown'],
            'payout_date_basis' => ['nullable', 'in:requested,completed'],
            'payout_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
