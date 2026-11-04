<?php

namespace App\Services;

use App\Enums\StatutStructure;
use App\Enums\TypeStructure;
use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * F8 : arborescence complète d'un ministère, actuelle ou à une date passée.
 * Les nœuds sont lus en une seule requête récursive puis assemblés en PHP.
 */
class ArborescenceService
{
    public function __construct(
        private readonly StructureService $structures,
        private readonly EtatADateService $etats,
    ) {}

    /**
     * @return array{arbre: array, statistiques: array}
     */
    public function arborescence(string $code, ?CarbonImmutable $date = null, bool $inclureInactives = false): array
    {
        if ($date === null) {
            $ministere = $this->structures->trouverParCode($code);
            $this->verifierMinistere($ministere->code, $ministere->type->value);
            $noeuds = $this->noeudsActuels($ministere->id, $inclureInactives);
        } else {
            $racine = $this->etats->etatStructure($code, $date)['etat']; // 404 si absente à la date
            $this->verifierMinistere($racine['code'], $racine['type']);
            $noeuds = $this->etats->noeudsArbre($racine['code'], $date, $inclureInactives);
        }

        return [
            'arbre' => $this->assembler($noeuds),
            'statistiques' => $this->statistiques($noeuds),
        ];
    }

    /**
     * 422 et non 409 : la donnée n'interdit aucune action, c'est la requête qui
     * désigne une structure qui n'est pas un ministère.
     */
    private function verifierMinistere(string $code, string $type): void
    {
        if ($type !== TypeStructure::MINISTERE->value) {
            throw ApiException::invalide('STRUCTURE_NON_MINISTERE',
                "La structure {$code} est de type {$type} : l'arborescence n'est disponible que pour une structure de type MINISTERE.",
                ['code' => $code, 'type' => $type]);
        }
    }

    /**
     * Filtrer les inactives dans la partie récursive exclut aussi leurs
     * descendants : une branche inactive disparaît entièrement.
     */
    private function noeudsActuels(int $idRacine, bool $inclureInactives): array
    {
        $filtreStatut = $inclureInactives ? '' : "AND s.statut = 'ACTIVE'";

        $lignes = DB::select(<<<SQL
            WITH RECURSIVE arbre AS (
                SELECT s.id, s.code, s.sigle, s.libelle, s.type, s.statut, s.parent_id, 0 AS profondeur
                FROM structures s WHERE s.id = ?
                UNION ALL
                SELECT s.id, s.code, s.sigle, s.libelle, s.type, s.statut, s.parent_id, a.profondeur + 1
                FROM structures s
                JOIN arbre a ON s.parent_id = a.id
                WHERE a.profondeur < 50 {$filtreStatut}
            )
            SELECT a.code, a.sigle, a.libelle, a.type, a.statut, p.code AS code_parent, a.profondeur
            FROM arbre a LEFT JOIN structures p ON p.id = a.parent_id
            ORDER BY a.profondeur, a.code
        SQL, [$idRacine]);

        return array_map(fn ($l) => [
            'code' => $l->code,
            'sigle' => $l->sigle,
            'libelle' => $l->libelle,
            'type' => $l->type,
            'statut' => $l->statut,
            'code_parent' => $l->code_parent,
            'profondeur' => (int) $l->profondeur,
        ], $lignes);
    }

    /** Nœuds triés par profondeur : le premier est la racine. */
    private function assembler(array $noeuds): array
    {
        $parParent = [];
        foreach ($noeuds as $noeud) {
            $parParent[$noeud['code_parent'] ?? ''][] = $noeud;
        }

        $construire = function (array $noeud) use (&$construire, $parParent): array {
            $filles = $parParent[$noeud['code']] ?? [];
            usort($filles, fn ($a, $b) => strcmp($a['code'], $b['code']));

            return [
                'code' => $noeud['code'],
                'sigle' => $noeud['sigle'],
                'libelle' => $noeud['libelle'],
                'type' => $noeud['type'],
                'statut' => $noeud['statut'],
                'filles' => array_map($construire, $filles),
            ];
        };

        return $construire($noeuds[0]);
    }

    private function statistiques(array $noeuds): array
    {
        $parType = array_fill_keys(TypeStructure::valeurs(), 0);
        $actives = 0;

        foreach ($noeuds as $noeud) {
            $parType[$noeud['type']]++;
            $actives += $noeud['statut'] === StatutStructure::ACTIVE->value ? 1 : 0;
        }

        return [
            'total' => count($noeuds),
            'actives' => $actives,
            'inactives' => count($noeuds) - $actives,
            'par_type' => $parType,
            'profondeur_max' => $noeuds === [] ? 0 : max(array_column($noeuds, 'profondeur')),
        ];
    }
}
