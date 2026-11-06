<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class MarketplaceHppBatchRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $rawChanges = $this->input('changes');
        if (is_string($rawChanges)) {
            $decoded = json_decode($rawChanges, true);
            $this->merge(['changes' => is_array($decoded) ? $decoded : $rawChanges]);
        }
    }

    public function authorize(): bool
    {
        return $this->user('web')?->can('hpp.edit') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1', 'max:500'],
            'changes.*.store_id' => ['required', 'string', 'max:50'],
            'changes.*.sku' => ['required', 'string', 'max:100'],
            'changes.*.hpp' => ['required'],
            'changes.*.effective_from' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Format HPP tidak valid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
