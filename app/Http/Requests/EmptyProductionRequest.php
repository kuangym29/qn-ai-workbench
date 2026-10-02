<?php

namespace App\Http\Requests;

class EmptyProductionRequest extends ProductionApiRequest
{
    public function rules(): array
    {
        return $this->prohibitExcept([]);
    }
}
