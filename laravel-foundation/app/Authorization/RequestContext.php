<?php

namespace App\Authorization;

/**
 * The company, store and workspace the current request runs in, as decided by the context middleware
 * (EnsureCompanyContext, EnsureStoreContext, EnsureWorkspace).
 *
 * Registered as a scoped singleton: a fresh instance per request (and per queued job), and AssignRequestId resets it at
 * the start of every web request, so a value can never leak from one request into the next. Anything that needs "the
 * active company" (controllers through `$request->companyKey()`, later Eloquent global scopes) reads it from here.
 */
final class RequestContext
{
    private ?string $companyKey = null;

    private ?string $storeId = null;

    private ?string $workspace = null;

    public function reset(): void
    {
        $this->companyKey = $this->storeId = $this->workspace = null;
    }

    public function setCompanyKey(?string $companyKey): void
    {
        $this->companyKey = $companyKey === null || $companyKey === '' ? null : $companyKey;
    }

    public function setStoreId(?string $storeId): void
    {
        $this->storeId = $storeId === null || $storeId === '' ? null : $storeId;
    }

    public function setWorkspace(?string $workspace): void
    {
        $this->workspace = $workspace === null || $workspace === '' ? null : $workspace;
    }

    public function companyKey(): ?string
    {
        return $this->companyKey;
    }

    public function storeId(): ?string
    {
        return $this->storeId;
    }

    public function workspace(): ?string
    {
        return $this->workspace;
    }
}
