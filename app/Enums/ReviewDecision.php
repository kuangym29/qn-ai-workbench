<?php

namespace App\Enums;

/**
 * 单次审核决策。审核中心不是审批系统：只有「通过」与「退回修改」。
 */
enum ReviewDecision: string
{
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
}
