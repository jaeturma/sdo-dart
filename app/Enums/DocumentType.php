<?php

namespace App\Enums;

enum DocumentType: string
{
    case Outgoing = 'outgoing';
    case Incoming = 'incoming';
}
