<?php

namespace App\Http\Controllers\Web;

use App\Enums\StatutStructure;
use App\Enums\TypeStructure;
use App\Http\Controllers\Controller;
use App\Http\Requests\RechercheStructureRequest;
use App\Services\RechercheStructureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * F10 : écran de consultation en lecture seule, même service que l'API.
 * Public : il n'expose que des données de référentiel non sensibles ;
 * toutes les écritures restent derrière Sanctum sur l'API.
 */
class RechercheStructureWebController extends Controller
{
    public function __invoke(Request $request, RechercheStructureService $service): View
    {
        $criteres = array_merge($request->query(), RechercheStructureRequest::normaliser($request->query()));
        $validateur = Validator::make($criteres, RechercheStructureRequest::regles(), RechercheStructureRequest::messagesPersonnalises());

        $resultats = $validateur->fails() ? null : $service->rechercher($validateur->validated());

        return view('structures.recherche', [
            'criteres' => $criteres,
            'erreurs' => $validateur->errors(),
            'resultats' => $resultats,
            'types' => TypeStructure::cases(),
            'statuts' => StatutStructure::cases(),
        ]);
    }
}
