<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class SanteController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            throw new ApiException(503, 'BASE_INDISPONIBLE', 'La base de données est injoignable.');
        }

        return response()->json([
            'statut' => 'ok',
            'base_de_donnees' => 'ok',
            'horodatage' => now('UTC')->toIso8601ZuluString(),
        ]);
    }
}
