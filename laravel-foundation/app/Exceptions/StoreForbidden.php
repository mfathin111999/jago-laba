<?php

namespace App\Exceptions;

/** The store is not part of the active company (or no longer active). HTTP 403, code STORE_FORBIDDEN. */
final class StoreForbidden extends ApiException
{
    public function __construct(string $message = 'Toko marketplace tidak ditemukan dalam perusahaan aktif.')
    {
        parent::__construct($message, 'STORE_FORBIDDEN', 403);
    }
}
