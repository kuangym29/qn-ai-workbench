<?php

namespace App\Enums;

enum PageType: string
{
    case Cover = 'cover';
    case Content = 'content';
    case ColumnClosing = 'column_closing';
    case FixedBackCover = 'fixed_back_cover';
}
