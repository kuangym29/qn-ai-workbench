<?php

namespace App\Http\Requests;

class ReorderPagesRequest extends PageApiRequest
{
    public function rules(): array
    {
        return [
            'page_ids' => ['required', 'array', 'min:1'],
            'page_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
