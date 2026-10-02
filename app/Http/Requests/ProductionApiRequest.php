<?php

namespace App\Http\Requests;

use App\Support\ProjectContext;
use Illuminate\Foundation\Http\FormRequest;

abstract class ProductionApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        app(ProjectContext::class)->assertCurrent($this, $project);
        $project->contentColumns()->findOrFail((int) $this->route('column'))
            ->topics()->findOrFail((int) $this->route('topic'))
            ->contentItems()->findOrFail((int) $this->route('item'));

        return true;
    }

    protected function prohibitExcept(array $allowed): array
    {
        $rules = [];
        foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
