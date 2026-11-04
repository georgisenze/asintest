<?php

namespace App\Models;

use App\Enums\ActionHistorique;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoriqueStructure extends Model
{
    protected $table = 'historique_structures';

    public $timestamps = false;

    protected $fillable = [
        'structure_id', 'code', 'action', 'donnees_avant', 'donnees_apres',
        'champs_modifies', 'motif', 'utilisateur_id', 'effectue_le',
    ];

    protected function casts(): array
    {
        return [
            'action' => ActionHistorique::class,
            'donnees_avant' => 'array',
            'donnees_apres' => 'array',
            'champs_modifies' => 'array',
            'effectue_le' => 'immutable_datetime',
        ];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return \Carbon\CarbonImmutable::instance($date)->utc()->toIso8601ZuluString();
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'structure_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }
}
