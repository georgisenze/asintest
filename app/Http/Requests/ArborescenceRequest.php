<?php

namespace App\Http\Requests;

/** F8 : inclure_inactives (booléen, défaut false), date facultative (mêmes règles que F9). */
class ArborescenceRequest extends EtatStructureRequest
{
    protected bool $dateObligatoire = false;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $brut = $this->query('inclure_inactives');
        if (is_string($brut)) {
            $booleen = filter_var($brut, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($booleen !== null) {
                $this->merge(['inclure_inactives' => $booleen]);
            }
        }
    }

    public function rules(): array
    {
        return parent::rules() + [
            'inclure_inactives' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'inclure_inactives.boolean' => 'Le paramètre inclure_inactives doit valoir true, false, 1 ou 0.',
        ];
    }

    public function inclureInactives(): bool
    {
        return (bool) $this->input('inclure_inactives', false);
    }
}
