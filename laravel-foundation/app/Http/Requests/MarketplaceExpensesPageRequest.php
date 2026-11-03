<?php

namespace App\Http\Requests;

/** Expenses report request: the store may be omitted, then the page falls back to the first active store. */
final class MarketplaceExpensesPageRequest extends MarketplaceReportRequest
{
    public function rules(): array
    {
        return ['store_id' => ['nullable', 'string', 'max:120']] + parent::rules();
    }
}
