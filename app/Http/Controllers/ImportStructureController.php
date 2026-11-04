<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;

/** Squelette : à implémenter. */
class ImportStructureController extends Controller
{
    public function __invoke(): never
    {
        throw new ApiException(501, 'NON_IMPLEMENTE', 'Fonction non encore implémentée.');
    }
}
