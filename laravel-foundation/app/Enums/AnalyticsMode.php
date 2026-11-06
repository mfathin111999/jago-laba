<?php

namespace App\Enums;

/** Which orders an analytics report counts: everything, or only income the platform has settled. */
enum AnalyticsMode: string
{
    case All = 'all';
    case Settled = 'settled';
}
