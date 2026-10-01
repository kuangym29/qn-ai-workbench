<?php

namespace App\Http\Requests;

use App\Enums\CopyStatus;
use App\Support\ProjectContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(ProjectContext::class)->assertCurrent($this, $this->route('project'));

        return true;
    }

    public function rules(): array
    {
        return [
            'title' => [$this->isMethod('post') ? 'required' : 'sometimes', 'required', 'string', 'max:200'],
            'copy_status' => ['sometimes', 'required', Rule::enum(CopyStatus::class)],
            'project_id' => ['prohibited'],
            'content_column_id' => ['prohibited'],
            'topic_id' => ['prohibited'],
        ];
    }
}
