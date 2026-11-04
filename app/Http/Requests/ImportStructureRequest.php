<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportStructureRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // En multipart, les booléens arrivent en texte ("true", "1", "oui"...).
        if ($this->has('simulation') && is_string($this->input('simulation'))) {
            $valeur = mb_strtolower(trim($this->input('simulation')));
            $converti = match ($valeur) {
                'oui' => true,
                'non' => false,
                default => filter_var($valeur, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            };
            if ($converti !== null) {
                $this->merge(['simulation' => $converti]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'fichier' => ['required', 'file', 'extensions:csv,txt', 'mimes:csv,txt', 'max:2048'],
            'simulation' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'fichier.required' => 'Le fichier CSV est obligatoire (champ multipart "fichier").',
            'fichier.extensions' => 'Le fichier doit avoir l\'extension .csv ou .txt.',
            'fichier.mimes' => 'Le fichier doit être un fichier texte CSV.',
            'fichier.max' => 'Le fichier ne doit pas dépasser 2 Mo.',
            'simulation.boolean' => 'Le champ simulation doit valoir true ou false.',
        ];
    }
}
