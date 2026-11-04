<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sur /api : force la négociation JSON (erreurs comprises, y compris 401 sans
 * redirection vers une page de login) et rejette un corps JSON malformé en 422
 * au lieu de le traiter silencieusement comme vide.
 */
class ForceJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api', 'api/*')) {
            return $next($request);
        }

        $request->headers->set('Accept', 'application/json');

        $this->verifierCorpsJson($request);

        return $next($request);
    }

    private function verifierCorpsJson(Request $request): void
    {
        if (! $request->isJson()) {
            return;
        }

        $contenu = trim((string) $request->getContent());
        if ($contenu === '') {
            return;
        }

        try {
            $donnees = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ApiException::invalide(
                'JSON_INVALIDE',
                'Le corps de la requête n\'est pas un JSON valide.',
                ['analyse' => $e->getMessage()],
            );
        }

        if (! is_array($donnees)) {
            throw ApiException::invalide(
                'JSON_INVALIDE',
                'Le corps de la requête doit être un objet JSON.',
            );
        }
    }
}
