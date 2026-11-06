<?php

namespace App\Models\Concerns;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * For models whose table has a company_key column: adds the CompanyScope global scope and a forCompany() scope.
 *
 * @method static Builder forCompany(string $companyKey)
 */
trait ScopedToCompany
{
    public static function bootScopedToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyKey): Builder
    {
        return $query->where($this->qualifyColumn('company_key'), $companyKey);
    }
}
