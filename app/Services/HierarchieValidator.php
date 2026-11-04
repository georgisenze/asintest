<?php

namespace App\Services;

use App\Enums\StatutStructure;
use App\Enums\TypeStructure;
use App\Exceptions\ApiException;
use App\Models\Structure;

/**
 * Règles de hiérarchie (R2, R3, R4, R5, R7). Lève des ApiException
 * avec un code d'erreur stable et un message qui cite les codes concernés.
 */
class HierarchieValidator
{
    /** R2 : compatibilité type / parent. */
    public function verifierTypeEtParent(string $code, TypeStructure $type, ?Structure $parent): void
    {
        if ($type->estRacine()) {
            if ($parent !== null) {
                throw ApiException::invalide('HIERARCHIE_INVALIDE',
                    "La structure {$code} de type {$type->value} ne peut pas être rattachée à une autre structure (rattachement demandé : {$parent->code}).",
                    ['code' => $code, 'type' => $type->value, 'code_parent' => $parent->code]);
            }

            return;
        }

        $attendus = array_map(fn (TypeStructure $t) => $t->value, $type->parentsAutorises());

        if ($parent === null) {
            throw ApiException::invalide('HIERARCHIE_INVALIDE',
                "La structure {$code} de type {$type->value} doit être rattachée à une structure de type ".implode(' ou ', $attendus).'.',
                ['code' => $code, 'type' => $type->value, 'types_parent_autorises' => $attendus]);
        }

        if (! $type->accepteCommeParent($parent->type)) {
            throw ApiException::invalide('HIERARCHIE_INVALIDE',
                "La structure {$code} de type {$type->value} ne peut pas être rattachée à {$parent->code} de type {$parent->type->value}. Types de parent autorisés : ".implode(', ', $attendus).'.',
                ['code' => $code, 'type' => $type->value, 'code_parent' => $parent->code,
                    'type_parent' => $parent->type->value, 'types_parent_autorises' => $attendus]);
        }
    }

    /** R3 : le parent doit être actif. */
    public function verifierParentActif(string $code, ?Structure $parent): void
    {
        if ($parent !== null && ! $parent->estActive()) {
            throw ApiException::conflit('PARENT_INACTIF',
                "Impossible de rattacher {$code} à {$parent->code} : la structure parente est inactive.",
                ['code' => $code, 'code_parent' => $parent->code]);
        }
    }

    /** R4 : le nouveau parent ne peut être ni la structure elle-même, ni l'un de ses descendants. */
    public function verifierAbsenceDeCycle(Structure $structure, ?Structure $nouveauParent): void
    {
        if ($nouveauParent === null) {
            return;
        }

        $ancetresDuParent = $nouveauParent->chemin()->pluck('id')->all();

        if (in_array($structure->id, $ancetresDuParent, true)) {
            throw ApiException::conflit('CYCLE_HIERARCHIQUE',
                "Impossible de rattacher {$structure->code} à {$nouveauParent->code} : {$nouveauParent->code} est la structure elle-même ou l'une de ses descendantes.",
                ['code' => $structure->code, 'code_parent' => $nouveauParent->code]);
        }
    }

    /** R4 : un changement de type doit rester compatible avec les filles actives et inactives. */
    public function verifierFillesCompatibles(Structure $structure, TypeStructure $nouveauType): void
    {
        $incompatibles = $structure->filles()->get()
            ->reject(fn (Structure $f) => $f->type->accepteCommeParent($nouveauType))
            ->pluck('code')->values()->all();

        if ($incompatibles !== []) {
            throw ApiException::conflit('FILLES_INCOMPATIBLES',
                "Impossible de passer {$structure->code} en type {$nouveauType->value} : les structures filles ".implode(', ', $incompatibles).' ne pourraient plus y être rattachées.',
                ['code' => $structure->code, 'type' => $nouveauType->value, 'filles_incompatibles' => $incompatibles]);
        }
    }

    /** R5 : aucune fille active pour désactiver. */
    public function verifierDesactivable(Structure $structure): void
    {
        if (! $structure->estActive()) {
            throw ApiException::conflit('STRUCTURE_DEJA_INACTIVE',
                "La structure {$structure->code} est déjà inactive.", ['code' => $structure->code]);
        }

        $fillesActives = $structure->filles()->actives()->orderBy('code')->pluck('code')->all();

        if ($fillesActives !== []) {
            throw ApiException::conflit('FILLES_ACTIVES',
                "Impossible de désactiver {$structure->code} : elle a encore ".count($fillesActives).' structure(s) fille(s) active(s) ('.implode(', ', $fillesActives).'). Désactivez-les ou rattachez-les ailleurs d\'abord.',
                ['code' => $structure->code, 'filles_actives' => $fillesActives]);
        }
    }

    /** Une structure inactive est figée. */
    public function verifierModifiable(Structure $structure): void
    {
        if (! $structure->estActive()) {
            throw ApiException::conflit('STRUCTURE_INACTIVE',
                "La structure {$structure->code} est inactive et ne peut plus être modifiée.",
                ['code' => $structure->code]);
        }
    }

    /** R7 : sigle unique parmi les structures actives de même parent. */
    public function verifierSigleUnique(string $code, string $sigle, ?int $parentId, ?int $idExclu = null): void
    {
        $doublon = Structure::query()
            ->where('statut', StatutStructure::ACTIVE->value)
            ->whereRaw('lower(sigle) = lower(?)', [$sigle])
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->when($idExclu !== null, fn ($q) => $q->where('id', '<>', $idExclu))
            ->value('code');

        if ($doublon !== null) {
            throw ApiException::conflit('SIGLE_EN_DOUBLON',
                "Le sigle {$sigle} demandé pour {$code} est déjà utilisé par la structure active {$doublon} au même niveau de rattachement.",
                ['code' => $code, 'sigle' => $sigle, 'code_existant' => $doublon]);
        }
    }
}
