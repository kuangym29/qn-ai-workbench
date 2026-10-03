<?php

namespace App\Http\Requests;

class AppendChannelAssetBindingRequest extends ProductionApiRequest
{
    public function rules(): array
    {
        return [
            'content_page_id' => ['required', 'integer', 'min:1'],
            'asset_version_id' => ['required', 'integer', 'min:1'],
            ...$this->prohibitExcept(['content_page_id', 'asset_version_id']),
        ];
    }
}
