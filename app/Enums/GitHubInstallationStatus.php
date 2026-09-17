<?php

namespace App\Enums;

enum GitHubInstallationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';
}
