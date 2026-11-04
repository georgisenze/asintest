<?php

use App\Http\Controllers\ArborescenceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EtatStructureController;
use App\Http\Controllers\ImportStructureController;
use App\Http\Controllers\RechercheStructureController;
use App\Http\Controllers\SanteController;
use App\Http\Controllers\StructureController;
use App\Http\Controllers\SynchronisationController;
use Illuminate\Support\Facades\Route;

Route::get('/sante', SanteController::class)->name('api.sante');

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.me');

    // Routes statiques avant /structures/{code}.
    Route::get('/structures', RechercheStructureController::class)->name('api.structures.recherche');          // F3
    Route::post('/structures', [StructureController::class, 'store'])->name('api.structures.creer');           // F1
    Route::get('/structures/synchronisation', SynchronisationController::class)->name('api.structures.sync');  // F6
    Route::post('/structures/import', ImportStructureController::class)->name('api.structures.import');        // F7

    Route::get('/structures/{code}', [StructureController::class, 'show'])->name('api.structures.voir');                     // F2
    Route::patch('/structures/{code}', [StructureController::class, 'update'])->name('api.structures.modifier');             // F4
    Route::get('/structures/{code}/historique', [StructureController::class, 'historique'])->name('api.structures.historique');
    Route::post('/structures/{code}/desactivation', [StructureController::class, 'desactiver'])->name('api.structures.desactiver'); // F5
    Route::get('/structures/{code}/etat', EtatStructureController::class)->name('api.structures.etat');                     // F9

    Route::get('/ministeres/{code}/arborescence', ArborescenceController::class)->name('api.ministeres.arborescence');       // F8
});
