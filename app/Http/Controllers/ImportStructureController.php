<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportStructureRequest;
use App\Services\ImportStructureService;
use Illuminate\Http\JsonResponse;

/** F7 : import CSV de structures avec rapport ligne par ligne. */
class ImportStructureController extends Controller
{
    public function __construct(private readonly ImportStructureService $import) {}

    public function __invoke(ImportStructureRequest $request): JsonResponse
    {
        $rapport = $this->import->importer(
            (string) $request->file('fichier')->get(),
            $request->boolean('simulation'),
            $request->user(),
        );

        return response()->json($rapport, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
