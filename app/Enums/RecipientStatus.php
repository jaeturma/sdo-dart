<?php

namespace App\Enums;

enum RecipientStatus: string
{
    case Pending      = 'pending';
    case Acknowledged = 'acknowledged';
    case Completed    = 'completed';
    case Cancelled    = 'cancelled';
}
