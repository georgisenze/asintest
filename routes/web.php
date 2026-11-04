<?php

use App\Http\Controllers\Web\DocumentationController;
use App\Http\Controllers\Web\RechercheStructureWebController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/structures');

// F10 : consultation publique en lecture seule.
Route::get('/structures', RechercheStructureWebController::class)->name('web.structures.recherche');

// Swagger UI pour tester l'API directement depuis le navigateur.
Route::get('/docs', [DocumentationController::class, 'index'])->name('web.documentation');
Route::get('/docs/openapi.yaml', [DocumentationController::class, 'contrat'])->name('web.documentation.contrat');
