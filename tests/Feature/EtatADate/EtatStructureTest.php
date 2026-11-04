<?php

use App\Models\User;
use App\Services\StructureService;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
 * Chronologie (UTC) :
 * 2026-01-10 09:00  création MIN-A, MIN-B, DG-A (sous MIN-A), DIR-X (sous DG-A)
 * 2026-02-01 15:00  DIR-X change de libellé
 * 2026-02-20 08:00  DG-A change de libellé (le parent change de nom)
 * 2026-03-01 10:00  DIR-X rattachée directement à MIN-B
 * 2026-04-01 12:00  DIR-X désactivée
 * 2026-05-01 00:00  consultation
 */
beforeEach(function () {
    $service = app(StructureService::class);
    $auteur = User::factory()->create();

    $this->travelTo(CarbonImmutable::parse('2026-01-10T09:00:00Z'));
    $service->creer(['code' => 'MIN-A', 'sigle' => 'MA', 'libelle' => 'Ministère A', 'type' => 'MINISTERE'], $auteur);
    $service->creer(['code' => 'MIN-B', 'sigle' => 'MB', 'libelle' => 'Ministère B', 'type' => 'MINISTERE'], $auteur);
    $service->creer(['code' => 'DG-A', 'sigle' => 'DGA', 'libelle' => 'Direction générale A', 'type' => 'DIRECTION_GENERALE', 'code_parent' => 'MIN-A'], $auteur);
    $dir = $service->creer(['code' => 'DIR-X', 'sigle' => 'DX', 'libelle' => 'Direction X', 'type' => 'DIRECTION', 'code_parent' => 'DG-A'], $auteur);

    $this->travelTo(CarbonImmutable::parse('2026-02-01T15:00:00Z'));
    $dir = $service->modifier($dir, ['libelle' => 'Direction X renommée'], $auteur);

    $this->travelTo(CarbonImmutable::parse('2026-02-20T08:00:00Z'));
    $service->modifier($service->trouverParCode('DG-A'), ['libelle' => 'Direction générale A bis'], $auteur);

    $this->travelTo(CarbonImmutable::parse('2026-03-01T10:00:00Z'));
    $dir = $service->modifier($dir, ['code_parent' => 'MIN-B'], $auteur);

    $this->travelTo(CarbonImmutable::parse('2026-04-01T12:00:00Z'));
    $service->desactiver($dir, 'Suppression de la direction', $auteur);

    $this->travelTo(CarbonImmutable::parse('2026-05-01T00:00:00Z'));
    Sanctum::actingAs($auteur);
});

it('renvoie l\'état initial juste après la création', function () {
    $this->getJson('/api/structures/DIR-X/etat?date=2026-01-15T00:00:00Z')
        ->assertOk()
        ->assertJsonPath('data.code', 'DIR-X')
        ->assertJsonPath('data.libelle', 'Direction X')
        ->assertJsonPath('data.statut', 'ACTIVE')
        ->assertJsonPath('data.code_parent', 'DG-A')
        ->assertJsonPath('data.parent.code', 'DG-A')
        ->assertJsonPath('data.parent.libelle', 'Direction générale A')
        ->assertJsonPath('meta.date_demandee', '2026-01-15T00:00:00Z')
        ->assertJsonPath('meta.etat_au', '2026-01-10T09:00:00Z')
        ->assertJsonPath('meta.action', 'CREATION');
});

it('prend en compte une modification à l\'instant exact où elle a eu lieu', function () {
    $this->getJson('/api/structures/DIR-X/etat?date=2026-02-01T14:59:59Z')
        ->assertJsonPath('data.libelle', 'Direction X');

    $this->getJson('/api/structures/DIR-X/etat?date=2026-02-01T15:00:00Z')
        ->assertJsonPath('data.libelle', 'Direction X renommée')
        ->assertJsonPath('meta.action', 'MODIFICATION')
        ->assertJsonPath('meta.etat_au', '2026-02-01T15:00:00Z');
});

it('donne l\'état du parent tel qu\'il était à la date demandée', function () {
    $this->getJson('/api/structures/DIR-X/etat?date=2026-02-10T00:00:00Z')
        ->assertJsonPath('data.parent.libelle', 'Direction générale A');

    $this->getJson('/api/structures/DIR-X/etat?date=2026-02-25T00:00:00Z')
        ->assertJsonPath('data.code_parent', 'DG-A')
        ->assertJsonPath('data.parent.libelle', 'Direction générale A bis');
});

