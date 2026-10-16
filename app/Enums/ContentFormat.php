<?php

namespace App\Enums;

enum ContentFormat: string
{
    case ImagePost = 'image_post';
    case Article = 'article';
    case Video = 'video';
}
