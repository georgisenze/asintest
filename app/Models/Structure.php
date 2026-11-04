<?php

namespace App\Models;

use App\Enums\StatutStructure;
use App\Enums\TypeStructure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $code
 * @property string $sigle
 * @property string $libelle
 * @property TypeStructure $type
 * @property StatutStructure $statut
 * @property ?int $parent_id
 * @property int $version
 */
class Structure extends Model
{
    /** @use HasFactory<\Database\Factories\StructureFactory> */
    use HasFactory;

    protected $table = 'structures';

    protected $fillable = [
        'code', 'sigle', 'libelle', 'type', 'parent_id', 'statut',
        'date_desactivation', 'motif_desactivation', 'version', 'cree_par', 'modifie_par',
    ];

    protected $attributes = [
        'statut' => 'ACTIVE',
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeStructure::class,
            'statut' => StatutStructure::class,
            'date_desactivation' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return \Carbon\CarbonImmutable::instance($date)->utc()->toIso8601ZuluString();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function filles(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function historiques(): HasMany
    {
        return $this->hasMany(HistoriqueStructure::class, 'structure_id');
    }

    public function estActive(): bool
    {
        return $this->statut === StatutStructure::ACTIVE;
    }

    public function scopeActives(Builder $query): Builder
    {
        return $query->where('statut', StatutStructure::ACTIVE->value);
    }

    /**
     * Ancêtres de la structure, de la racine vers le parent direct (sans la structure elle-même).
     * Une seule requête récursive, quelle que soit la profondeur.
     *
     * @return Collection<int, self>
     */
    public function ancetres(): Collection
    {
        if ($this->parent_id === null) {
            return collect();
        }

        $lignes = DB::select(<<<'SQL'
            WITH RECURSIVE chaine AS (
                SELECT s.*, 1 AS profondeur FROM structures s WHERE s.id = ?
                UNION ALL
                SELECT p.*, c.profondeur + 1 FROM structures p
                JOIN chaine c ON p.id = c.parent_id
                WHERE c.profondeur < 50
            )
            SELECT * FROM chaine ORDER BY profondeur DESC
        SQL, [$this->parent_id]);

        return self::hydrate(array_map(
            fn ($l) => collect((array) $l)->except('profondeur')->all(),
            $lignes,
        ))->toBase();
    }

    /**
     * Chemin complet de la racine jusqu'à la structure incluse.
     *
     * @return Collection<int, self>
     */
    public function chemin(): Collection
    {
        return $this->ancetres()->push($this);
    }

    /** Identifiants de toute la descendance (filles, petites-filles...). */
    public function idsDescendants(): array
    {
        return array_map('intval', array_column(DB::select(<<<'SQL'
            WITH RECURSIVE descendance AS (
                SELECT id FROM structures WHERE parent_id = ?
                UNION
                SELECT s.id FROM structures s JOIN descendance d ON s.parent_id = d.id
            )
            SELECT id FROM descendance
        SQL, [$this->id]), 'id'));
    }
}
