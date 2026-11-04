<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Swagger UI : test interactif de l'API à partir de docs/openapi.yaml. */
class DocumentationController extends Controller
{
    public function index(): View
    {
        return view('documentation.swagger');
    }

    public function contrat(): BinaryFileResponse
    {
        return response()->file(base_path('docs/openapi.yaml'), [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
