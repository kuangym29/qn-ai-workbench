<?php

namespace App\Enums;

enum PublishStatus: string
{
    case Unpublished = 'unpublished';
    case Scheduled = 'scheduled';
    case Published = 'published';
}
