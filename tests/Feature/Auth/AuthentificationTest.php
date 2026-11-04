<?php

use App\Models\User;

beforeEach(function () {
    $this->utilisateur = User::factory()->create([
        'email' => 'agent@referentiel.local',
        'password' => 'secret123',
    ]);
});

it('connecte un utilisateur et renvoie un jeton Bearer', function () {
    $reponse = $this->postJson('/api/auth/login', [
        'email' => 'agent@referentiel.local',
        'password' => 'secret123',
    ]);

    $reponse->assertOk()
        ->assertJsonStructure(['jeton', 'type', 'expire_le', 'utilisateur' => ['id', 'nom', 'email']])
        ->assertJsonPath('type', 'Bearer')
        ->assertJsonPath('utilisateur.email', 'agent@referentiel.local');

    expect($reponse->json('expire_le'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('accepte un e-mail en majuscules', function () {
    $this->postJson('/api/auth/login', ['email' => 'AGENT@referentiel.local', 'password' => 'secret123'])
        ->assertOk();
});

it('refuse un mauvais mot de passe en 401', function () {
    attendreErreur(
        $this->postJson('/api/auth/login', ['email' => 'agent@referentiel.local', 'password' => 'faux']),
        401, 'IDENTIFIANTS_INVALIDES'
    );
});

it('refuse un compte inconnu avec le même message', function () {
    $inconnu = $this->postJson('/api/auth/login', ['email' => 'inconnu@referentiel.local', 'password' => 'secret123']);
    $mauvais = $this->postJson('/api/auth/login', ['email' => 'agent@referentiel.local', 'password' => 'faux']);

    attendreErreur($inconnu, 401, 'IDENTIFIANTS_INVALIDES');
    expect($inconnu->json('message'))->toBe($mauvais->json('message'));
});

it('valide le format des identifiants en 422', function (array $corps, string $champ) {
    attendreErreur($this->postJson('/api/auth/login', $corps), 422, 'VALIDATION_ECHOUEE')
        ->assertJsonStructure(['details' => [$champ]]);
})->with([
    'email absent' => [['password' => 'x'], 'email'],
    'email invalide' => [['email' => 'pas-un-email', 'password' => 'x'], 'email'],
    'mot de passe absent' => [['email' => 'agent@referentiel.local'], 'password'],
    'email tableau' => [['email' => ['a'], 'password' => 'x'], 'email'],
]);

it('renvoie l\'utilisateur courant avec /me', function () {
    $jeton = $this->postJson('/api/auth/login', ['email' => 'agent@referentiel.local', 'password' => 'secret123'])->json('jeton');

    $this->withToken($jeton)->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('email', 'agent@referentiel.local');
});

it('révoque le jeton à la déconnexion', function () {
    $jeton = $this->postJson('/api/auth/login', ['email' => 'agent@referentiel.local', 'password' => 'secret123'])->json('jeton');

    $this->withToken($jeton)->postJson('/api/auth/logout')->assertNoContent();

    expect($this->utilisateur->tokens()->count())->toBe(0);

    // Nouvelle instance de l'application : le garde ne garde pas l'utilisateur en cache.
    $this->app['auth']->forgetGuards();
    attendreErreur($this->withToken($jeton)->getJson('/api/auth/me'), 401, 'NON_AUTHENTIFIE');
});

it('renvoie 401 sans jeton ou avec un jeton invalide', function () {
    attendreErreur($this->getJson('/api/auth/me'), 401, 'NON_AUTHENTIFIE');
    attendreErreur($this->getJson('/api/structures/MIN-SANTE'), 401, 'NON_AUTHENTIFIE');
    attendreErreur($this->withToken('1|faux')->getJson('/api/auth/me'), 401, 'NON_AUTHENTIFIE');
});

it('refuse un jeton expiré', function () {
    $jeton = $this->utilisateur->createToken('test', ['*'], now()->subMinute())->plainTextToken;

    attendreErreur($this->withToken($jeton)->getJson('/api/auth/me'), 401, 'NON_AUTHENTIFIE');
});
