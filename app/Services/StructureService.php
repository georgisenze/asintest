<?php

namespace App\Services;

use App\Enums\ActionHistorique;
use App\Enums\StatutStructure;
use App\Enums\TypeStructure;
use App\Exceptions\ApiException;
use App\Models\HistoriqueStructure;
use App\Models\Structure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Écritures sur le référentiel. Chaque opération s'exécute dans une transaction
 * qui inclut l'écriture de l'historique (R6). Les lignes concernées sont
 * verrouillées (SELECT ... FOR UPDATE) pour éviter qu'une désactivation et une
 * création de fille concurrentes ne contournent R3 / R5.
 */
class StructureService
{
    public function __construct(private readonly HierarchieValidator $validateur) {}

    /**
     * F1. $donnees : code, sigle, libelle, type, code_parent (optionnel).
     */
    public function creer(array $donnees, ?User $auteur = null): Structure
    {
        return DB::transaction(function () use ($donnees, $auteur) {
            $code = self::normaliserCode($donnees['code']);
            $type = TypeStructure::from($donnees['type']);
            $sigle = trim($donnees['sigle']);

            if (Structure::where('code', $code)->exists()) {
                throw ApiException::conflit('CODE_EXISTANT',
                    "Une structure de code {$code} existe déjà.", ['code' => $code]);
            }

            $parent = $this->trouverParent($donnees['code_parent'] ?? null, $code);

            $this->validateur->verifierTypeEtParent($code, $type, $parent);
            $this->validateur->verifierParentActif($code, $parent);
            $this->validateur->verifierSigleUnique($code, $sigle, $parent?->id);

            $structure = Structure::create([
                'code' => $code,
                'sigle' => $sigle,
                'libelle' => trim($donnees['libelle']),
                'type' => $type,
                'parent_id' => $parent?->id,
                'statut' => StatutStructure::ACTIVE,
                'version' => 1,
                'cree_par' => $auteur?->id,
                'modifie_par' => $auteur?->id,
            ]);

            $this->historiser($structure, ActionHistorique::CREATION, null, $auteur);

            return $structure->load('parent');
        });
    }

    /**
     * F4. $donnees peut contenir : sigle, libelle, type, code_parent (null = racine), version.
     * Le code n'est jamais modifiable (R1).
     */
    public function modifier(Structure $structure, array $donnees, ?User $auteur = null, ?string $motif = null): Structure
    {
        return DB::transaction(function () use ($structure, $donnees, $auteur, $motif) {
            /** @var Structure $structure */
            $structure = Structure::whereKey($structure->id)->lockForUpdate()->firstOrFail();

            $this->validateur->verifierModifiable($structure);

            if (isset($donnees['version']) && (int) $donnees['version'] !== $structure->version) {
                throw ApiException::conflit('VERSION_OBSOLETE',
                    "La structure {$structure->code} a été modifiée entre-temps (version actuelle {$structure->version}, version fournie {$donnees['version']}). Rechargez-la avant de la modifier.",
                    ['code' => $structure->code, 'version_actuelle' => $structure->version, 'version_fournie' => (int) $donnees['version']]);
            }

            if (array_key_exists('code', $donnees) && self::normaliserCode((string) $donnees['code']) !== $structure->code) {
                throw ApiException::invalide('CODE_NON_MODIFIABLE',
                    "Le code de la structure {$structure->code} n'est pas modifiable.", ['code' => $structure->code]);
            }

            $avant = self::instantane($structure);

            $type = isset($donnees['type']) ? TypeStructure::from($donnees['type']) : $structure->type;
            $parent = array_key_exists('code_parent', $donnees)
                ? $this->trouverParent($donnees['code_parent'], $structure->code)
                : $structure->parent;
            $sigle = isset($donnees['sigle']) ? trim($donnees['sigle']) : $structure->sigle;

            $parentChange = ($parent?->id) !== $structure->parent_id;
            $typeChange = $type !== $structure->type;

            if ($parentChange || $typeChange) {
                $this->validateur->verifierTypeEtParent($structure->code, $type, $parent);
            }
            if ($parentChange) {
                $this->validateur->verifierParentActif($structure->code, $parent);
                $this->validateur->verifierAbsenceDeCycle($structure, $parent);
            }
            if ($typeChange) {
                $this->validateur->verifierFillesCompatibles($structure, $type);
            }
            if ($parentChange || mb_strtolower($sigle) !== mb_strtolower($structure->sigle)) {
                $this->validateur->verifierSigleUnique($structure->code, $sigle, $parent?->id, $structure->id);
            }

            $structure->fill([
                'sigle' => $sigle,
                'libelle' => isset($donnees['libelle']) ? trim($donnees['libelle']) : $structure->libelle,
                'type' => $type,
                'parent_id' => $parent?->id,
            ]);

            if (! $structure->isDirty()) {
                return $structure->load('parent'); // rien à changer : ni version ni historique.
            }

            $structure->version++;
            $structure->modifie_par = $auteur?->id;
            $structure->save();
            $structure->setRelation('parent', $parent);

            $this->historiser($structure, ActionHistorique::MODIFICATION, $avant, $auteur, $motif);

            return $structure;
        });
    }

