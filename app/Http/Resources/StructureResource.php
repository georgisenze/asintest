<?php

namespace App\Http\Resources;

use App\Models\Structure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Structure */
class StructureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'sigle' => $this->sigle,
            'libelle' => $this->libelle,
            'type' => $this->type->value,
            'statut' => $this->statut->value,
            'code_parent' => $this->whenLoaded('parent', fn () => $this->parent?->code, fn () => $this->parent_id === null ? null : $this->parent()->value('code')),
            'date_desactivation' => self::iso($this->date_desactivation),
            'motif_desactivation' => $this->motif_desactivation,
            'version' => $this->version,
            'cree_le' => self::iso($this->created_at),
            'modifie_le' => self::iso($this->updated_at),
        ];
    }

    public static function resume(?Structure $s): ?array
    {
        return $s === null ? null : [
            'code' => $s->code,
            'sigle' => $s->sigle,
            'libelle' => $s->libelle,
            'type' => $s->type->value,
            'statut' => $s->statut->value,
        ];
    }

    public static function iso(?\DateTimeInterface $date): ?string
    {
        return $date === null ? null : \Carbon\CarbonImmutable::instance($date)->utc()->toIso8601ZuluString();
    }
}
