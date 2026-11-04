<?php

use App\Models\User;
use App\Services\StructureService;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
    $this->service = app(StructureService::class);
    $this->t0 = CarbonImmutable::parse('2026-01-10T08:00:00Z');
    $this->travelTo($this->t0);
    $this->service->creer(['code' => 'MIN-A', 'sigle' => 'MA', 'libelle' => 'Ministère A', 'type' => 'MINISTERE']);
    $this->service->creer(['code' => 'DIR-A', 'sigle' => 'DA', 'libelle' => 'Direction A', 'type' => 'DIRECTION', 'code_parent' => 'MIN-A']);
});

function codesSync(\Illuminate\Testing\TestResponse $r): array
{
    return collect($r->json('data'))->pluck('code')->all();
}

it('renvoie les créations, modifications et désactivations postérieures à depuis', function () {
    $this->travelTo($this->t0->addHour());
    $this->service->modifier($this->service->trouverParCode('MIN-A'), ['libelle' => 'Ministère A modifié']);
    $this->travelTo($this->t0->addHours(2));
    $this->service->desactiver($this->service->trouverParCode('DIR-A'), 'Fusion');
    $this->service->creer(['code' => 'MIN-B', 'sigle' => 'MB', 'libelle' => 'Ministère B', 'type' => 'MINISTERE']);

    $r = $this->getJson('/api/structures/synchronisation?depuis=2026-01-10T08:30:00Z')->assertOk();

    expect(codesSync($r))->toBe(['MIN-A', 'DIR-A', 'MIN-B'])
        ->and(collect($r->json('data'))->firstWhere('code', 'DIR-A')['statut'])->toBe('INACTIVE')
        ->and($r->json('synchronisation.complet'))->toBeTrue()
        ->and($r->json('synchronisation.horodatage_serveur'))->toBe('2026-01-10T09:59:55Z');
});

it('ne renvoie rien quand rien n\'a changé depuis', function () {
    $this->travelTo($this->t0->addDay());

    $r = $this->getJson('/api/structures/synchronisation?depuis=2026-01-10T09:00:00%2B01:00')->assertOk();

    expect(codesSync($r))->toBe([])->and($r->json('synchronisation.depuis'))->toBe('2026-01-10T08:00:00Z');
});

it('exclut une modification faite exactement à la date depuis (borne stricte)', function () {
    $this->travelTo($this->t0->addMinute());

    expect(codesSync($this->getJson('/api/structures/synchronisation?depuis=2026-01-10T08:00:00Z')))->toBe([])
        ->and(codesSync($this->getJson('/api/structures/synchronisation?depuis=2026-01-10T07:59:59Z')))->toBe(['DIR-A', 'MIN-A']);
});

it('accepte un + non encodé dans depuis', function () {
    $this->travelTo($this->t0->addMinute());

    expect(codesSync($this->getJson('/api/structures/synchronisation?depuis=2026-01-10T08:59:59+01:00')->assertOk()))->toBe(['DIR-A', 'MIN-A']);
});

it('pagine par jeu de clés sans perte ni doublon', function () {
    $this->travelTo($this->t0->addMinute());

    $p1 = $this->getJson('/api/structures/synchronisation?depuis=2026-01-01T00:00:00Z&limite=1')->assertOk();
    expect(codesSync($p1))->toBe(['DIR-A'])->and($p1->json('synchronisation.complet'))->toBeFalse();

    $suivant = $p1->json('synchronisation.suivant');
    $p2 = $this->getJson('/api/structures/synchronisation?'.http_build_query($suivant))->assertOk();

    expect(codesSync($p2))->toBe(['MIN-A'])->and($p2->json('synchronisation.complet'))->toBeTrue()
        ->and($p2->json('synchronisation.suivant'))->toBeNull();
});

it('rejette un depuis absent, mal formé ou futur en 422', function (string $query) {
    $this->travelTo($this->t0->addMinute());

    attendreErreur($this->getJson('/api/structures/synchronisation'.$query), 422, 'VALIDATION_ECHOUEE')
        ->assertJsonStructure(['details' => ['depuis']]);
})->with([
    'absent' => [''],
    'texte' => ['?depuis=hier'],
    'sans fuseau' => ['?depuis=2026-01-10T08:00:00'],
    'date seule' => ['?depuis=2026-01-10'],
    'date impossible' => ['?depuis=2026-02-30T08:00:00Z'],
    'futur' => ['?depuis=2030-01-01T00:00:00Z'],
]);

it('rejette une limite hors bornes en 422', function () {
    attendreErreur($this->getJson('/api/structures/synchronisation?depuis=2026-01-01T00:00:00Z&limite=5000'), 422, 'VALIDATION_ECHOUEE');
});
