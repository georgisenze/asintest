<?php

use App\Models\User;
use App\Services\StructureService;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
    $s = app(StructureService::class);
    $s->creer(['code' => 'MIN-SANTE', 'sigle' => 'MSHP', 'libelle' => 'Ministère de la Santé', 'type' => 'MINISTERE']);
    $s->creer(['code' => 'DGS', 'sigle' => 'DGS', 'libelle' => 'DG Santé', 'type' => 'DIRECTION_GENERALE', 'code_parent' => 'MIN-SANTE']);
    $s->creer(['code' => 'DSIM', 'sigle' => 'DSIM', 'libelle' => 'Direction des soins', 'type' => 'DIRECTION', 'code_parent' => 'DGS']);
    $s->creer(['code' => 'DSIM-SPF', 'sigle' => 'SPF', 'libelle' => 'Planification', 'type' => 'SERVICE', 'code_parent' => 'DSIM']);
    $s->creer(['code' => 'DSIM-SVAC', 'sigle' => 'SVAC', 'libelle' => 'Vaccination', 'type' => 'SERVICE', 'code_parent' => 'DSIM']);
});

it('renvoie la structure avec son parent, ses filles et son chemin (F2)', function () {
    $this->getJson('/api/structures/dsim')
        ->assertOk()
        ->assertJsonPath('data.code', 'DSIM')
        ->assertJsonPath('data.parent.code', 'DGS')
        ->assertJsonPath('data.filles.*.code', ['DSIM-SPF', 'DSIM-SVAC'])
        ->assertJsonPath('data.chemin.*.code', ['MIN-SANTE', 'DGS', 'DSIM']);
});

it('renvoie un parent nul et un chemin réduit pour un ministère', function () {
    $this->getJson('/api/structures/MIN-SANTE')
        ->assertOk()
        ->assertJsonPath('data.parent', null)
        ->assertJsonPath('data.chemin.*.code', ['MIN-SANTE'])
        ->assertJsonPath('data.filles.*.code', ['DGS']);
});

it('renvoie 404 STRUCTURE_INTROUVABLE avec le code dans le message', function () {
    $reponse = $this->getJson('/api/structures/inconnue');

    attendreErreur($reponse, 404, 'STRUCTURE_INTROUVABLE')->assertJsonPath('details.code', 'INCONNUE');
    expect($reponse->json('message'))->toContain('INCONNUE');
});

it('expose les dates en ISO 8601 UTC', function () {
    expect($this->getJson('/api/structures/DGS')->json('data.modifie_le'))
        ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});
