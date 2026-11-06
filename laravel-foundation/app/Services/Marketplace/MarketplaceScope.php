<?php

namespace App\Services\Marketplace;

use App\Auth\AuthenticatedUser;
use App\Authorization\RequestContext;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Who is acting and for which company, for the marketplace services. Replaces the legacy `$_SESSION` keys
 * (company_key, user_id, username, role): an HTTP request reads the company from RequestContext and the person from the
 * signed-in user; queued jobs and commands state both explicitly with run() / forCompany().
 *
 * Registered as a scoped singleton (AppServiceProvider): one instance per request and per queued job.
 */
final class MarketplaceScope
{
    private const DEFAULT_COMPANY = 'LOCAL-COMPANY';

    private ?string $company = null;

    /** @var array{id: string, username: string, role: string}|null */
    private ?array $actor = null;

    public function __construct(
        private readonly RequestContext $context,
        private readonly DatabaseManager $database,
    ) {}

    public function companyKey(): string
    {
        $candidate = $this->company ?? $this->context->companyKey() ?? config('jago.company_key') ?: self::DEFAULT_COMPANY;
        $key = trim((string) $candidate);

        return $key !== '' ? substr($key, 0, 120) : self::DEFAULT_COMPANY;
    }

    public function userId(): string
    {
        return $this->actor()['id'];
    }

    public function username(): string
    {
        return $this->actor()['username'];
    }

    public function role(): string
    {
        return $this->actor()['role'];
    }

    /** Runs $callback as $user inside $companyKey (jobs, commands, and calls that must not depend on the request). */
    public function run(AuthenticatedUser $user, string $companyKey, callable $callback): mixed
    {
        if (trim($companyKey) === '') {
            throw new DomainException('Company context wajib tersedia.');
        }

        return $this->within($companyKey, [
            'id' => (string) $user->getAuthIdentifier(),
            'username' => $user->getUsername(),
            'role' => $user->getRole() ?: 'ROLE-OWNER_SUPER_ADMIN',
        ], $callback);
    }

    /** Runs $callback for $companyKey only (read paths that have no acting person). */
    public function forCompany(string $companyKey, callable $callback): mixed
    {
        return $this->within($companyKey, ['id' => '', 'username' => '', 'role' => ''], $callback);
    }

    /**
     * The store row when it belongs to the active company and is not archived.
     *
     * @return array<string, mixed>
     */
    public function assertStoreAccess(string $storeId): array
    {
        $storeId = trim($storeId);
        if ($storeId === '') {
            throw new InvalidArgumentException('Toko marketplace wajib dipilih.');
        }

        $store = $this->database->connection()->table('marketplace_stores')
            ->where('id', $storeId)->where('company_key', $this->companyKey())->whereNull('archived_at')
            ->first();
        if ($store === null) {
            throw new DomainException('Toko marketplace tidak ditemukan dalam perusahaan aktif.');
        }

        return (array) $store;
    }

    /** @return list<string> */
    public function accessibleStoreIds(bool $includeDisconnected = true): array
    {
        $query = $this->database->connection()->table('marketplace_stores')
            ->where('company_key', $this->companyKey())->whereNull('archived_at');
        if (! $includeDisconnected) {
            $query->where('authorization_status', '<>', 'disconnected');
        }

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** @return array{id: string, username: string, role: string} */
    private function actor(): array
    {
        if ($this->actor !== null) {
            return $this->actor;
        }
        $user = Auth::guard('web')->user();
        if ($user instanceof AuthenticatedUser) {
            return ['id' => (string) $user->getAuthIdentifier(), 'username' => $user->getUsername(), 'role' => (string) $user->getRole()];
        }

        return ['id' => '', 'username' => '', 'role' => ''];
    }

    /** @param array{id: string, username: string, role: string} $actor */
    private function within(string $companyKey, array $actor, callable $callback): mixed
    {
        [$previousCompany, $previousActor] = [$this->company, $this->actor];
        $this->company = $companyKey;
        $this->actor = $actor;

        try {
            return $callback();
        } finally {
            [$this->company, $this->actor] = [$previousCompany, $previousActor];
        }
    }
}
