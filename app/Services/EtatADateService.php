<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\HistoriqueStructure;
use App\Models\Structure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * F9 : reconstruction de l'état passé à partir de historique_structures.
 * L'état d'une structure à une date D est l'instantané donnees_apres de sa
 * dernière ligne d'historique telle que effectue_le <= D.
 */
class EtatADateService
{
    public function __construct(private readonly StructureService $structures) {}

    /**
     * @return array{etat: array, parent: ?array, effectue_le: CarbonImmutable, action: string}
     */
    public function etatStructure(string $code, CarbonImmutable $date): array
    {
        $structure = $this->structures->trouverParCode($code);
        $version = $this->derniereVersion($structure, $date);

        if ($version === null) {
            $iso = $date->utc()->toIso8601ZuluString();
            $premiere = $structure->historiques()->orderBy('effectue_le')->orderBy('id')->value('effectue_le');

            throw ApiException::introuvable('STRUCTURE_INEXISTANTE_A_DATE',
                "La structure {$structure->code} n'existait pas encore au {$iso}.",
                ['code' => $structure->code, 'date' => $iso,
                    'existe_depuis' => $premiere ? CarbonImmutable::parse($premiere)->utc()->toIso8601ZuluString() : null]);
        }

        $etat = $version->donnees_apres;
        $parent = null;

        if (! empty($etat['code_parent'])) {
            $structureParent = Structure::where('code', $etat['code_parent'])->first();
            $parent = $structureParent ? $this->derniereVersion($structureParent, $date)?->donnees_apres : null;
        }

        return [
            'etat' => $etat,
            'parent' => $parent,
            'effectue_le' => $version->effectue_le,
            'action' => $version->action->value,
        ];
    }

    /**
     * Nœuds de l'arbre enraciné en $codeRacine tel qu'il était à $date,
     * en une seule requête : dernier instantané de chaque structure (DISTINCT ON)
     * puis parcours récursif par code_parent.
     *
     * @return list<array{code:string, sigle:string, libelle:string, type:string, statut:string, code_parent:?string, profondeur:int}>
     */
    public function noeudsArbre(string $codeRacine, CarbonImmutable $date, bool $inclureInactives): array
    {
        $filtreStatut = $inclureInactives ? '' : "AND e.d->>'statut' = 'ACTIVE'";

        $lignes = DB::select(<<<SQL
            WITH RECURSIVE etats AS (
                SELECT DISTINCT ON (h.structure_id) h.donnees_apres AS d
                FROM historique_structures h
                WHERE h.effectue_le <= ?
                ORDER BY h.structure_id, h.effectue_le DESC, h.id DESC
            ),
            arbre AS (
                SELECT e.d, 0 AS profondeur FROM etats e WHERE e.d->>'code' = ?
                UNION ALL
                SELECT e.d, a.profondeur + 1
                FROM etats e
                JOIN arbre a ON e.d->>'code_parent' = a.d->>'code'
                WHERE a.profondeur < 50 {$filtreStatut}
            )
            SELECT d, profondeur FROM arbre ORDER BY profondeur, d->>'code'
        SQL, [$date->utc()->format('Y-m-d H:i:s.uP'), $codeRacine]);

        return array_map(function ($ligne) {
            $d = json_decode($ligne->d, true);

            return [
                'code' => $d['code'],
                'sigle' => $d['sigle'],
                'libelle' => $d['libelle'],
                'type' => $d['type'],
                'statut' => $d['statut'],
                'code_parent' => $d['code_parent'] ?? null,
                'profondeur' => (int) $ligne->profondeur,
            ];
        }, $lignes);
    }

    private function derniereVersion(Structure $structure, CarbonImmutable $date): ?HistoriqueStructure
    {
        return $structure->historiques()
            ->where('effectue_le', '<=', $date->utc()->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('effectue_le')->orderByDesc('id')
            ->first();
    }
}
