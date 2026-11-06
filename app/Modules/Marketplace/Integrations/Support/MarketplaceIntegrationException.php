<?php
declare(strict_types=1);

// The class now lives in Laravel (App\Exceptions\MarketplaceIntegrationException); the legacy services that still run
// inside Laravel keep using the global name, which is an alias of it until they are ported.
class_alias(\App\Exceptions\MarketplaceIntegrationException::class, 'MarketplaceIntegrationException');
