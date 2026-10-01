<?php

namespace App\Enums;

enum CopyStatus: string
{
    case NotStarted = 'not_started';
    case Editing = 'editing';
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
}
