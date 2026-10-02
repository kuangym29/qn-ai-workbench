<?php

namespace App\Http\Requests;

use App\Enums\SourceRole;
use Illuminate\Validation\Rule;

/**
 * DEV-W07 — Item 级来源引用写入（final_image_copy / source_script）。
 * Project 级角色在这条路径上一律 422。
 */
class ItemSourceReferenceRequest extends SourceReferenceRequest
{
    protected function itemScoped(): bool
    {
        return true;
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
                    // 复用 Enum 的 isContentItemScoped()，不另建硬编码角色清单。
                    if (SourceRole::tryFrom($value)?->isContentItemScoped() !== true) {
                        $fail('This role is project scoped and cannot be attached to a content item.');
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
