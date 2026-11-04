<?php

use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Routes de test uniquement, pour provoquer chaque type d'erreur.
    Route::middleware('api')->prefix('api/_test')->group(function () {
        Route::post('/echo', fn (Request $r) => response()->json($r->all()));
        Route::post('/validation', fn (Request $r) => $r->validate(['libelle' => 'required|string']));
        Route::get('/conflit', fn () => throw ApiException::conflit(
            'STRUCTURE_INACTIVE', 'La structure MIN-SANTE est inactive.', ['code' => 'MIN-SANTE']
        ));
        Route::get('/sql-format', fn () => DB::select("select 'abc'::integer"));
        Route::get('/bug', fn () => throw new LogicException('détail technique secret'));
        Route::get('/protegee', fn () => 'ok')->middleware('auth:sanctum');
    });
});

it('répond en JSON sur /api même sans en-tête Accept', function () {
    $reponse = $this->call('GET', '/api/route-inexistante');

    attendreErreur($reponse, 404, 'ROUTE_INTROUVABLE')
        ->assertJsonPath('message', "La route GET /api/route-inexistante n'existe pas.");
});

it('renvoie 405 au format unique avec les méthodes autorisées', function () {
    attendreErreur($this->postJson('/api/sante'), 405, 'METHODE_NON_AUTORISEE')
        ->assertJsonPath('details.methodes_autorisees', ['GET', 'HEAD']);
});

it('rejette un corps JSON malformé en 422', function () {
    $reponse = $this->call('POST', '/api/_test/echo', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], '{"sigle": "MS",');

    attendreErreur($reponse, 422, 'JSON_INVALIDE');
});

it('rejette un corps JSON qui n\'est pas un objet en 422', function () {
    $reponse = $this->call('POST', '/api/_test/echo', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], '"texte"');

    attendreErreur($reponse, 422, 'JSON_INVALIDE');
});

it('accepte un corps JSON valide', function () {
    $this->postJson('/api/_test/echo', ['sigle' => 'MS'])
        ->assertOk()
        ->assertExactJson(['sigle' => 'MS']);
});

it('renvoie les erreurs de validation en 422 avec le détail par champ', function () {
    attendreErreur($this->postJson('/api/_test/validation', []), 422, 'VALIDATION_ECHOUEE')
        ->assertJsonStructure(['details' => ['libelle']]);
});

it('rend une ApiException avec son statut, son code et ses détails', function () {
    $reponse = $this->getJson('/api/_test/conflit');

    attendreErreur($reponse, 409, 'STRUCTURE_INACTIVE')
        ->assertJsonPath('message', 'La structure MIN-SANTE est inactive.')
        ->assertJsonPath('details.code', 'MIN-SANTE');

    // Accents non échappés (é) dans la réponse brute.
    expect($reponse->getContent())->toContain('La structure MIN-SANTE est inactive.');
});

it('convertit une valeur rejetée par PostgreSQL en 422 sans exposer le SQL', function () {
    $reponse = $this->getJson('/api/_test/sql-format');

    attendreErreur($reponse, 422, 'DONNEE_INVALIDE');
    expect($reponse->getContent())->not->toContain('select');
});

it('renvoie 401 au format unique sans redirection vers une page de login', function () {
    attendreErreur($this->call('GET', '/api/_test/protegee'), 401, 'NON_AUTHENTIFIE');
});

it('masque le détail technique d\'une erreur inattendue hors mode debug', function () {
    config(['app.debug' => false]);

    $reponse = $this->getJson('/api/_test/bug');

    attendreErreur($reponse, 500, 'ERREUR_INTERNE')
        ->assertJsonPath('message', 'Une erreur interne est survenue.');
    expect($reponse->getContent())->not->toContain('secret');
});

it('expose un point de santé qui vérifie la base', function () {
    $this->getJson('/api/sante')
        ->assertOk()
        ->assertJsonPath('statut', 'ok')
        ->assertJsonPath('base_de_donnees', 'ok');
});
