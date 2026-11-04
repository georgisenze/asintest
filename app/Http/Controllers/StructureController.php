<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreerStructureRequest;
use App\Http\Requests\DesactiverStructureRequest;
use App\Http\Requests\ModifierStructureRequest;
use App\Http\Resources\HistoriqueResource;
use App\Http\Resources\StructureDetailResource;
use App\Http\Resources\StructureResource;
use App\Services\StructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StructureController extends Controller
{
    public function __construct(private readonly StructureService $service) {}

    /** F1 */
    public function store(CreerStructureRequest $request): JsonResponse
    {
        $structure = $this->service->creer($request->validated(), $request->user());

        return (new StructureDetailResource($structure))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url('/api/structures/'.$structure->code));
    }

    /** F2 */
    public function show(string $code): StructureDetailResource
    {
        return new StructureDetailResource($this->service->trouverParCode($code)->load('parent'));
    }

    /** F4 */
    public function update(ModifierStructureRequest $request, string $code): StructureDetailResource
    {
        $donnees = $request->validated();
        $motif = $donnees['motif'] ?? null;
        unset($donnees['motif']);

        return new StructureDetailResource(
            $this->service->modifier($this->service->trouverParCode($code), $donnees, $request->user(), $motif)
        );
    }

    /** F5 */
    public function desactiver(DesactiverStructureRequest $request, string $code): StructureDetailResource
    {
        return new StructureDetailResource(
            $this->service->desactiver($this->service->trouverParCode($code), $request->validated('motif'), $request->user())
        );
    }

    /** Historique d'une structure, plus récent d'abord. */
    public function historique(Request $request, string $code): AnonymousResourceCollection
    {
        $parPage = min(max((int) $request->query('par_page', 20), 1), 100);

        $historique = $this->service->trouverParCode($code)
            ->historiques()->with('utilisateur')
            ->orderByDesc('effectue_le')->orderByDesc('id')
            ->paginate($parPage)->withQueryString();

        return HistoriqueResource::collection($historique);
    }
}
