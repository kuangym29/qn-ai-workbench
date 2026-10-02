<?php

namespace App\Http\Requests;

class AppendPageDraftRequest extends PageApiRequest
{
    private const FIELDS = [
        'column_label', 'cover_title', 'cover_subtitle', 'page_title',
        'page_small_text', 'closing_line', 'note',
    ];

    public function rules(): array
    {
        $rules = [];
        foreach (self::FIELDS as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string'];
        }
        foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
