<?php

namespace App\Enums;

/** Stock level of a variant against its configured minimum. */
enum StockStatus: string
{
    case Safe = 'safe';
    case Low = 'low';
    case Out = 'out';
    case Unknown = 'unknown';
    case Unconfigured = 'unconfigured';
}
