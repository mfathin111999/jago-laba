<?php

namespace App\Enums;

/** Normalised state of a marketplace withdrawal / payout. */
enum PayoutStatus: string
{
    case Succeeded = 'succeeded';
    case Processing = 'processing';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
