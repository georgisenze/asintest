<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * F9 : date obligatoire, ISO 8601, non future.
 * Formats acceptés : 2026-01-31 (fin de journée UTC) ou date-heure avec fuseau
 * (2026-01-31T10:00:00Z, 2026-01-31T10:00:00+01:00).
 */
class EtatStructureRequest extends FormRequest
{
    public const FORMAT_ISO = '/^(\d{4})-(\d{2})-(\d{2})(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}(?::?\d{2})?))?$/';

    protected bool $dateObligatoire = true;

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('date'))) {
            $this->merge(['date' => self::normaliserDate($this->query('date'))]);
        }
    }

    public function rules(): array
    {
        return [
            'date' => [$this->dateObligatoire ? 'required' : 'sometimes', 'string', 'max:40'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('date') || ! is_string($this->input('date'))) {
                return;
            }
            $message = self::erreurDate($this->input('date'));
            if ($message !== null) {
                $validator->errors()->add('date', $message);
            }
        }];
    }

    public function messages(): array
    {
        return ['date.required' => 'Le paramètre date est obligatoire (format ISO 8601, par exemple 2026-01-31T10:00:00Z).'];
    }

    /** Date demandée, en UTC, plafonnée à maintenant (cas d'une date du jour sans heure). */
    public function dateDemandee(): ?CarbonImmutable
    {
        $brute = $this->input('date');

        return is_string($brute) ? self::analyserDate($brute) : null;
    }

    /**
     * Dans une query string non encodée, le "+" du fuseau devient une espace :
     * on le rétablit avant validation.
     */
    public static function normaliserDate(string $date): string
    {
        return preg_replace('/(T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?) (\d{2}(?::?\d{2})?)$/', '$1+$2', trim($date));
    }

    public static function erreurDate(string $date): ?string
    {
        if (! preg_match(self::FORMAT_ISO, $date, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "La date {$date} n'est pas une date ISO 8601 valide (exemples : 2026-01-31 ou 2026-01-31T10:00:00Z ; une heure doit être suivie de son fuseau).";
        }

        $debut = strlen($date) === 10
            ? CarbonImmutable::parse($date, 'UTC')->startOfDay()
            : CarbonImmutable::parse($date)->utc();

        if ($debut->greaterThan(CarbonImmutable::now('UTC'))) {
            return "La date {$date} est dans le futur : seul l'état à une date passée ou présente peut être consulté.";
        }

        return null;
    }

    public static function analyserDate(string $date): CarbonImmutable
    {
        $maintenant = CarbonImmutable::now('UTC');

        if (strlen($date) === 10) {
            $finDeJournee = CarbonImmutable::parse($date, 'UTC')->endOfDay();

            return $finDeJournee->greaterThan($maintenant) ? $maintenant : $finDeJournee;
        }

        return CarbonImmutable::parse($date)->utc();
    }
}
