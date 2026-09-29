<?php

namespace App\Enums;

enum RoutingAction: string
{
    // Standard DepEd routing actions
    case AppropriateAction         = 'appropriate_action';
    case Assistance                = 'assistance';
    case Comment                   = 'comment';
    case Compliance                = 'compliance';
    case Consideration             = 'consideration';
    case Dissemination             = 'dissemination';
    case DraftReply                = 'draft_reply';
    case InboxIndorse              = 'inbox_indorse';
    case Information               = 'information';
    case PleaseFacilitate          = 'please_facilitate';
    case PleaseHandle              = 'please_handle';
    case PleaseSeeMe               = 'please_see_me';
    case PleaseCoordinate          = 'please_coordinate';
    case PleaseRepresentMe         = 'please_represent_me';
    case PleaseAttend              = 'please_attend';
    case Recommendation            = 'recommendation';
    case Verification              = 'verification';
    case SignatureInitialNotation  = 'signature_initial_notation';
    case InitialIfOkay             = 'initial_if_okay';
    case NoteAndReturn             = 'note_and_return';
    case File                      = 'file';
    case RushExpediteAction        = 'rush_expedite';
    case ForProcurement            = 'for_procurement';
    case Others                    = 'others';

    // Legacy cases kept for backward compatibility with existing records
    case ForAction        = 'for_action';
    case Transmittal      = 'transmittal';
    case Attendance       = 'attendance';
    case DraftLetter      = 'draft_letter';
    case Validation       = 'validation';
    case DraftIndorsement = 'draft_indorsement';
}
