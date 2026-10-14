<?php

namespace App\Enums;

/**
 * 审核请求状态。只有三种，不做多级审批 / 终审 / 转交。
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
}
