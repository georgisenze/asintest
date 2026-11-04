<?php

use App\Models\HistoriqueStructure;
use App\Models\Structure;
use App\Models\User;
use App\Services\StructureService;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
    $s = app(StructureService::class);
    $s->creer(['code' => 'MIN-SANTE', 'sigle' => 'MSHP', 'libelle' => 'Ministère de la Santé', 'type' => 'MINISTERE']);
    $s->creer(['code' => 'MIN-EDUC', 'sigle' => 'MENA', 'libelle' => 'Ministère de l\'Éducation', 'type' => 'MINISTERE']);
    $s->creer(['code' => 'DGS', 'sigle' => 'DGS', 'libelle' => 'DG Santé', 'type' => 'DIRECTION_GENERALE', 'code_parent' => 'MIN-SANTE']);
    $s->creer(['code' => 'DSIM', 'sigle' => 'DSIM', 'libelle' => 'Direction des soins', 'type' => 'DIRECTION', 'code_parent' => 'DGS']);
    $s->creer(['code' => 'DSIM-SPF', 'sigle' => 'SPF', 'libelle' => 'Planification', 'type' => 'SERVICE', 'code_parent' => 'DSIM']);
});

it('modifie le libellé, incrémente la version et historise avant/après (F4, R6)', function () {
    $this->patchJson('/api/structures/DSIM', ['libelle' => 'Direction des soins infirmiers', 'motif' => 'Renommage'])
        ->assertOk()
        ->assertJsonPath('data.libelle', 'Direction des soins infirmiers')
        ->assertJsonPath('data.version', 2);

    $h = HistoriqueStructure::where('code', 'DSIM')->where('action', 'MODIFICATION')->sole();
    expect($h->champs_modifies)->toBe(['libelle'])
        ->and($h->donnees_avant['libelle'])->toBe('Direction des soins')
        ->and($h->donnees_apres['libelle'])->toBe('Direction des soins infirmiers')
        ->and($h->motif)->toBe('Renommage');
});

it('change le rattachement vers un parent compatible', function () {
    $this->patchJson('/api/structures/DSIM', ['code_parent' => 'MIN-EDUC'])
        ->assertOk()
        ->assertJsonPath('data.parent.code', 'MIN-EDUC')
        ->assertJsonPath('data.chemin.*.code', ['MIN-EDUC', 'DSIM']);

    expect(HistoriqueStructure::where('code', 'DSIM')->latest('id')->first()->champs_modifies)->toBe(['code_parent']);
});

it('refuse un rattachement à une descendante (R4)', function () {
    // DIRECTION_GENERALE sous DIRECTION interdite par R2 ; on teste le cycle sur un type compatible.
    app(StructureService::class)->creer(['code' => 'DSIM-2', 'sigle' => 'D2', 'libelle' => 'Sous direction', 'type' => 'DIRECTION', 'code_parent' => 'DGS']);
    Structure::where('code', 'DSIM-2')->update(['parent_id' => Structure::where('code', 'DSIM')->value('id')]);
    // DSIM -> DSIM-2 n'est pas autorisé par R2 non plus : on force le cas via le validateur.
    $dsim = Structure::where('code', 'DSIM')->first();
    $fille = Structure::where('code', 'DSIM-2')->first();

    expect(fn () => app(\App\Services\HierarchieValidator::class)->verifierAbsenceDeCycle($dsim, $fille))
        ->toThrow(\App\Exceptions\ApiException::class, 'DSIM-2');
});

it('refuse un rattachement à soi-même (R4)', function () {
    $reponse = $this->patchJson('/api/structures/DGS', ['type' => 'DIRECTION', 'code_parent' => 'DGS']);

    expect($reponse->status())->toBeIn([409, 422]);
    expect(Structure::where('code', 'DGS')->value('parent_id'))->not->toBeNull();
});

it('refuse un changement de type incompatible avec les filles (R4)', function () {
    attendreErreur($this->patchJson('/api/structures/DSIM', ['type' => 'SERVICE']), 409, 'FILLES_INCOMPATIBLES')
        ->assertJsonPath('details.filles_incompatibles', ['DSIM-SPF']);
});

it('refuse une version obsolète', function () {
    attendreErreur($this->patchJson('/api/structures/DSIM', ['libelle' => 'Nouveau', 'version' => 9]), 409, 'VERSION_OBSOLETE');
});

it('refuse la modification du code (R1)', function () {
    attendreErreur($this->patchJson('/api/structures/DSIM', ['code' => 'DSIM-NEW', 'libelle' => 'X Y']), 422, 'CODE_NON_MODIFIABLE');
});

it('refuse la modification d\'une structure inactive', function () {
    app(StructureService::class)->desactiver(Structure::where('code', 'DSIM-SPF')->first(), 'Fermeture');

    attendreErreur($this->patchJson('/api/structures/DSIM-SPF', ['libelle' => 'Nouveau libellé']), 409, 'STRUCTURE_INACTIVE');
});

it('ne crée pas d\'historique si rien ne change', function () {
    $avant = HistoriqueStructure::count();

    $this->patchJson('/api/structures/DSIM', ['libelle' => 'Direction des soins'])
        ->assertOk()->assertJsonPath('data.version', 1);

    expect(HistoriqueStructure::count())->toBe($avant);
});

it('exige au moins un champ modifiable', function () {
    attendreErreur($this->patchJson('/api/structures/DSIM', []), 422, 'VALIDATION_ECHOUEE');
});

it('renvoie 404 pour une structure inconnue', function () {
    attendreErreur($this->patchJson('/api/structures/NOPE', ['libelle' => 'Abc']), 404, 'STRUCTURE_INTROUVABLE');
});
