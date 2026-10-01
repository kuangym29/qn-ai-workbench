<?php

namespace App\Http\Requests;

use App\Support\ProjectContext;
use Illuminate\Foundation\Http\FormRequest;

class TopicRequest extends FormRequest
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
            'description' => ['sometimes', 'nullable', 'string'],
            'project_id' => ['prohibited'],
            'content_column_id' => ['prohibited'],
        ];
    }
}
