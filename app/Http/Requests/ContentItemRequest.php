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
            'copy_status' => $this->isMethod('post')
                ? ['missing']
                : ['sometimes', 'required', Rule::enum(CopyStatus::class), Rule::notIn([CopyStatus::Confirmed->value])],
            'project_id' => ['prohibited'],
            'content_column_id' => ['prohibited'],
            'topic_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'copy_status.not_in' => 'Use the copy confirmation endpoint to confirm content.',
        ];
    }
}
