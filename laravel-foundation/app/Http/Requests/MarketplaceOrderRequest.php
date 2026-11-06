<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MarketplaceOrderRequest extends FormRequest
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
            'tab' => ['nullable', 'string', 'in:pesanan,transaksi'],
            'period' => ['nullable', 'string', 'in:today,yesterday,week,month,last_month,custom'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'order_tab' => ['nullable', 'string', 'in:all,new,pickup,shipped,cancelled,returns,complaint'],
            'search' => ['nullable', 'string', 'max:200'],
            'search_by' => ['nullable', 'string', 'in:order,sku,product,tracking,recipient'],
            'courier' => ['nullable', 'string', 'max:100'],
            'payment' => ['nullable', 'string', 'in:cod,non_cod'],
            'condition' => ['nullable', 'string', 'in:unpaid,cancel_request,partial,completed,return,refund'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'return_type' => ['nullable', 'string', 'in:goods,refund,replacement'],
            'return_date' => ['nullable', 'string', 'in:created,updated,order'],
        ];
    }

    public function storeId(): ?string
    {
        $value = $this->input('store_id', $this->input('store'));

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = [
            'period' => (string) ($this->input('period') ?: 'month'),
            'date_start' => (string) ($this->input('date_start') ?: ''),
            'date_end' => (string) ($this->input('date_end') ?: ''),
            'order_tab' => (string) ($this->input('order_tab') ?: 'all'),
            'search' => trim((string) ($this->input('search') ?: '')),
            'search_by' => (string) ($this->input('search_by') ?: 'order'),
            'courier' => trim((string) ($this->input('courier') ?: '')),
            'payment' => (string) ($this->input('payment') ?: ''),
            'condition' => (string) ($this->input('condition') ?: ''),
            'per_page' => (int) ($this->input('per_page') ?: 50),
            'page' => (int) ($this->input('page') ?: 1),
            'return_type' => (string) ($this->input('return_type') ?: ''),
            'return_date' => (string) ($this->input('return_date') ?: 'created'),
        ];

        return array_filter($filters, static fn (mixed $value): bool => $value !== '');
    }
}
