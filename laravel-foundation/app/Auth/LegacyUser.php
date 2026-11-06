<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\Access\Authorizable;
use JsonSerializable;

final class LegacyUser implements Authenticatable, AuthorizableContract, JsonSerializable
{
    use Authorizable;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(private readonly array $attributes) {}

    public function getAuthIdentifierName(): string
    {
        return 'auth_identifier';
    }

    public function getAuthIdentifier(): string
    {
        return (string) ($this->attributes['auth_identifier'] ?? '');
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) ($this->attributes['password_hash'] ?? '');
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // Legacy user tables do not expose a remember-token column.
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    public function getLegacyUserId(): string
    {
        return (string) ($this->attributes['id'] ?? '');
    }

    public function getSource(): string
    {
        return (string) ($this->attributes['source'] ?? '');
    }

    public function getUsername(): string
    {
        return (string) ($this->attributes['username'] ?? '');
    }

    public function getDisplayName(): string
    {
        return (string) ($this->attributes['name'] ?? $this->getUsername());
    }

    public function getRole(): string
    {
        return (string) ($this->attributes['role_id'] ?? 'ROLE-USER');
    }

    public function getCompanyKey(): ?string
    {
        foreach (['company_key', 'company_id'] as $key) {
            $value = trim((string) ($this->attributes[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function getWorkspace(): ?string
    {
        $workspace = strtolower(trim((string) ($this->attributes['workspace'] ?? '')));

        return $workspace !== '' ? $workspace : null;
    }

    public function getStoreId(): ?string
    {
        $storeId = trim((string) ($this->attributes['store_id'] ?? ''));

        return $storeId !== '' ? $storeId : null;
    }

    /**
     * @return list<string>
     */
    public function legacyKeyCandidates(): array
    {
        $keys = [
            $this->getLegacyUserId(),
            $this->getAuthIdentifier(),
        ];

        if ($this->getSource() === 'auth_users_v2' && $this->getLegacyUserId() !== '') {
            $keys[] = 'AUTHV2-'.$this->getLegacyUserId();
        }

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
    }

    /**
     * Apply context carried by the old server-side session without replacing
     * the role and password hash read from the legacy database row.
     *
     * @param  array<string, mixed>  $context
     */
    public function withLegacyContext(array $context): self
    {
        $attributes = $this->attributes;

        foreach (['company_key', 'company_id', 'workspace', 'store_id'] as $key) {
            if (in_array($key, ['company_key', 'company_id'], true) && $this->getCompanyKey() !== null) {
                continue;
            }
            if (isset($context[$key]) && is_scalar($context[$key]) && trim((string) $context[$key]) !== '') {
                $attributes[$key] = (string) $context[$key];
            }
        }

        return new self($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_intersect_key($this->attributes, array_flip([
            'id', 'source', 'username', 'name', 'role_id', 'status',
            'company_key', 'company_id', 'workspace', 'store_id',
        ]));
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
