<?php

use App\Models\User;
use App\Services\StructureService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $s = app(StructureService::class);
    $u = User::factory()->create();

    $this->travelTo(CarbonImmutable::parse('2026-01-10T09:00:00Z'));
    $s->creer(['code' => 'MIN-A', 'sigle' => 'MA', 'libelle' => 'Ministère A', 'type' => 'MINISTERE'], $u);
    $s->creer(['code' => 'MIN-B', 'sigle' => 'MB', 'libelle' => 'Ministère B', 'type' => 'MINISTERE'], $u);
    $s->creer(['code' => 'DG-A', 'sigle' => 'DGA', 'libelle' => 'DG A', 'type' => 'DIRECTION_GENERALE', 'code_parent' => 'MIN-A'], $u);
    $s->creer(['code' => 'DIR-B', 'sigle' => 'DB', 'libelle' => 'Direction B', 'type' => 'DIRECTION', 'code_parent' => 'MIN-B'], $u);

    $this->travelTo(CarbonImmutable::parse('2026-02-01T09:00:00Z'));
    $s->creer(['code' => 'DIR-1', 'sigle' => 'D1', 'libelle' => 'Direction 1', 'type' => 'DIRECTION', 'code_parent' => 'DG-A'], $u);
    $s->creer(['code' => 'SRV-1', 'sigle' => 'S1', 'libelle' => 'Service 1', 'type' => 'SERVICE', 'code_parent' => 'DIR-1'], $u);
    $s->creer(['code' => 'DIR-2', 'sigle' => 'D2', 'libelle' => 'Direction 2', 'type' => 'DIRECTION', 'code_parent' => 'MIN-A'], $u);
    $s->creer(['code' => 'SRV-2', 'sigle' => 'S2', 'libelle' => 'Service 2', 'type' => 'SERVICE', 'code_parent' => 'DIR-2'], $u);

    // Branche DIR-2 désactivée (fille d'abord, R5).
    $this->travelTo(CarbonImmutable::parse('2026-03-01T09:00:00Z'));
    $s->desactiver($s->trouverParCode('SRV-2'), 'Fermeture', $u);
    $s->desactiver($s->trouverParCode('DIR-2'), 'Fermeture', $u);

    // DIR-B passe de MIN-B à MIN-A.
    $this->travelTo(CarbonImmutable::parse('2026-04-01T09:00:00Z'));
    $s->modifier($s->trouverParCode('DIR-B'), ['code_parent' => 'MIN-A'], $u);

    $this->travelTo(CarbonImmutable::parse('2026-05-01T00:00:00Z'));
    Sanctum::actingAs($u);
});

it('renvoie l\'arbre actuel imbriqué sans les branches inactives, en une requête récursive', function () {
    $recursives = 0;
    DB::listen(function ($q) use (&$recursives) {
        $recursives += str_contains($q->sql, 'WITH RECURSIVE') ? 1 : 0;
    });

    $this->getJson('/api/ministeres/min-a/arborescence')
        ->assertOk()
        ->assertJsonPath('data.code', 'MIN-A')
        ->assertJsonPath('data.filles.0.code', 'DG-A')
        ->assertJsonPath('data.filles.0.filles.0.code', 'DIR-1')
        ->assertJsonPath('data.filles.0.filles.0.filles.0.code', 'SRV-1')
        ->assertJsonPath('data.filles.1.code', 'DIR-B')
        ->assertJsonCount(2, 'data.filles')
        ->assertJsonPath('statistiques.total', 5)
        ->assertJsonPath('statistiques.inactives', 0)
        ->assertJsonPath('statistiques.par_type', ['MINISTERE' => 1, 'DIRECTION_GENERALE' => 1, 'DIRECTION' => 2, 'SERVICE' => 1])
        ->assertJsonPath('statistiques.profondeur_max', 3)
        ->assertJsonPath('parametres.inclure_inactives', false);

    expect($recursives)->toBe(1);
});

it('inclut les branches inactives sur demande', function () {
    $this->getJson('/api/ministeres/MIN-A/arborescence?inclure_inactives=true')
        ->assertOk()
        ->assertJsonCount(3, 'data.filles')
        ->assertJsonPath('data.filles.1.code', 'DIR-2')
        ->assertJsonPath('data.filles.1.statut', 'INACTIVE')
        ->assertJsonPath('data.filles.1.filles.0.code', 'SRV-2')
        ->assertJsonPath('statistiques.total', 7)
        ->assertJsonPath('statistiques.inactives', 2);
});

it('reconstruit l\'arbre à une date passée', function () {
    // Au 15/01 : ni DIR-1, ni DIR-2 ; DIR-B est encore sous MIN-B.
    $this->getJson('/api/ministeres/MIN-A/arborescence?date=2026-01-15T00:00:00Z')
        ->assertOk()
        ->assertJsonCount(1, 'data.filles')
        ->assertJsonPath('data.filles.0.code', 'DG-A')
        ->assertJsonCount(0, 'data.filles.0.filles')
        ->assertJsonPath('statistiques.total', 2)
        ->assertJsonPath('parametres.date', '2026-01-15T00:00:00Z');

    // Au 15/02 : DIR-2 encore active, DIR-B pas encore rattachée à MIN-A.
    $this->getJson('/api/ministeres/MIN-A/arborescence?date=2026-02-15T00:00:00Z')
        ->assertJsonCount(2, 'data.filles')
        ->assertJsonPath('data.filles.1.code', 'DIR-2')
        ->assertJsonPath('data.filles.1.statut', 'ACTIVE')
        ->assertJsonPath('statistiques.total', 6);

    $this->getJson('/api/ministeres/MIN-B/arborescence?date=2026-02-15T00:00:00Z')
        ->assertJsonPath('data.filles.0.code', 'DIR-B');
});

it('renvoie 404 pour un ministère inconnu ou inexistant à la date', function () {
    attendreErreur($this->getJson('/api/ministeres/INCONNU/arborescence'), 404, 'STRUCTURE_INTROUVABLE');
    attendreErreur($this->getJson('/api/ministeres/MIN-A/arborescence?date=2026-01-01T00:00:00Z'), 404, 'STRUCTURE_INEXISTANTE_A_DATE');
});

it('renvoie 422 si la structure n\'est pas un ministère', function () {
    attendreErreur($this->getJson('/api/ministeres/DG-A/arborescence'), 422, 'STRUCTURE_NON_MINISTERE')
        ->assertJsonPath('details.type', 'DIRECTION_GENERALE');
});

it('valide les paramètres', function () {
    attendreErreur($this->getJson('/api/ministeres/MIN-A/arborescence?inclure_inactives=peut-etre'), 422, 'VALIDATION_ECHOUEE');
    attendreErreur($this->getJson('/api/ministeres/MIN-A/arborescence?date=2027-01-01T00:00:00Z'), 422, 'VALIDATION_ECHOUEE');
});
