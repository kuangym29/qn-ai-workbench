<?php

namespace App\Http\Requests;

use App\Enums\PageType;
use Illuminate\Validation\Rule;

class StoreContentPageRequest extends PageApiRequest
{
    public function rules(): array
    {
        return [
            'page_no' => ['required', 'integer', 'min:1'],
            'page_type' => ['required', Rule::enum(PageType::class)],
            'project_id' => ['prohibited'],
            'content_item_id' => ['prohibited'],
            'topic_id' => ['prohibited'],
            'content_column_id' => ['prohibited'],
        ];
    }
}
