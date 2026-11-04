<?php

namespace Database\Seeders;

use App\Models\Structure;
use App\Models\User;
use App\Services\StructureService;
use Illuminate\Database\Seeder;

/**
 * Jeu de démonstration créé via StructureService : les règles métier
 * s'appliquent et l'historique est alimenté. Idempotent.
 */
class StructuresDemoSeeder extends Seeder
{
    /** [code, sigle, libelle, type, code_parent] */
    private const STRUCTURES = [
        ['MIN-SANTE', 'MSHP', 'Ministère de la Santé et de l\'Hygiène publique', 'MINISTERE', null],
        ['DGS', 'DGS', 'Direction générale de la Santé', 'DIRECTION_GENERALE', 'MIN-SANTE'],
        ['DSIM', 'DSIM', 'Direction des Soins infirmiers et maternels', 'DIRECTION', 'DGS'],
        ['DSIM-SPF', 'SPF', 'Service de la Planification familiale', 'SERVICE', 'DSIM'],
        ['DSIM-SVAC', 'SVAC', 'Service de la Vaccination', 'SERVICE', 'DSIM'],
        ['DRH-SANTE', 'DRH', 'Direction des Ressources humaines', 'DIRECTION', 'MIN-SANTE'],
        ['DRH-SANTE-SPAIE', 'SPAIE', 'Service de la Paie', 'SERVICE', 'DRH-SANTE'],

        ['MIN-EDUC', 'MENA', 'Ministère de l\'Éducation nationale et de l\'Alphabétisation', 'MINISTERE', null],
        ['DGEN', 'DGEN', 'Direction générale de l\'Enseignement', 'DIRECTION_GENERALE', 'MIN-EDUC'],
        ['DEP', 'DEP', 'Direction de l\'Enseignement primaire', 'DIRECTION', 'DGEN'],
        ['DEP-SEXA', 'SEXA', 'Service des Examens et Concours', 'SERVICE', 'DEP'],
        ['DRH-EDUC', 'DRH', 'Direction des Ressources humaines', 'DIRECTION', 'MIN-EDUC'],

        ['MIN-ECOFIN', 'MEF', 'Ministère de l\'Économie et des Finances', 'MINISTERE', null],
        ['DGBF', 'DGBF', 'Direction générale du Budget et des Finances', 'DIRECTION_GENERALE', 'MIN-ECOFIN'],
        ['DGTCP', 'DGTCP', 'Direction générale du Trésor et de la Comptabilité publique', 'DIRECTION_GENERALE', 'MIN-ECOFIN'],
        ['DGTCP-SRECET', 'SRECET', 'Service des Recettes', 'SERVICE', 'DGTCP'],
        ['DSI-ECOFIN', 'DSI', 'Direction des Systèmes d\'information', 'DIRECTION', 'MIN-ECOFIN'],
    ];

    public function run(StructureService $service): void
    {
        $admin = User::where('email', 'admin@referentiel.local')->first();

        foreach (self::STRUCTURES as [$code, $sigle, $libelle, $type, $parent]) {
            if (Structure::where('code', $code)->exists()) {
                continue;
            }
            $service->creer(compact('code', 'sigle', 'libelle', 'type') + ['code_parent' => $parent], $admin);
        }

        // Une structure désactivée pour la démonstration (R5, F9).
        $ancien = Structure::where('code', 'DGTCP-SCAISSE')->first()
            ?? $service->creer(['code' => 'DGTCP-SCAISSE', 'sigle' => 'SCAISSE', 'libelle' => 'Service de la Caisse centrale',
                'type' => 'SERVICE', 'code_parent' => 'DGTCP'], $admin);

        if ($ancien->estActive()) {
            $service->desactiver($ancien, 'Fusion avec le Service des Recettes', $admin);
        }
    }
}
