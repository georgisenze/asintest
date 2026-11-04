<?php

use App\Models\Structure;
use App\Models\User;
use App\Services\StructureService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());

    $service = app(StructureService::class);
    $creer = fn (string $code, string $sigle, string $libelle, string $type, ?string $parent = null) => $service->creer(
        compact('code', 'sigle', 'libelle', 'type') + ['code_parent' => $parent]
    );

    $creer('MIN-SANTE', 'MSHP', 'Ministère de la Santé', 'MINISTERE');
    $creer('DGS', 'DGS', 'Direction générale de la Santé', 'DIRECTION_GENERALE', 'MIN-SANTE');
    $creer('DSIM', 'DSIM', 'Direction des Soins infirmiers', 'DIRECTION', 'DGS');
    $creer('DSIM-SVAC', 'SVAC', 'Service de la Vaccination', 'SERVICE', 'DSIM');
    $creer('MIN-EDUC', 'MENA', 'Ministère de l\'Éducation nationale', 'MINISTERE');
    $creer('DEP', 'DEP', 'Direction de l\'Enseignement primaire', 'DIRECTION', 'MIN-EDUC');
    $creer('DEP-100', 'S100', 'Service 100% numérique', 'SERVICE', 'DEP');
    $creer('DEP-SX', 'S_X', 'Service des examens', 'SERVICE', 'DEP');

    $service->desactiver(Structure::where('code', 'DEP-SX')->first(), 'Fusion');
});

function codesRecherche(\Illuminate\Testing\TestResponse $r): array
{
    return collect($r->json('data'))->pluck('code')->all();
}

it('exige une authentification', function () {
    $this->app['auth']->forgetGuards();

    attendreErreur($this->getJson('/api/structures?q=x'), 401, 'NON_AUTHENTIFIE');
});

it('renvoie une liste paginée triée par code par défaut', function () {
    $r = $this->getJson('/api/structures')->assertOk()
        ->assertJsonStructure(['data' => [['code', 'sigle', 'libelle', 'type', 'statut', 'code_parent']], 'links', 'meta' => ['current_page', 'per_page', 'total']]);

    expect(codesRecherche($r))->toBe(['DEP', 'DEP-100', 'DEP-SX', 'DGS', 'DSIM', 'DSIM-SVAC', 'MIN-EDUC', 'MIN-SANTE'])
        ->and($r->json('meta.total'))->toBe(8)
        ->and($r->json('meta.per_page'))->toBe(20);
});

it('cherche sans tenir compte des accents ni de la casse', function (string $q, array $attendus) {
    expect(codesRecherche($this->getJson('/api/structures?q='.urlencode($q))->assertOk()))->toBe($attendus);
})->with([
    'sans accent trouve accent' => ['education', ['MIN-EDUC']],
    'majuscules et accents' => ['SANTÉ', ['DGS', 'MIN-SANTE']],
    'accent saisi, texte sans accent en base' => ['vâccination', ['DSIM-SVAC']],
    'sur le code' => ['dsim', ['DSIM', 'DSIM-SVAC']],
    'sur le sigle' => ['mena', ['MIN-EDUC']],
]);

it('prend % et _ littéralement dans q', function () {
    expect(codesRecherche($this->getJson('/api/structures?q='.urlencode('100%'))))->toBe(['DEP-100'])
        ->and(codesRecherche($this->getJson('/api/structures?q='.urlencode('S_X'))))->toBe(['DEP-SX'])
        ->and(codesRecherche($this->getJson('/api/structures?q='.urlencode('%'))))->toBe(['DEP-100']);
});

it('filtre par type et statut', function () {
    expect(codesRecherche($this->getJson('/api/structures?type=service&statut=ACTIVE')))->toBe(['DEP-100', 'DSIM-SVAC'])
        ->and(codesRecherche($this->getJson('/api/structures?statut=INACTIVE')))->toBe(['DEP-SX']);
});

it('filtre sur les filles directes avec code_parent', function () {
    expect(codesRecherche($this->getJson('/api/structures?code_parent=dep')))->toBe(['DEP-100', 'DEP-SX'])
        ->and(codesRecherche($this->getJson('/api/structures?code_parent=INCONNU')))->toBe([]);
});

it('filtre sur toute la descendance d\'un ministère avec code_ministere', function () {
    expect(codesRecherche($this->getJson('/api/structures?code_ministere=MIN-SANTE')))->toBe(['DGS', 'DSIM', 'DSIM-SVAC', 'MIN-SANTE'])
        ->and(codesRecherche($this->getJson('/api/structures?code_ministere=DGS')))->toBe([]); // DGS n'est pas un ministère
});

it('combine q et code_ministere', function () {
    expect(codesRecherche($this->getJson('/api/structures?code_ministere=MIN-EDUC&q=service')))->toBe(['DEP-100', 'DEP-SX']);
});

it('trie selon la liste blanche, en ordre décroissant avec un tiret', function () {
    expect(codesRecherche($this->getJson('/api/structures?type=MINISTERE&tri=-code')))->toBe(['MIN-SANTE', 'MIN-EDUC'])
        ->and(codesRecherche($this->getJson('/api/structures?type=MINISTERE&tri=libelle')))->toBe(['MIN-EDUC', 'MIN-SANTE']);
});

it('pagine avec par_page et page', function () {
    $r = $this->getJson('/api/structures?par_page=3&page=2')->assertOk();

    expect(codesRecherche($r))->toBe(['DGS', 'DSIM', 'DSIM-SVAC'])
        ->and($r->json('meta.last_page'))->toBe(3)
        ->and($r->json('links.next'))->toContain('par_page=3');
});

it('rejette les critères invalides en 422', function (string $query, string $champ) {
    attendreErreur($this->getJson('/api/structures?'.$query), 422, 'VALIDATION_ECHOUEE')
        ->assertJsonStructure(['details' => [$champ]]);
})->with([
    ['type=AGENCE', 'type'],
    ['statut=SUPPRIMEE', 'statut'],
    ['tri=id', 'tri'],
    ['tri=libelle;drop table structures', 'tri'],
    ['par_page=0', 'par_page'],
    ['par_page=101', 'par_page'],
    ['par_page=abc', 'par_page'],
    ['page=0', 'page'],
    ['q[]=x', 'q'],
]);

it('n\'exécute pas une requête par structure pour le parent (pas de N+1)', function () {
    DB::enableQueryLog();
    $this->getJson('/api/structures?par_page=100')->assertOk();
    $requetes = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($requetes)->toBeLessThanOrEqual(5);
});
