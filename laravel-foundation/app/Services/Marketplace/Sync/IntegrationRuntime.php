<?php

namespace App\Services\Marketplace\Sync;

use App\Exceptions\MarketplaceIntegrationException;
use App\Marketplace\IntegrationPolicy;
use App\Services\Marketplace\Concerns\RunsSql;
use Illuminate\Database\DatabaseManager;

final class IntegrationRuntime
{
    use RunsSql;

    private ?bool $ready = null;

    public function __construct(private readonly DatabaseManager $database) {}

    public function ready(): bool
    {
        return $this->ready ??= (bool) $this->database->connection()->getSchemaBuilder()->hasTable('marketplace_integration_bindings');
    }

    public function binding(int $connection): ?array
    {
        if (! $this->ready()) {
            return null;
        }

        return $this->row('SELECT environment,context_hash,verified_at FROM marketplace_integration_bindings WHERE connection_id=?', [$connection]) ?: null;
    }

    public function assertContext(array $connection, array $config): void
    {
        $binding = $this->binding((int) $connection['id']);
        if ($binding && ! hash_equals($binding['context_hash'], IntegrationPolicy::fingerprint($connection['platform'], $config))) {
            throw new MarketplaceIntegrationException('Konfigurasi atau lingkungan aplikasi berubah. Otorisasi ulang toko sebelum melanjutkan.', 'INTEGRATION_CONTEXT_CHANGED');
        }
    }

    public function bind(array $connection, array $config, bool $reauthorized = false): void
    {
        if (! $this->ready()) {
            return;
        }
        if (! $reauthorized) {
            $this->assertContext($connection, $config);
        }
        $this->run('INSERT INTO marketplace_integration_bindings(connection_id,environment,context_hash,verified_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE environment=VALUES(environment),context_hash=VALUES(context_hash),verified_at=NOW()', [
            $connection['id'], IntegrationPolicy::environment($connection['platform'], $config), IntegrationPolicy::fingerprint($connection['platform'], $config)]);
        if ($reauthorized) {
            $this->run('DELETE FROM marketplace_api_capabilities WHERE connection_id=?', [$connection['id']]);
        }
    }

    public function observe(int $connection, string $resource, ?string $code = null): void
    {
        if (! $this->ready()) {
            return;
        }
        $state = $code ? match (IntegrationPolicy::classify($code)) {
            'authorization' => 'reauthorize','permission' => 'permission','unsupported' => 'unsupported',default => 'temporary'
        } : 'available';
        $this->run('INSERT INTO marketplace_api_capabilities(connection_id,resource,state,last_verified_at,last_attempt_at,last_error_code) VALUES (?,?,?,IF(?=1,NOW(),NULL),NOW(),?) ON DUPLICATE KEY UPDATE state=VALUES(state),last_verified_at=IF(?=1,NOW(),last_verified_at),last_attempt_at=NOW(),last_error_code=VALUES(last_error_code)', [$connection, $resource, $state, $code === null ? 1 : 0, $code, $code === null ? 1 : 0]);
    }

    public function evidence(int $connection): array
    {
        if (! $this->ready()) {
            return [];
        }

        return array_column($this->rows('SELECT resource,state,last_verified_at,last_attempt_at,last_error_code FROM marketplace_api_capabilities WHERE connection_id=?', [$connection]), null, 'resource');
    }

    public static function scope(string $platform, array $config): string
    {
        return hash('sha256', $platform.':'.IntegrationPolicy::environment($platform, $config).':'.($config['partner_id'] ?? $config['app_key'] ?? ''));
    }

    public function remainingCooldown(string $scope): int
    {
        if (! $this->ready()) {
            return 0;
        }

        return (int) $this->value('SELECT GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),next_allowed_at)) FROM marketplace_platform_cooldowns WHERE scope_hash=?', [$scope]);
    }

    public function cooldown(string $scope, int $seconds): void
    {
        if (! $this->ready()) {
            return;
        }
        $this->run('INSERT INTO marketplace_platform_cooldowns(scope_hash,next_allowed_at) VALUES (?,DATE_ADD(NOW(),INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE next_allowed_at=GREATEST(next_allowed_at,VALUES(next_allowed_at))', [$scope, $seconds]);
    }
}
