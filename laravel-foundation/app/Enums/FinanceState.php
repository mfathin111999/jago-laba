<?php

namespace App\Enums;

/** How certain an order's income is: confirmed by the platform, estimated, or not known yet. */
enum FinanceState: string
{
    case Final = 'final';
    case Estimated = 'estimated';
    case Unknown = 'unknown';
}
