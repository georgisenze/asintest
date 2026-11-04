<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier rendue telle quelle par le handler global
 * au format {erreur, message, details}.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly int $statut,
        public readonly string $erreur,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** 422 : saisie invalide. */
    public static function invalide(string $erreur, string $message, array $details = []): self
    {
        return new self(422, $erreur, $message, $details);
    }

    /** 404 : ressource introuvable. */
    public static function introuvable(string $erreur, string $message, array $details = []): self
    {
        return new self(404, $erreur, $message, $details);
    }

    /** 409 : action interdite par l'état des données. */
    public static function conflit(string $erreur, string $message, array $details = []): self
    {
        return new self(409, $erreur, $message, $details);
    }
}
