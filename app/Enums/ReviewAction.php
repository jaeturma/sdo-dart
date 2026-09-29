<?php

namespace App\Enums;

enum ReviewAction: string
{
    case ApprovedForwarded   = 'approved_forwarded';
    case ApprovedSigned      = 'approved_signed';
    case ForComment          = 'for_comment';
    case ReturnedForRevision = 'returned_for_revision';
    case Rejected            = 'rejected';
    case Resubmitted         = 'resubmitted';
    case Released            = 'released';
    case SignatureReverted   = 'signature_reverted';
    case Emailed             = 'emailed';
}
