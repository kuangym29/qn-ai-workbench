<?php

namespace App\Enums;

enum SourceRole: string
{
    case FinalImageCopy = 'final_image_copy';
    case SourceScript = 'source_script';
    case ContentLedger = 'content_ledger';
    case ClosingLineRegistry = 'closing_line_registry';
    case NavigationIndex = 'navigation_index';

    public function defaultAuthority(): SourceAuthority
    {
        return match ($this) {
            self::FinalImageCopy => SourceAuthority::Authoritative,
            self::SourceScript => SourceAuthority::Evidence,
            self::ContentLedger, self::NavigationIndex => SourceAuthority::Index,
            self::ClosingLineRegistry => SourceAuthority::Reference,
        };
    }

    public function isContentItemScoped(): bool
    {
        return match ($this) {
            self::FinalImageCopy, self::SourceScript => true,
            self::ContentLedger, self::ClosingLineRegistry, self::NavigationIndex => false,
        };
    }
}
