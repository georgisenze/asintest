<?php

use App\Models\HistoriqueStructure;
use App\Models\User;
use App\Services\StructureService;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
    $s = app(StructureService::class);
    $s->creer(['code' => 'MIN-SANTE', 'sigle' => 'MSHP', 'libelle' => 'Ministère de la Santé', 'type' => 'MINISTERE']);
    $s->creer(['code' => 'DSIM', 'sigle' => 'DSIM', 'libelle' => 'Direction des soins', 'type' => 'DIRECTION', 'code_parent' => 'MIN-SANTE']);
    $s->creer(['code' => 'DSIM-SPF', 'sigle' => 'SPF', 'libelle' => 'Planification', 'type' => 'SERVICE', 'code_parent' => 'DSIM']);
    $s->creer(['code' => 'DSIM-SVAC', 'sigle' => 'SVAC', 'libelle' => 'Vaccination', 'type' => 'SERVICE', 'code_parent' => 'DSIM']);
});

it('désactive une structure sans fille active et historise (F5, R6)', function () {
    $reponse = $this->postJson('/api/structures/DSIM-SPF/desactivation', ['motif' => 'Fusion de services']);

    $reponse->assertOk()
        ->assertJsonPath('data.statut', 'INACTIVE')
        ->assertJsonPath('data.motif_desactivation', 'Fusion de services');
    expect($reponse->json('data.date_desactivation'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $h = HistoriqueStructure::where('code', 'DSIM-SPF')->where('action', 'DESACTIVATION')->sole();
    expect($h->donnees_avant['statut'])->toBe('ACTIVE')
        ->and($h->donnees_apres['statut'])->toBe('INACTIVE')
        ->and($h->motif)->toBe('Fusion de services');
});

it('refuse de désactiver une structure ayant des filles actives (R5)', function () {
    $reponse = $this->postJson('/api/structures/DSIM/desactivation', ['motif' => 'Réorganisation']);

    attendreErreur($reponse, 409, 'FILLES_ACTIVES')
        ->assertJsonPath('details.filles_actives', ['DSIM-SPF', 'DSIM-SVAC']);
    expect($reponse->json('message'))->toContain('DSIM');
});

it('autorise la désactivation une fois les filles désactivées', function () {
    $this->postJson('/api/structures/DSIM-SPF/desactivation', ['motif' => 'Fusion'])->assertOk();
    $this->postJson('/api/structures/DSIM-SVAC/desactivation', ['motif' => 'Fusion'])->assertOk();

    $this->postJson('/api/structures/DSIM/desactivation', ['motif' => 'Fusion'])
        ->assertOk()->assertJsonPath('data.statut', 'INACTIVE');
});

it('refuse de désactiver une structure déjà inactive', function () {
    $this->postJson('/api/structures/DSIM-SPF/desactivation', ['motif' => 'Fusion'])->assertOk();

    attendreErreur($this->postJson('/api/structures/DSIM-SPF/desactivation', ['motif' => 'Encore']), 409, 'STRUCTURE_DEJA_INACTIVE');
});

it('exige un motif', function (array $corps) {
    attendreErreur($this->postJson('/api/structures/DSIM-SPF/desactivation', $corps), 422, 'VALIDATION_ECHOUEE')
        ->assertJsonStructure(['details' => ['motif']]);
})->with([
    'absent' => [[]],
    'tableau' => [['motif' => ['x']]],
    'trop court' => [['motif' => 'a']],
]);

it('renvoie l\'historique paginé, plus récent d\'abord', function () {
    $this->patchJson('/api/structures/DSIM-SPF', ['libelle' => 'Planification familiale'])->assertOk();
    $this->postJson('/api/structures/DSIM-SPF/desactivation', ['motif' => 'Fusion'])->assertOk();

    $this->getJson('/api/structures/DSIM-SPF/historique?par_page=2')
        ->assertOk()
        ->assertJsonPath('data.*.action', ['DESACTIVATION', 'MODIFICATION'])
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2);
});
