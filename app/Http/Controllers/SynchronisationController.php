<?php

namespace App\Http\Controllers;

use App\Http\Requests\SynchronisationRequest;
use App\Http\Resources\StructureResource;
use App\Services\SynchronisationService;
use Illuminate\Http\JsonResponse;

/** F6 : GET /api/structures/synchronisation?depuis=ISO8601[&limite=][&apres_code=] */
class SynchronisationController extends Controller
{
    public function __invoke(SynchronisationRequest $request, SynchronisationService $service): JsonResponse
    {
        $depuis = $request->depuis();
        $limite = (int) $request->validated('limite', SynchronisationService::LIMITE_DEFAUT);
        $resultat = $service->modificationsDepuis($depuis, $limite, $request->validated('apres_code'));

        $derniere = $resultat['structures']->last();

        return response()->json([
            'data' => StructureResource::collection($resultat['structures'])->resolve($request),
            'synchronisation' => [
                'depuis' => StructureResource::iso($depuis),
                'horodatage_serveur' => StructureResource::iso($resultat['horodatage_serveur']),
                'nombre' => $resultat['structures']->count(),
                'complet' => $resultat['complet'],
                'suivant' => $resultat['complet'] || $derniere === null ? null : [
                    'depuis' => StructureResource::iso($derniere->updated_at),
                    'apres_code' => $derniere->code,
                    'limite' => $limite,
                ],
                'semantique' => 'Structures modifiées strictement après depuis, inactives incluses, triées par modifie_le puis code. '
                    .'Tant que complet vaut false, rappeler avec les paramètres de suivant. '
                    .'Ensuite, conserver horodatage_serveur de la première page comme prochain depuis. '
                    .'Une marge de recouvrement de '.SynchronisationService::MARGE_SECONDES.' s peut renvoyer une structure déjà reçue : dédoublonner par code et version.',
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
