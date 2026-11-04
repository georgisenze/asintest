<?php

use App\Http\Controllers\Web\RechercheStructureWebController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/structures');

// F10 : consultation publique en lecture seule.
Route::get('/structures', RechercheStructureWebController::class)->name('web.structures.recherche');
