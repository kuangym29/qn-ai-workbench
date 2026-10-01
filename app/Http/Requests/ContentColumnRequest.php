<?php

namespace App\Http\Requests;

use App\Support\ProjectContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContentColumnRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(ProjectContext::class)->assertCurrent($this, $this->route('project'));

        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$required, 'string', 'max:120'],
            'slug' => [...$required, 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('content_columns', 'slug')
                    ->where('project_id', $this->route('project')?->id)
                    ->ignore($this->route('column'))],
            'description' => ['sometimes', 'nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'project_id' => ['prohibited'],
            'content_column_id' => ['prohibited'],
        ];
    }
}
