<?php

namespace App\Http\Resources;

use App\Models\HistoriqueStructure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HistoriqueStructure */
class HistoriqueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'action' => $this->action->value,
            'effectue_le' => StructureResource::iso($this->effectue_le),
            'utilisateur' => $this->utilisateur?->email,
            'motif' => $this->motif,
            'champs_modifies' => $this->champs_modifies,
            'donnees_avant' => $this->donnees_avant,
            'donnees_apres' => $this->donnees_apres,
        ];
    }
}
