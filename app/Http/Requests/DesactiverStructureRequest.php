<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DesactiverStructureRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'motif' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
