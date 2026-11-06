<?php

namespace App\Authorization;

use App\Auth\AuthenticatedUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

final class CompanyContext
{
    public function key(?Authenticatable $user): ?string
    {
        $userKey = $user instanceof AuthenticatedUser ? $user->getCompanyKey() : null;

        if ($userKey !== null && $userKey !== '') {
            return $userKey;
        }

        $configured = trim((string) config('jago.company_key', ''));

        return $configured !== '' ? $configured : null;
    }

    public function matchesRequest(Request $request, ?Authenticatable $user): bool
    {
        $context = $this->key($user);

        $request->attributes->set('company_key', $context);
        app(RequestContext::class)->setCompanyKey($context);

        if ($context === null) {
            return false;
        }
        foreach ([$request->route('company_key'), $request->input('company_key'), $request->header('X-Company-Key')] as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_scalar($value) || ! hash_equals($context, trim((string) $value))) {
                return false;
            }
        }

        return true;
    }

    private function requestValue(Request $request): ?string
    {
        $value = $request->route('company_key') ?? $request->input('company_key') ?? $request->header('X-Company-Key');

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
