<?php

it('affiche Swagger UI sur /docs', function () {
    $this->get('/docs')
        ->assertOk()
        ->assertSee('swagger-ui', false)
        ->assertSee('openapi.yaml', false);
});

it('sert le contrat OpenAPI', function () {
    $reponse = $this->get('/docs/openapi.yaml')->assertOk();

    expect($reponse->headers->get('Content-Type'))->toContain('application/yaml');
    expect(file_get_contents($reponse->baseResponse->getFile()->getPathname()))->toContain('openapi: 3.1');
});
