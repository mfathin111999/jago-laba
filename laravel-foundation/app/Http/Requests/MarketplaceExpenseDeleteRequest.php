<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class MarketplaceExpenseDeleteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route('id') !== null) {
            $this->merge([
                'id' => (int) $this->route('id'),
            ]);
        }
    }

    public function authorize(): bool
    {
        return $this->user('web')?->can('expense.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'string', 'max:50'],
            'id' => ['required', 'integer', 'gt:0'],
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
