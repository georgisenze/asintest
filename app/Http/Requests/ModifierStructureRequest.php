<?php

namespace App\Http\Requests;

use App\Enums\TypeStructure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModifierStructureRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['code', 'code_parent', 'type'] as $champ) {
            if (is_string($this->input($champ))) {
                $this->merge([$champ => mb_strtoupper(trim($this->input($champ)))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string'],
            'sigle' => ['sometimes', 'required', 'string', 'max:30'],
            'libelle' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'type' => ['sometimes', 'required', 'string', Rule::in(TypeStructure::valeurs())],
            'code_parent' => ['sometimes', 'nullable', 'string', 'max:30'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'motif' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (array_intersect(array_keys($this->all()), ['sigle', 'libelle', 'type', 'code_parent']) === []) {
                $validator->errors()->add('modification', 'Au moins un champ parmi sigle, libelle, type, code_parent doit être fourni.');
            }
        }];
    }

    public function messages(): array
    {
        return ['type.in' => 'Le type doit valoir : '.implode(', ', TypeStructure::valeurs()).'.'];
    }
}
