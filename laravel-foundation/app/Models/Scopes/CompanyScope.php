<?php

namespace App\Models\Scopes;

use App\Authorization\RequestContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Inside a web request every query on a company-owned model is limited to the active company (RequestContext),
 * on top of whatever the caller asks for with forCompany(). A service that passes the wrong company therefore finds
 * nothing instead of another company's rows. Outside a request (queue workers, console commands) there is no active
 * company and the explicit forCompany() filter is the only guard.
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyKey = app(RequestContext::class)->companyKey();

        if ($companyKey !== null) {
            $builder->where($model->qualifyColumn('company_key'), $companyKey);
        }
    }
}
