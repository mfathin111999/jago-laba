<?php

namespace App\Enums;

/** State of a store's OAuth connection (marketplace_oauth_connections.status). */
enum ConnectionStatus: string
{
    case Verified = 'verified';
    case Reauthorize = 'reauthorize';
    case Disconnected = 'disconnected';
    case Pending = 'pending';
}