it('reflète un changement de rattachement', function () {
    $this->getJson('/api/structures/DIR-X/etat?date=2026-03-15T00:00:00Z')
        ->assertJsonPath('data.code_parent', 'MIN-B')
        ->assertJsonPath('data.parent.code', 'MIN-B')
        ->assertJsonPath('data.statut', 'ACTIVE');
});

it('reflète la désactivation après sa date et pas avant', function () {
    $this->getJson('/api/structures/DIR-X/etat?date=2026-04-01T11:59:59Z')
        ->assertJsonPath('data.statut', 'ACTIVE');

    $this->getJson('/api/structures/DIR-X/etat?date=2026-04-02T00:00:00Z')
        ->assertJsonPath('data.statut', 'INACTIVE')
        ->assertJsonPath('data.motif_desactivation', 'Suppression de la direction')
        ->assertJsonPath('data.date_desactivation', '2026-04-01T12:00:00Z')
        ->assertJsonPath('meta.action', 'DESACTIVATION');
});

it('interprète une date sans heure comme la fin de cette journée UTC', function () {
    $this->getJson('/api/structures/DIR-X/etat?date=2026-02-01')
        ->assertOk()
        ->assertJsonPath('data.libelle', 'Direction X renommée')
        ->assertJsonPath('meta.date_demandee', '2026-02-01T23:59:59Z');
});

it('accepte un fuseau horaire et le "+" non encodé de la query string', function () {
    // 16:00+01:00 = 15:00Z, instant exact de la modification. Le "+" brut devient une espace.
    $this->getJson('/api/structures/DIR-X/etat?date=2026-02-01T16:00:00+01:00')
        ->assertOk()
        ->assertJsonPath('data.libelle', 'Direction X renommée')
        ->assertJsonPath('meta.date_demandee', '2026-02-01T15:00:00Z');
});

it('renvoie 404 STRUCTURE_INEXISTANTE_A_DATE avant la création', function () {
    attendreErreur($this->getJson('/api/structures/DIR-X/etat?date=2026-01-10T08:59:59Z'), 404, 'STRUCTURE_INEXISTANTE_A_DATE')
        ->assertJsonPath('details.code', 'DIR-X')
        ->assertJsonPath('details.date', '2026-01-10T08:59:59Z')
        ->assertJsonPath('details.existe_depuis', '2026-01-10T09:00:00Z')
        ->assertJsonPath('message', "La structure DIR-X n'existait pas encore au 2026-01-10T08:59:59Z.");
});

it('renvoie 404 STRUCTURE_INTROUVABLE pour un code inconnu', function () {
    attendreErreur($this->getJson('/api/structures/INCONNUE/etat?date=2026-01-15T00:00:00Z'), 404, 'STRUCTURE_INTROUVABLE');
});

it('est insensible à la casse du code', function () {
    $this->getJson('/api/structures/dir-x/etat?date=2026-01-15T00:00:00Z')
        ->assertOk()->assertJsonPath('data.code', 'DIR-X');
});

it('exige le paramètre date', function () {
    attendreErreur($this->getJson('/api/structures/DIR-X/etat'), 422, 'VALIDATION_ECHOUEE')
        ->assertJsonStructure(['details' => ['date']]);
});

it('rejette une date qui n\'est pas au format ISO 8601', function (string $date) {
    attendreErreur($this->getJson('/api/structures/DIR-X/etat?date='.urlencode($date)), 422, 'VALIDATION_ECHOUEE')
        ->assertJsonStructure(['details' => ['date']]);
})->with([
    'format français' => '31/01/2026',
    'jour inexistant' => '2026-02-30',
    'heure sans fuseau' => '2026-01-31T10:00:00',
    'texte' => 'hier',
]);

it('rejette une date future', function () {
    attendreErreur($this->getJson('/api/structures/DIR-X/etat?date=2026-05-01T00:00:01Z'), 422, 'VALIDATION_ECHOUEE')
        ->assertJsonPath('details.date.0', "La date 2026-05-01T00:00:01Z est dans le futur : seul l'état à une date passée ou présente peut être consulté.");
});

it('accepte la date du jour sans heure en la plafonnant à maintenant', function () {
    $this->getJson('/api/structures/DIR-X/etat?date=2026-05-01')
        ->assertOk()
        ->assertJsonPath('meta.date_demandee', '2026-05-01T00:00:00Z')
        ->assertJsonPath('data.statut', 'INACTIVE');
});
