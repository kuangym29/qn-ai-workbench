<?php

namespace App\Http\Requests;

use App\Enums\AssetRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppendAssetVersionRequest extends FormRequest
{
    private mixed $rawStoragePath = null;

    protected function prepareForValidation(): void
    {
        $this->rawStoragePath = $this->input('storage_path');
        if (! is_string($this->rawStoragePath)) {
            return;
        }

        $path = str_replace('\\', '/', trim($this->rawStoragePath));
        $path = preg_replace('~/+~', '/', $path);
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        $this->merge(['storage_path' => trim($path, '/')]);
    }

    public function rules(): array
    {
        $allowed = [
            'content_page_id', 'role', 'storage_disk', 'storage_path', 'original_name',
            'mime_type', 'size_bytes', 'width', 'height', 'note',
        ];
        $rules = [
            'content_page_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', Rule::enum(AssetRole::class)],
            'storage_disk' => ['required', 'string', 'max:80', 'regex:/\A[A-Za-z0-9._-]+\z/D'],
            'storage_path' => ['required', 'string', 'max:640', function (string $attribute, mixed $value, $fail): void {
                $raw = $this->rawStoragePath;
                if (! is_string($raw) || preg_match('~\A(?:[A-Za-z]:|[/\\\\])~', trim($raw)) ||
                    preg_match('~(?:\A|/)\.\.(?:/|\z)~', (string) $value)) {
                    $fail('Storage path must be a relative path without parent segments.');
                }
            }],
            'original_name' => ['required', 'string', 'max:255', 'not_in:.,..', 'regex:/\A[^\/\\\\]+\z/D'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'size_bytes' => ['nullable', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string'],
        ];
        foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
