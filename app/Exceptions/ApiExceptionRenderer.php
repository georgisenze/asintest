<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Convertit toute exception levée sous /api en réponse JSON unique :
 * {"erreur": CODE, "message": "texte en français", "details": {...}}.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $this->concerneApi($request)) {
            return null; // rendu Laravel standard (pages web).
        }

        [$statut, $erreur, $message, $details, $entetes] = $this->convertir($e, $request);

        return response()->json(
            [
                'erreur' => $erreur,
                'message' => $message,
                'details' => $details === [] ? new \stdClass : $details,
            ],
            $statut,
            $entetes,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public function concerneApi(Request $request): bool
    {
        return $request->is('api', 'api/*') || $request->expectsJson();
    }

    /**
     * @return array{0:int, 1:string, 2:string, 3:array<string,mixed>, 4:array<string,string>}
     */
    private function convertir(Throwable $e, Request $request): array
    {
        $route = $request->method().' /'.ltrim($request->path(), '/');

        return match (true) {
            $e instanceof ApiException => [$e->statut, $e->erreur, $e->getMessage(), $e->details, []],

            $e instanceof ValidationException => [
                422, 'VALIDATION_ECHOUEE', 'Les données fournies sont invalides.', $e->errors(), [],
            ],

            $e instanceof AuthenticationException => [
                401, 'NON_AUTHENTIFIE', 'Authentification requise : jeton absent, invalide ou expiré.', [], [],
            ],

            // Laravel convertit ModelNotFoundException en NotFoundHttpException avant le rendu.
            $e instanceof NotFoundHttpException && $e->getPrevious() instanceof ModelNotFoundException => [
                404, 'RESSOURCE_INTROUVABLE', 'La ressource demandée est introuvable.', [], [],
            ],

            $e instanceof NotFoundHttpException => [
                404, 'ROUTE_INTROUVABLE', "La route {$route} n'existe pas.", [], [],
            ],

            $e instanceof MethodNotAllowedHttpException => [
                405, 'METHODE_NON_AUTORISEE',
                "La méthode {$request->method()} n'est pas autorisée sur /{$request->path()}.",
                ['methodes_autorisees' => explode(', ', $e->getHeaders()['Allow'] ?? '')],
                $e->getHeaders(),
            ],

            $e instanceof AccessDeniedHttpException => [
                403, 'ACCES_REFUSE', "Vous n'êtes pas autorisé à effectuer cette action.", [], [],
            ],

            $e instanceof ThrottleRequestsException => [
                429, 'TROP_DE_REQUETES', 'Trop de requêtes. Réessayez plus tard.', [], $e->getHeaders(),
            ],

            $e instanceof PostTooLargeException => [
                413, 'REQUETE_TROP_VOLUMINEUSE', 'Le corps de la requête dépasse la taille autorisée.', [], [],
            ],

            $e instanceof QueryException => $this->convertirErreurBase($e),

            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(), 'ERREUR_HTTP',
                $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$e->getStatusCode()] ?? 'Erreur HTTP'),
                [], $e->getHeaders(),
            ],

            default => $this->erreurInterne($e),
        };
    }

    /**
     * Filet de sécurité : une valeur rejetée par PostgreSQL (format, contrainte)
     * produit une 422 ou une 409, jamais une 500. Le SQL n'est jamais exposé.
     */
    private function convertirErreurBase(QueryException $e): array
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        return match (true) {
            $sqlState === '23505' => [
                409, 'CONFLIT_UNICITE', 'Une donnée identique existe déjà (contrainte d\'unicité).', [], [],
            ],
            $sqlState === '23503' => [
                409, 'CONFLIT_REFERENCE', 'Opération impossible : elle casserait une référence entre données.', [], [],
            ],
            in_array($sqlState, ['40001', '40P01'], true) => [
                409, 'CONFLIT_CONCURRENCE', 'Conflit avec une opération concurrente. Réessayez.', [], [],
            ],
            in_array($sqlState, ['23502', '23514'], true), str_starts_with($sqlState, '22') => [
                422, 'DONNEE_INVALIDE', 'Une valeur fournie a un format ou une taille non accepté.', [], [],
            ],
            default => $this->erreurInterne($e),
        };
    }

    private function erreurInterne(Throwable $e): array
    {
        $details = config('app.debug')
            ? ['exception' => $e::class, 'message_technique' => $e->getMessage()]
            : [];

        return [500, 'ERREUR_INTERNE', 'Une erreur interne est survenue.', $details, []];
    }
}
