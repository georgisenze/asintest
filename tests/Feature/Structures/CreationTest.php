<?php

use App\Models\HistoriqueStructure;
use App\Models\Structure;
use App\Models\User;
use App\Services\StructureService;
use Laravel\Sanctum\Sanctum;

if (! function_exists('creerViaService')) {
    function creerViaService(string $code, string $type, ?string $parent = null, ?string $sigle = null): Structure
    {
        return app(StructureService::class)->creer([
            'code' => $code, 'sigle' => $sigle ?? $code, 'libelle' => 'Libellé '.$code,
            'type' => $type, 'code_parent' => $parent,
        ]);
    }
}

beforeEach(function () {
    $this->auteur = User::factory()->create();
    Sanctum::actingAs($this->auteur);
});

it('crée un ministère, renvoie 201, Location et écrit l\'historique CREATION', function () {
    $reponse = $this->postJson('/api/structures', [
        'code' => 'MIN-SANTE', 'sigle' => 'MSHP', 'libelle' => 'Ministère de la Santé', 'type' => 'MINISTERE',
    ]);

    $reponse->assertCreated()
        ->assertHeader('Location', url('/api/structures/MIN-SANTE'))
        ->assertJsonPath('data.code', 'MIN-SANTE')
        ->assertJsonPath('data.statut', 'ACTIVE')
        ->assertJsonPath('data.code_parent', null)
        ->assertJsonPath('data.version', 1);

    expect($reponse->json('data.cree_le'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $historique = HistoriqueStructure::sole();
    expect($historique->action->value)->toBe('CREATION')
        ->and($historique->code)->toBe('MIN-SANTE')
        ->and($historique->donnees_avant)->toBeNull()
        ->and($historique->donnees_apres['libelle'])->toBe('Ministère de la Santé')
        ->and($historique->utilisateur_id)->toBe($this->auteur->id);
});

it('crée une structure rattachée à un parent compatible', function () {
    creerViaService('MIN-SANTE', 'MINISTERE');
    creerViaService('DGS', 'DIRECTION_GENERALE', 'MIN-SANTE');

    $this->postJson('/api/structures', [
        'code' => 'DSIM', 'sigle' => 'DSIM', 'libelle' => 'Direction des soins', 'type' => 'DIRECTION', 'code_parent' => 'dgs',
    ])->assertCreated()
        ->assertJsonPath('data.code_parent', 'DGS')
        ->assertJsonPath('data.parent.code', 'DGS')
        ->assertJsonPath('data.chemin.0.code', 'MIN-SANTE');
});

it('normalise le code et le type en majuscules (R1)', function () {
    $this->postJson('/api/structures', [
        'code' => '  min-sante ', 'sigle' => 'MSHP', 'libelle' => 'Ministère de la Santé', 'type' => 'ministere',
    ])->assertCreated()->assertJsonPath('data.code', 'MIN-SANTE');

    expect(Structure::where('code', 'MIN-SANTE')->exists())->toBeTrue();
});

it('rejette un code au format invalide en 422 (R1)', function (mixed $code) {
    attendreErreur($this->postJson('/api/structures', [
        'code' => $code, 'sigle' => 'X', 'libelle' => 'Libellé', 'type' => 'MINISTERE',
    ]), 422, 'VALIDATION_ECHOUEE')->assertJsonStructure(['details' => ['code']]);
})->with([
    'trop court' => ['A'],
    'espace' => ['MIN SANTE'],
    'accent' => ['MIN-SANTÉ'],
    'commence par un tiret' => ['-MIN'],
    'trop long' => [str_repeat('A', 31)],
    'tableau' => [['MIN']],
    'entier' => [12345],
]);

it('rejette les champs obligatoires absents et un type inconnu en 422', function () {
    attendreErreur($this->postJson('/api/structures', []), 422, 'VALIDATION_ECHOUEE')
        ->assertJsonStructure(['details' => ['code', 'sigle', 'libelle', 'type']]);

    attendreErreur($this->postJson('/api/structures', [
        'code' => 'MIN-X', 'sigle' => 'X', 'libelle' => 'Libellé', 'type' => 'AGENCE',
    ]), 422, 'VALIDATION_ECHOUEE')->assertJsonStructure(['details' => ['type']]);
});

it('rejette un code déjà existant en 409 (R1)', function () {
    creerViaService('MIN-SANTE', 'MINISTERE');

    attendreErreur($this->postJson('/api/structures', [
        'code' => 'min-sante', 'sigle' => 'AUTRE', 'libelle' => 'Autre', 'type' => 'MINISTERE',
    ]), 409, 'CODE_EXISTANT')->assertJsonPath('details.code', 'MIN-SANTE');

    expect(Structure::count())->toBe(1);
});

it('refuse un ministère rattaché à un parent (R2)', function () {
    creerViaService('MIN-SANTE', 'MINISTERE');

    $reponse = $this->postJson('/api/structures', [
        'code' => 'MIN-EDUC', 'sigle' => 'MENA', 'libelle' => 'Éducation', 'type' => 'MINISTERE', 'code_parent' => 'MIN-SANTE',
    ]);

    attendreErreur($reponse, 422, 'HIERARCHIE_INVALIDE');
    expect($reponse->json('message'))->toContain('MIN-EDUC')->toContain('MIN-SANTE');
});

it('refuse un service sans parent (R2)', function () {
    attendreErreur($this->postJson('/api/structures', [
        'code' => 'SRV-X', 'sigle' => 'SX', 'libelle' => 'Service X', 'type' => 'SERVICE',
    ]), 422, 'HIERARCHIE_INVALIDE')
        ->assertJsonPath('details.types_parent_autorises', ['DIRECTION_GENERALE', 'DIRECTION']);
});

it('refuse un service directement sous un ministère (R2)', function () {
    creerViaService('MIN-SANTE', 'MINISTERE');

    attendreErreur($this->postJson('/api/structures', [
        'code' => 'SRV-X', 'sigle' => 'SX', 'libelle' => 'Service X', 'type' => 'SERVICE', 'code_parent' => 'MIN-SANTE',
    ]), 422, 'HIERARCHIE_INVALIDE')
        ->assertJsonPath('details.type_parent', 'MINISTERE');
});

it('refuse une direction générale sous une direction (R2)', function () {
    creerViaService('MIN-SANTE', 'MINISTERE');
    creerViaService('DRH', 'DIRECTION', 'MIN-SANTE');

    attendreErreur($this->postJson('/api/structures', [
        'code' => 'DG-X', 'sigle' => 'DGX', 'libelle' => 'DG X', 'type' => 'DIRECTION_GENERALE', 'code_parent' => 'DRH',
    ]), 422, 'HIERARCHIE_INVALIDE');
});

it('refuse un parent inexistant en 422', function () {
    $reponse = $this->postJson('/api/structures', [
        'code' => 'DGS', 'sigle' => 'DGS', 'libelle' => 'DG Santé', 'type' => 'DIRECTION_GENERALE', 'code_parent' => 'MIN-FANTOME',
    ]);

    attendreErreur($reponse, 422, 'PARENT_INTROUVABLE')->assertJsonPath('details.code_parent', 'MIN-FANTOME');
    expect($reponse->json('message'))->toContain('MIN-FANTOME');
});

it('refuse un parent inactif en 409 (R3)', function () {
    $ministere = creerViaService('MIN-SANTE', 'MINISTERE');
    app(StructureService::class)->desactiver($ministere, 'Fusion');

    attendreErreur($this->postJson('/api/structures', [
        'code' => 'DGS', 'sigle' => 'DGS', 'libelle' => 'DG Santé', 'type' => 'DIRECTION_GENERALE', 'code_parent' => 'MIN-SANTE',
    ]), 409, 'PARENT_INACTIF')->assertJsonPath('details.code_parent', 'MIN-SANTE');

    expect(Structure::where('code', 'DGS')->exists())->toBeFalse();
});

it('refuse un sigle déjà utilisé par une structure active de même parent (R7)', function () {
    creerViaService('MIN-SANTE', 'MINISTERE');
    creerViaService('DRH-SANTE', 'DIRECTION', 'MIN-SANTE', 'DRH');

    attendreErreur($this->postJson('/api/structures', [
        'code' => 'DRH-BIS', 'sigle' => 'drh', 'libelle' => 'Autre DRH', 'type' => 'DIRECTION', 'code_parent' => 'MIN-SANTE',
    ]), 409, 'SIGLE_EN_DOUBLON')->assertJsonPath('details.code_existant', 'DRH-SANTE');
});

it('accepte le même sigle sous un autre parent (R7)', function () {
    creerViaService('MIN-SANTE', 'MINISTERE');
    creerViaService('MIN-EDUC', 'MINISTERE');
    creerViaService('DRH-SANTE', 'DIRECTION', 'MIN-SANTE', 'DRH');

    $this->postJson('/api/structures', [
        'code' => 'DRH-EDUC', 'sigle' => 'DRH', 'libelle' => 'DRH Éducation', 'type' => 'DIRECTION', 'code_parent' => 'MIN-EDUC',
    ])->assertCreated();
});

it('accepte un sigle porté par une structure inactive de même parent (R7)', function () {
    creerViaService('MIN-SANTE', 'MINISTERE');
    $ancienne = creerViaService('DRH-ANCIENNE', 'DIRECTION', 'MIN-SANTE', 'DRH');
    app(StructureService::class)->desactiver($ancienne, 'Remplacée');

    $this->postJson('/api/structures', [
        'code' => 'DRH-SANTE', 'sigle' => 'DRH', 'libelle' => 'Nouvelle DRH', 'type' => 'DIRECTION', 'code_parent' => 'MIN-SANTE',
    ])->assertCreated();
});

it('ne renvoie jamais 500 sur des entrées farfelues', function (array $corps) {
    $reponse = $this->postJson('/api/structures', $corps);

    expect($reponse->status())->toBeIn([422, 409])
        ->and($reponse->json())->toHaveKeys(['erreur', 'message', 'details']);
})->with([
    'libellé tableau' => [['code' => 'MIN-X', 'sigle' => 'X', 'libelle' => ['a' => 'b'], 'type' => 'MINISTERE']],
    'libellé énorme' => [['code' => 'MIN-X', 'sigle' => 'X', 'libelle' => str_repeat('é', 100000), 'type' => 'MINISTERE']],
    'sigle entier' => [['code' => 'MIN-X', 'sigle' => 42, 'libelle' => 'Libellé', 'type' => 'MINISTERE']],
    'type tableau' => [['code' => 'MIN-X', 'sigle' => 'X', 'libelle' => 'Libellé', 'type' => ['MINISTERE']]],
    'parent tableau' => [['code' => 'DGX', 'sigle' => 'X', 'libelle' => 'Libellé', 'type' => 'DIRECTION_GENERALE', 'code_parent' => ['MIN']]],
    'parent entier' => [['code' => 'DGX', 'sigle' => 'X', 'libelle' => 'Libellé', 'type' => 'DIRECTION_GENERALE', 'code_parent' => 7]],
    'octets nuls' => [['code' => 'MIN-X', 'sigle' => "X\0", 'libelle' => "Lib\0ellé", 'type' => 'MINISTERE']],
]);