    /** F5 (R5). */
    public function desactiver(Structure $structure, ?string $motif = null, ?User $auteur = null): Structure
    {
        return DB::transaction(function () use ($structure, $motif, $auteur) {
            /** @var Structure $structure */
            $structure = Structure::whereKey($structure->id)->lockForUpdate()->firstOrFail();

            $this->validateur->verifierDesactivable($structure);

            $avant = self::instantane($structure);

            $structure->fill([
                'statut' => StatutStructure::INACTIVE,
                'date_desactivation' => CarbonImmutable::now('UTC'),
                'motif_desactivation' => $motif,
                'modifie_par' => $auteur?->id,
            ]);
            $structure->version++;
            $structure->save();

            $this->historiser($structure, ActionHistorique::DESACTIVATION, $avant, $auteur, $motif);

            return $structure->load('parent');
        });
    }

    /** Recherche une structure par code ou lève 404 STRUCTURE_INTROUVABLE. */
    public function trouverParCode(string $code): Structure
    {
        $code = self::normaliserCode($code);

        return Structure::where('code', $code)->first()
            ?? throw ApiException::introuvable('STRUCTURE_INTROUVABLE',
                "La structure de code {$code} est introuvable.", ['code' => $code]);
    }

    /**
     * Instantané stocké dans l'historique : suffit à reconstruire l'état
     * d'une structure à n'importe quelle date (F9).
     */
    public static function instantane(Structure $structure): array
    {
        $codeParent = $structure->parent_id === null ? null
            : ($structure->relationLoaded('parent') && $structure->parent?->id === $structure->parent_id
                ? $structure->parent->code
                : Structure::whereKey($structure->parent_id)->value('code'));

        return [
            'code' => $structure->code,
            'sigle' => $structure->sigle,
            'libelle' => $structure->libelle,
            'type' => $structure->type->value,
            'statut' => $structure->statut->value,
            'code_parent' => $codeParent,
            'date_desactivation' => $structure->date_desactivation?->utc()->toIso8601ZuluString(),
            'motif_desactivation' => $structure->motif_desactivation,
        ];
    }

    public static function normaliserCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * Parent verrouillé en lecture partagée (FOR SHARE) : une désactivation
     * concurrente du parent attend la fin de cette transaction.
     */
    private function trouverParent(?string $codeParent, string $codeEnfant): ?Structure
    {
        if ($codeParent === null || trim($codeParent) === '') {
            return null;
        }

        $codeParent = self::normaliserCode($codeParent);

        return Structure::where('code', $codeParent)->sharedLock()->first()
            ?? throw ApiException::invalide('PARENT_INTROUVABLE',
                "La structure parente {$codeParent} indiquée pour {$codeEnfant} n'existe pas.",
                ['code' => $codeEnfant, 'code_parent' => $codeParent]);
    }

    private function historiser(Structure $structure, ActionHistorique $action, ?array $avant, ?User $auteur, ?string $motif = null): void
    {
        $apres = self::instantane($structure);

        $champsModifies = $avant === null ? null : array_values(array_keys(array_filter(
            $apres, fn ($valeur, $cle) => ($avant[$cle] ?? null) !== $valeur, ARRAY_FILTER_USE_BOTH,
        )));

        HistoriqueStructure::create([
            'structure_id' => $structure->id,
            'code' => $structure->code,
            'action' => $action,
            'donnees_avant' => $avant,
            'donnees_apres' => $apres,
            'champs_modifies' => $champsModifies,
            'motif' => $motif,
            'utilisateur_id' => $auteur?->id,
            'effectue_le' => CarbonImmutable::now('UTC'),
        ]);
    }
}
