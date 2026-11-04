<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Les tests Feature tournent sur PostgreSQL (base referentiel_test),
| jamais sur SQLite : unaccent, timestamptz et contraintes doivent être
| ceux de la production.
*/

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Vérifie qu'une réponse respecte le format d'erreur unique de l'API.
 */
function attendreErreur(\Illuminate\Testing\TestResponse $reponse, int $statut, string $code): \Illuminate\Testing\TestResponse
{
    return $reponse
        ->assertStatus($statut)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['erreur', 'message', 'details'])
        ->assertJsonPath('erreur', $code);
}
