<?php

namespace App\Http\Requests;

use App\Enums\TypeStructure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation de format uniquement : les règles métier sont dans StructureService. */
class CreerStructureRequest extends FormRequest
{
    public const FORMAT_CODE = '/^[A-Z0-9][A-Z0-9_-]{1,29}$/';

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'code' => is_string($this->code) ? mb_strtoupper(trim($this->code)) : $this->code,
            'code_parent' => is_string($this->code_parent) ? mb_strtoupper(trim($this->code_parent)) : $this->code_parent,
            'type' => is_string($this->type) ? mb_strtoupper(trim($this->type)) : $this->type,
        ], fn ($v) => $v !== null));
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:'.self::FORMAT_CODE],
            'sigle' => ['required', 'string', 'max:30'],
            'libelle' => ['required', 'string', 'min:2', 'max:255'],
            'type' => ['required', 'string', Rule::in(TypeStructure::valeurs())],
            'code_parent' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Le code doit contenir de 2 à 30 caractères parmi A à Z, 0 à 9, tiret et souligné, et commencer par une lettre ou un chiffre.',
            'type.in' => 'Le type doit valoir : '.implode(', ', TypeStructure::valeurs()).'.',
        ];
    }
}
