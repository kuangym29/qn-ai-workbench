<?php

namespace App\Http\Requests;

use App\Support\ProjectContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($project = $this->route('project')) {
            app(ProjectContext::class)->assertCurrent($this, $project);
        }

        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$required, 'string', 'max:120'],
            'slug' => [...$required, 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('projects', 'slug')->ignore($this->route('project')?->id)],
            'description' => ['sometimes', 'nullable', 'string'],
            'project_id' => ['prohibited'],
        ];
    }
}
