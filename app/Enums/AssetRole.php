<?php

namespace App\Enums;

enum AssetRole: string
{
    case CleanMaster = 'clean_master';
    case CopyMaster = 'copy_master';
}
