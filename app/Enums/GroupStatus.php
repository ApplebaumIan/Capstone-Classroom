<?php

namespace App\Enums;

enum GroupStatus: string
{
    case Waiting = 'waiting';
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';
    case Missing = 'missing';
}
