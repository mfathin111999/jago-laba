<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MarketplaceSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('sync.trigger') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'string', 'max:50'],
            'run_key' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]{1,100}$/'],
        ];
    }
}
