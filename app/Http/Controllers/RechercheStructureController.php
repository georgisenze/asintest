<?php

namespace App\Http\Controllers;

use App\Http\Requests\RechercheStructureRequest;
use App\Http\Resources\StructureResource;
use App\Services\RechercheStructureService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** F3 : GET /api/structures */
class RechercheStructureController extends Controller
{
    public function __invoke(RechercheStructureRequest $request, RechercheStructureService $service): AnonymousResourceCollection
    {
        return StructureResource::collection($service->rechercher($request->validated()));
    }
}
