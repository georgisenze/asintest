<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** F6 : depuis (ISO 8601 obligatoire, avec fuseau), limite, apres_code (page suivante). */
class SynchronisationRequest extends FormRequest
{
    /** Date-heure ISO 8601 avec fuseau : Z ou +hh:mm, fractions de seconde facultatives. */
    public const FORMAT_ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    protected function prepareForValidation(): void
    {
        // Un "+" non encodé dans l'URL arrive sous forme d'espace.
        if (is_string($this->query('depuis'))) {
            $this->merge(['depuis' => str_replace(' ', '+', trim($this->query('depuis')))]);
        }
        if (is_string($this->query('apres_code'))) {
            $this->merge(['apres_code' => mb_strtoupper(trim($this->query('apres_code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'depuis' => ['required', 'string', 'regex:'.self::FORMAT_ISO],
            'limite' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'apres_code' => ['sometimes', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'depuis.required' => 'Le paramètre depuis est obligatoire (date ISO 8601, par exemple 2026-01-31T10:00:00Z).',
            'depuis.regex' => 'Le paramètre depuis doit être une date ISO 8601 avec fuseau, par exemple 2026-01-31T10:00:00Z.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('depuis')) {
                return;
            }
            try {
                $depuis = CarbonImmutable::parse($this->input('depuis'));
            } catch (\Throwable) {
                $validator->errors()->add('depuis', 'Le paramètre depuis n\'est pas une date valide.');

                return;
            }
            if ($depuis->isAfter(CarbonImmutable::now('UTC'))) {
                $validator->errors()->add('depuis', 'Le paramètre depuis ne peut pas être dans le futur.');
            }
        }];
    }

    public function depuis(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('depuis'))->utc();
    }
}
