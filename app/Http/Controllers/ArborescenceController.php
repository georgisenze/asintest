<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArborescenceRequest;
use App\Services\ArborescenceService;
use Illuminate\Http\JsonResponse;

/** F8 */
class ArborescenceController extends Controller
{
    public function __construct(private readonly ArborescenceService $service) {}

    public function __invoke(ArborescenceRequest $request, string $code): JsonResponse
    {
        $date = $request->dateDemandee();
        $resultat = $this->service->arborescence($code, $date, $request->inclureInactives());

        return response()->json([
            'data' => $resultat['arbre'],
            'statistiques' => $resultat['statistiques'],
            'parametres' => [
                'date' => $date?->toIso8601ZuluString(),
                'inclure_inactives' => $request->inclureInactives(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
