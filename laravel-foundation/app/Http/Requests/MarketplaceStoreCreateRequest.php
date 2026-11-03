<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class MarketplaceStoreCreateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'platform' => trim((string) ($this->input('platform') ?: 'Shopee')),
            'store_name' => trim((string) ($this->input('store_name') ?: '')),
            'store_identifier' => trim((string) ($this->input('store_identifier') ?: '')),
            'sync_mode' => trim((string) ($this->input('sync_mode') ?: 'API')),
            'default_admin_fee_pct' => $this->input('default_admin_fee_pct') !== null ? (float) $this->input('default_admin_fee_pct') : 11.0,
            'default_free_shipping_pct' => $this->input('default_free_shipping_pct') !== null ? (float) $this->input('default_free_shipping_pct') : 4.0,
            'default_payment_fee_pct' => $this->input('default_payment_fee_pct') !== null ? (float) $this->input('default_payment_fee_pct') : 1.0,
            'default_account_id' => $this->input('default_account_id') ? (int) $this->input('default_account_id') : null,
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
            'store_name' => ['required', 'string', 'max:150'],
            'platform' => ['required', 'string', 'in:Shopee,TikTok Shop,Tokopedia,Lazada'],
            'store_identifier' => ['nullable', 'string', 'max:100'],
            'id' => ['nullable', 'string', 'max:50'],
            'sync_mode' => ['nullable', 'string', 'max:20'],
            'default_account_id' => ['nullable', 'integer'],
            'default_admin_fee_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_free_shipping_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_payment_fee_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'partner_id' => ['prohibited'],
            'app_key' => ['prohibited'],
            'access_token' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'partner_id.prohibited' => 'Kredensial langsung tidak diterima. Hubungkan toko melalui OAuth resmi.',
            'app_key.prohibited' => 'Kredensial langsung tidak diterima. Hubungkan toko melalui OAuth resmi.',
            'access_token.prohibited' => 'Kredensial langsung tidak diterima. Hubungkan toko melalui OAuth resmi.',
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
