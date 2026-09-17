<?php

namespace App\Enums;

enum GitHubSyncResolution: string
{
    case Observed = 'observed';
    case Accepted = 'accepted';
    case Resynced = 'resynced';
}
