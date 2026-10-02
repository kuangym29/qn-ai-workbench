<?php

namespace App\Http\Requests;

use App\Enums\ArtworkStatus;
use Illuminate\Validation\Rule;

class UpdateProductionTaskRequest extends ProductionApiRequest
{
    public function rules(): array
    {
        return [
            'artwork_status' => ['required', Rule::enum(ArtworkStatus::class)],
            ...$this->prohibitExcept(['artwork_status']),
        ];
    }
}
