<?php

namespace App\Http\Controllers;

use App\Http\Requests\EtatStructureRequest;
use App\Services\EtatADateService;
use Illuminate\Http\JsonResponse;

/** F9 */
class EtatStructureController extends Controller
{
    public function __construct(private readonly EtatADateService $service) {}

    public function __invoke(EtatStructureRequest $request, string $code): JsonResponse
    {
        $date = $request->dateDemandee();
        $resultat = $this->service->etatStructure($code, $date);

        $parent = $resultat['parent'];

        return response()->json([
            'data' => $resultat['etat'] + [
                'parent' => $parent === null ? null : [
                    'code' => $parent['code'],
                    'sigle' => $parent['sigle'],
                    'libelle' => $parent['libelle'],
                    'type' => $parent['type'],
                    'statut' => $parent['statut'],
                ],
            ],
            'meta' => [
                'date_demandee' => $date->toIso8601ZuluString(),
                'etat_au' => $resultat['effectue_le']->utc()->toIso8601ZuluString(),
                'action' => $resultat['action'],
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
