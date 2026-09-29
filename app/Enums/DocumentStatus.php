<?php

namespace App\Enums;

enum DocumentStatus: string
{
    // Outgoing statuses
    case Draft                      = 'draft';
    case Submitted                  = 'submitted';
    case PendingUnitHeadReview      = 'pending_unit_head_review';
    case PendingDivisionChiefReview = 'pending_division_chief_review';
    case PendingArdReview          = 'pending_ard_review';
    case PendingRdReview           = 'pending_rd_review';
    case Approved                  = 'approved';
    case ForComment                = 'for_comment';
    case ReturnedForRevision       = 'returned_for_revision';
    case Rejected                  = 'rejected';
    case PendingSignature          = 'pending_signature';
    case Signed                    = 'signed';
    case Released                  = 'released';

    // Incoming statuses
    case Received       = 'received';
    case PendingRouting = 'pending_routing';
    case Routed         = 'routed';
    case Completed      = 'completed';
    case Archived       = 'archived';
}
