<?php

namespace App\Services\V2;

use App\Enums\ImageBatchUnitStatus;
use App\Models\ContentBranch;
use App\Models\ImageBatchUnitSetting;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * 来源变化处理（第一阶段只做 keep / regenerate）。
 *
 *   keep        更新来源 fingerprint，保留当前生产状态；
 *   regenerate  更新来源 fingerprint，并把状态置为 needs_revision。
 *
 * 不返回、也不允许前端伪造 source_changed —— 前端成功后必须重新 GET，以服务端为准。
 */
class ImageBatchSourceChangeService
{
    public function __construct(
        private readonly ImageBatchWorkspaceService $workspace = new ImageBatchWorkspaceService,
        private readonly ImageBatchSourceService $source = new ImageBatchSourceService,
    ) {}

    public function resolve(ContentBranch $branch, int $unitId, string $action): ImageBatchUnitSetting
    {
        $this->workspace->assertImageBatch($branch);

        if (! in_array($action, ['keep', 'regenerate'], true)) {
            throw new InvalidArgumentException('action 必须是 keep / regenerate。');
        }

        $draft = $this->workspace->ensureDraft($branch);

        $setting = $draft->unitSettings()->where('id', $unitId)->first();
        if ($setting === null) {
            throw new ModelNotFoundException('页面单元不存在。');
        }

        $page = $setting->contentPage;
        if ($page === null) {
            throw new InvalidArgumentException('页面单元关联的来源页已不存在。');
        }

        $setting->source_fingerprint = $this->source->fingerprint($page);

        if ($action === 'regenerate') {
            $setting->status = ImageBatchUnitStatus::NeedsRevision;
        }

        $setting->save();

        return $setting->refresh();
    }
}
