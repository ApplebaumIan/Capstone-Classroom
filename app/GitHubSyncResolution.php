<?php

namespace App;

enum GitHubSyncResolution: string
{
    case Observed = 'observed';
    case Accepted = 'accepted';
    case Resynced = 'resynced';
}
