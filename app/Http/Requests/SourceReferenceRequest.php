<?php

namespace App\Http\Requests;

use App\Enums\SourceRole;
use App\Support\ProjectContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DEV-W07 — SourceReference 写入请求的公共部分。
 *
 * 两级作用域都由 URL 决定：Project 级路径的 content_item_id 恒为 null，Item 级路径的
 * content_item_id 由祖先链推导。客户端提交的归属键与 authority 一律 prohibited：
 * authority 必须由服务端按 SourceRole::defaultAuthority() 派生。
 */
abstract class SourceReferenceRequest extends FormRequest
{
    /** 是否为 Item 级作用域（Item 级请求覆写）。 */
    abstract protected function itemScoped(): bool;

    public function authorize(): bool
    {
        $project = $this->route('project');
        app(ProjectContext::class)->assertCurrent($this, $project);

        // Item 级请求必须逐层验证 Project → Column → Topic → ContentItem。
        if ($this->itemScoped()) {
            $project->contentColumns()->findOrFail((int) $this->route('column'))
                ->topics()->findOrFail((int) $this->route('topic'))
                ->contentItems()->findOrFail((int) $this->route('item'));
        }

        return true;
    }

    /**
     * 只接受 role / source_path / note。归属键与 authority 由服务端决定。
     *
     * @return array<string, array<int, string>>
     */
    protected function baseRules(bool $creating): array
    {
        return [
            'role' => [$creating ? 'required' : 'sometimes', 'required', Rule::enum(SourceRole::class)],
            'source_path' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'content_item_id' => ['prohibited'],
            'authority' => ['prohibited'],
        ];
    }

    /**
     * 路径安全校验：只接受品牌源根目录下的相对路径。
     *
     * 拒绝空串、绝对路径（Windows / Unix）、UNC 以及任何 `..` 逃逸片段；允许中文与空格。
     * 这里只做字符串层面的校验，**不访问文件系统**——工作台服务器并不拥有用户本机的
     * 品牌源目录，因此不做存在性检查。
     */
    protected function validateRelativePath(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $path = trim($value);
        if ($path === '') {
            $fail('The source path must be a non-empty relative path.');

            return;
        }

        // 统一分隔符后判断，避免 `..\..\` 绕过。
        $normalised = str_replace('\\', '/', $path);

        if (str_starts_with($normalised, '/')) {
            $fail('The source path must be relative to the brand source root, not an absolute path.');

            return;
        }

        if (preg_match('#^[A-Za-z]:/#', $normalised) === 1) {
            $fail('The source path must be relative to the brand source root, not an absolute path.');

            return;
        }

        // UNC：原始串以 \\ 或 // 开头
        if (str_starts_with($path, '\\\\') || str_starts_with($normalised, '//')) {
            $fail('The source path must be relative to the brand source root, not a UNC path.');

            return;
        }

        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '..') {
                $fail('The source path must not contain ".." path traversal segments.');

                return;
            }
        }

        if (trim($normalised, '/') === '') {
            $fail('The source path must be a non-empty relative path.');
        }
    }

    /**
     * 路径规范化：统一为 `/` 分隔，去掉重复分隔符与开头的 `./`。
     * 规范化后用于重复判定与落库，保证 `a\\b.md` 与 `a/b.md` 被视为同一条。
     */
    protected function normalisePath(string $value): string
    {
        $path = str_replace('\\', '/', trim($value));
        $path = (string) preg_replace('#/+#', '/', $path);

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return trim($path, '/');
    }
}
