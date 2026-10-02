<?php

namespace App\Http\Requests;

use App\Enums\PageType;
use Illuminate\Validation\Rule;

class UpdateContentPageRequest extends PageApiRequest
{
    public function rules(): array
    {
        return [
            'page_type' => ['required', Rule::enum(PageType::class)],
            'page_no' => ['prohibited'],
            'project_id' => ['prohibited'],
            'content_item_id' => ['prohibited'],
            'topic_id' => ['prohibited'],
            'content_column_id' => ['prohibited'],
        ];
    }
}
