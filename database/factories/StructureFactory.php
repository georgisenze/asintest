<?php

namespace Database\Factories;

use App\Enums\StatutStructure;
use App\Enums\TypeStructure;
use App\Models\Structure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Attention : la factory écrit directement en base sans passer par
 * StructureService, donc sans historique. Pour tester les règles métier,
 * passer par l'API ou le service.
 *
 * @extends Factory<Structure>
 */
class StructureFactory extends Factory
{
    protected $model = Structure::class;

    public function definition(): array
    {
        $sigle = strtoupper($this->faker->unique()->bothify('??##'));

        return [
            'code' => 'MIN-'.$sigle,
            'sigle' => $sigle,
            'libelle' => 'Ministère '.$this->faker->words(3, true),
            'type' => TypeStructure::MINISTERE,
            'parent_id' => null,
            'statut' => StatutStructure::ACTIVE,
            'version' => 1,
        ];
    }

    public function sous(Structure $parent, TypeStructure $type): static
    {
        return $this->state(function () use ($parent, $type) {
            $sigle = strtoupper($this->faker->unique()->bothify('??##'));

            return [
                'code' => $parent->code.'-'.$sigle,
                'sigle' => $sigle,
                'libelle' => $type->libelle().' '.$this->faker->words(2, true),
                'type' => $type,
                'parent_id' => $parent->id,
            ];
        });
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'statut' => StatutStructure::INACTIVE,
            'date_desactivation' => now('UTC'),
            'motif_desactivation' => 'Réorganisation',
        ]);
    }
}
