<?php

namespace App\Services;

use App\Models\Structure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * F6 : structures modifiées strictement après une date (inactives incluses),
 * triées par (updated_at, code), paginées par jeu de clés (pas d'OFFSET).
 *
 * updated_at est stocké à la seconde : l'horodatage serveur renvoyé est donc
 * arrondi à la seconde inférieure moins une marge de recouvrement. Le client
 * peut recevoir deux fois une même structure, jamais en manquer une : il doit
 * dédoublonner par code et version.
 */
class SynchronisationService
{
    public const MARGE_SECONDES = 5;

    public const LIMITE_DEFAUT = 500;

    /**
     * @return array{structures: Collection<int, Structure>, complet: bool, horodatage_serveur: CarbonImmutable}
     */
    public function modificationsDepuis(CarbonImmutable $depuis, int $limite = self::LIMITE_DEFAUT, ?string $apresCode = null): array
    {
        // Capturé avant la lecture : tout ce qui sera écrit ensuite sera vu au prochain appel.
        $horodatage = CarbonImmutable::now('UTC')->startOfSecond()->subSeconds(self::MARGE_SECONDES);

        $requete = Structure::query()->with('parent');

        if ($apresCode === null) {
            $requete->where('updated_at', '>', $depuis);
        } else {
            $requete->where(fn ($q) => $q->where('updated_at', '>', $depuis)
                ->orWhere(fn ($e) => $e->where('updated_at', '=', $depuis)->where('code', '>', $apresCode)));
        }

        $lignes = $requete->orderBy('updated_at')->orderBy('code')->limit($limite + 1)->get();

        return [
            'structures' => $lignes->take($limite)->values(),
            'complet' => $lignes->count() <= $limite,
            'horodatage_serveur' => $horodatage,
        ];
    }
}
