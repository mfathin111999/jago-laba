<?php

namespace App\Services\Marketplace\Integrations;

/** Reads one platform's catalog and orders in the shape the shared sync pipeline expects. */
interface MarketplaceReadAdapter
{
    public function catalogPage(array $query, string $token): array;

    public function product(string $id, array $query, string $token): array;

    public function orderPage(array $query, string $token, array $filter): array;

    public function order(string $id, array $query, string $token): array;
}
