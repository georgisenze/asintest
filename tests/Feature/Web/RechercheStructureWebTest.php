<?php

use App\Services\StructureService;

beforeEach(function () {
    $service = app(StructureService::class);
    $service->creer(['code' => 'MIN-EDUC', 'sigle' => 'MENA', 'libelle' => 'Ministère de l\'Éducation nationale', 'type' => 'MINISTERE']);
    $service->creer(['code' => 'MIN-XSS', 'sigle' => 'XSS', 'libelle' => '<script>alert(1)</script>', 'type' => 'MINISTERE']);
});

it('affiche l\'écran de recherche avec les résultats', function () {
    $this->get('/structures?q=education')
        ->assertOk()
        ->assertSee('Référentiel des structures administratives')
        ->assertSee('MIN-EDUC')
        ->assertDontSee('MIN-XSS')
        ->assertSee('1 structure(s) trouvée(s).');
});

it('échappe les données affichées (pas de XSS)', function () {
    $this->get('/structures?q=xss')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('échappe la saisie réinjectée dans le formulaire', function () {
    $this->get('/structures?q='.urlencode('"><script>x</script>'))
        ->assertOk()
        ->assertDontSee('"><script>x</script>', false);
});

it('affiche les erreurs de critères au lieu de planter', function () {
    $this->get('/structures?type=AGENCE')
        ->assertOk()
        ->assertSee('role="alert"', false)
        ->assertSee('Le type doit valoir');
});

it('redirige la racine vers l\'écran de recherche', function () {
    $this->get('/')->assertRedirect('/structures');
});
