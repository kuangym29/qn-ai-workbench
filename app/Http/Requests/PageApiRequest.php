<?php

namespace App\Http\Requests;

use App\Models\ContentItem;
use App\Support\ProjectContext;
use Illuminate\Foundation\Http\FormRequest;

abstract class PageApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        app(ProjectContext::class)->assertCurrent($this, $project);
        $item = $project->contentColumns()->findOrFail((int) $this->route('column'))
            ->topics()->findOrFail((int) $this->route('topic'))
            ->contentItems()->findOrFail((int) $this->route('item'));
        $this->assertPageScope($item);

        return true;
    }

    protected function assertPageScope(ContentItem $item): void
    {
        if ($this->route('page') !== null) {
            $item->contentPages()->findOrFail((int) $this->route('page'));
        }
    }
}
