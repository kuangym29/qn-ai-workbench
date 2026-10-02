<?php

namespace App\Http\Requests;

/**
 * DEV-W05 — 显式整链 restart（restart-with-current-copy）。
 *
 * 空 Body：不接受任何字段。该动作是 destructive reset，必须由用户主动调用，
 * 绝不在确认新 Revision 时自动触发。
 */
class RestartProductionWithCurrentCopyRequest extends ProductionApiRequest
{
    public function rules(): array
    {
        return $this->prohibitExcept([]);
    }
}
