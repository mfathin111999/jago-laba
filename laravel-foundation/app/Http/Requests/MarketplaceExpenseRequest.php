<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class MarketplaceExpenseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');
        $amount = is_scalar($amount) ? str_replace(['Rp', '.', ' ', ','], ['', '', '', '.'], (string) $amount) : $amount;

        $this->merge([
            'amount' => $amount,
            'category' => $this->input('category') ?: 'Biaya Iklan/Advertising Marketplace',
            'vendor_name' => trim((string) ($this->input('vendor_name') ?: 'Vendor')),
            'expense_date' => $this->input('expense_date') ?: now('Asia/Jakarta')->format('Y-m-d'),
            'payment_method' => $this->input('payment_method') ?: 'Transfer Bank',
            'notes' => trim((string) ($this->input('notes') ?: '')),
            'affects_profit' => $this->boolean('affects_profit'),
            'is_cashflow_only' => $this->boolean('is_cashflow_only'),
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
            'description' => ['required', 'string', 'max:255'],
            'vendor_name' => ['required', 'string', 'max:150'],
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', 'max:50'],
            'account_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'affects_profit' => ['required', 'boolean'],
            'is_cashflow_only' => ['required', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->routeIs('pilot.marketplace.expenses') && ! $this->expectsJson()) {
            throw new HttpResponseException(
                redirect()->back(303)->withErrors($validator)->withInput(),
            );
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
