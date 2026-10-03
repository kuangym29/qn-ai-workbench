<?php

namespace App\Services\DuplicateCheck;

enum DuplicateField: string
{
    case ClosingLine = 'closing_line';
    case CoverTitle = 'cover_title';
    case CoverSubtitle = 'cover_subtitle';
    case PageTitle = 'page_title';
    case PageSmallText = 'page_small_text';

    /**
     * Initial overlap candidate thresholds.
     * These are uncalibrated initial references, not validated accuracy.
     */
    public function threshold(): float
    {
        return match ($this) {
            self::ClosingLine => 0.4,
            self::CoverTitle => 0.5,
            self::CoverSubtitle => 0.5,
            self::PageTitle => 0.6,
            self::PageSmallText => 0.5,
        };
    }
}
