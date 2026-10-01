<?php

namespace App\Enums;

enum VideoStatus: string
{
    case NotApplicable = 'not_applicable';
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
}
