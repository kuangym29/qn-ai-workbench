<?php

namespace App\Enums;

enum SourceAuthority: string
{
    case Authoritative = 'authoritative';
    case Evidence = 'evidence';
    case Index = 'index';
    case Reference = 'reference';
}
