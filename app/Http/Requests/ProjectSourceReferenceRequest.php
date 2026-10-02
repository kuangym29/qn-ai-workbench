<?php

namespace App\Http\Requests;

use App\Enums\SourceRole;
use Illuminate\Validation\Rule;

/**
 * DEV-W07 — Project 级来源引用写入（content_ledger / closing_line_registry /
 * navigation_index）。Item 级角色在这条路径上一律 422。
 */
class ProjectSourceReferenceRequest extends SourceReferenceRequest
{
    protected function itemScoped(): bool
    {
        return false;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'role' => [
                $creating ? 'required' : 'sometimes',
                'required',
                Rule::enum(SourceRole::class),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }
                    // 复用 Enum 自身的 scope 判定，不另建硬编码角色清单。
                    if (SourceRole::tryFrom($value)?->isContentItemScoped() !== false) {
                        $fail('This role belongs to a content item and cannot be managed at project level.');
                    }
                },
            ],
            'source_path' => [
                $creating ? 'required' : 'sometimes',
                'required',
                'string',
                'max:1000',
                fn (string $attribute, mixed $value, \Closure $fail) => $this->validateRelativePath($attribute, $value, $fail),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
            'id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'content_item_id' => ['prohibited'],
            'authority' => ['prohibited'],
        ];
    }
}
