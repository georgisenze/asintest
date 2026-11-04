<?php

namespace App\Http\Resources;

use App\Models\Structure;
use Illuminate\Http\Request;

/** F2 : structure avec parent, filles et chemin depuis la racine. */
class StructureDetailResource extends StructureResource
{
    public function toArray(Request $request): array
    {
        /** @var Structure $s */
        $s = $this->resource;

        return array_merge(parent::toArray($request), [
            'parent' => self::resume($s->parent),
            'filles' => $s->filles()->orderBy('code')->get()->map(fn (Structure $f) => self::resume($f))->all(),
            'chemin' => $s->chemin()->map(fn (Structure $c) => self::resume($c))->values()->all(),
        ]);
    }
}
