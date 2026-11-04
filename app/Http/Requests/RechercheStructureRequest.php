<?php

namespace App\Http\Requests;

use App\Enums\StatutStructure;
use App\Enums\TypeStructure;
use App\Services\RechercheStructureService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** F3 : validation de format des critères de recherche (query string). */
class RechercheStructureRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(self::normaliser($this->query()));
    }

    public function rules(): array
    {
        return self::regles();
    }

    public function messages(): array
    {
        return self::messagesPersonnalises();
    }

    /** Mise en majuscules des critères codifiés (partagé avec l'écran web). */
    public static function normaliser(array $criteres): array
    {
        $normalises = [];
        foreach (['type', 'statut', 'code_parent', 'code_ministere'] as $champ) {
            if (isset($criteres[$champ]) && is_string($criteres[$champ])) {
                $normalises[$champ] = mb_strtoupper(trim($criteres[$champ]));
            }
        }
        if (isset($criteres['q']) && is_string($criteres['q'])) {
            $normalises['q'] = trim($criteres['q']);
        }

        return $normalises;
    }

    public static function regles(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'nullable', 'string', Rule::in(TypeStructure::valeurs())],
            'statut' => ['sometimes', 'nullable', 'string', Rule::in(StatutStructure::valeurs())],
            'code_parent' => ['sometimes', 'nullable', 'string', 'max:30'],
            'code_ministere' => ['sometimes', 'nullable', 'string', 'max:30'],
            'tri' => ['sometimes', 'nullable', 'string', Rule::in(RechercheStructureService::trisAutorises())],
            'par_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public static function messagesPersonnalises(): array
    {
        return [
            'type.in' => 'Le type doit valoir : '.implode(', ', TypeStructure::valeurs()).'.',
            'statut.in' => 'Le statut doit valoir : '.implode(', ', StatutStructure::valeurs()).'.',
            'tri.in' => 'Le tri doit valoir : '.implode(', ', RechercheStructureService::trisAutorises()).'.',
        ];
    }
}
