<?php

namespace App\Services;

use App\Enums\TypeStructure;
use App\Models\Structure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * F3 : recherche paginée, partagée par l'API et l'écran Blade (F10).
 *
 * - q porte sur code, sigle et libellé, insensible à la casse et aux accents
 *   (f_unaccent(lower(...)), index trigram) ; % et _ saisis sont pris littéralement ;
 * - code_parent : filles directes ; code_ministere : le ministère et toute sa descendance ;
 * - un code_parent ou code_ministere inconnu donne simplement un résultat vide.
 */
class RechercheStructureService
{
    /** tri public => colonne */
    private const TRIS = [
        'code' => 'code',
        'sigle' => 'sigle',
        'libelle' => 'libelle',
        'type' => 'type',
        'statut' => 'statut',
        'cree_le' => 'created_at',
        'modifie_le' => 'updated_at',
    ];

    public const PAR_PAGE_DEFAUT = 20;

    /** @return list<string> */
    public static function trisAutorises(): array
    {
        $cles = array_keys(self::TRIS);

        return array_merge($cles, array_map(fn ($c) => '-'.$c, $cles));
    }

    public function rechercher(array $criteres): LengthAwarePaginator
    {
        $requete = Structure::query()->with('parent');

        $this->filtrerTexte($requete, $criteres['q'] ?? null);

        if (! empty($criteres['type'])) {
            $requete->where('type', $criteres['type']);
        }
        if (! empty($criteres['statut'])) {
            $requete->where('statut', $criteres['statut']);
        }
        if (! empty($criteres['code_parent'])) {
            $requete->whereIn('parent_id', Structure::select('id')->where('code', $criteres['code_parent']));
        }
        if (! empty($criteres['code_ministere'])) {
            $requete->whereRaw(<<<'SQL'
                structures.id IN (
                    WITH RECURSIVE descendance AS (
                        SELECT id FROM structures WHERE code = ? AND type = ?
                        UNION
                        SELECT s.id FROM structures s JOIN descendance d ON s.parent_id = d.id
                    )
                    SELECT id FROM descendance
                )
            SQL, [$criteres['code_ministere'], TypeStructure::MINISTERE->value]);
        }

        $this->trier($requete, $criteres['tri'] ?? 'code');

        $parPage = (int) ($criteres['par_page'] ?? self::PAR_PAGE_DEFAUT);

        return $requete->paginate($parPage)->withQueryString();
    }

    private function filtrerTexte(Builder $requete, ?string $q): void
    {
        if ($q === null || trim($q) === '') {
            return;
        }

        $motif = '%'.addcslashes(trim($q), '\\%_').'%';

        $requete->where(function (Builder $w) use ($motif) {
            foreach (['code', 'sigle', 'libelle'] as $colonne) {
                $w->orWhereRaw("f_unaccent(lower({$colonne})) LIKE f_unaccent(lower(?))", [$motif]);
            }
        });
    }

    private function trier(Builder $requete, string $tri): void
    {
        $descendant = str_starts_with($tri, '-');
        $colonne = self::TRIS[ltrim($tri, '-')] ?? 'code';

        $requete->orderBy($colonne, $descendant ? 'desc' : 'asc')->orderBy('id');
    }
}
