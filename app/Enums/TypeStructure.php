<?php

namespace App\Enums;

enum TypeStructure: string
{
    case MINISTERE = 'MINISTERE';
    case DIRECTION_GENERALE = 'DIRECTION_GENERALE';
    case DIRECTION = 'DIRECTION';
    case SERVICE = 'SERVICE';

    /**
     * Types de parent autorisés (R2). Liste vide : structure racine, sans parent.
     *
     * @return list<self>
     */
    public function parentsAutorises(): array
    {
        return match ($this) {
            self::MINISTERE => [],
            self::DIRECTION_GENERALE => [self::MINISTERE],
            self::DIRECTION => [self::MINISTERE, self::DIRECTION_GENERALE],
            self::SERVICE => [self::DIRECTION_GENERALE, self::DIRECTION],
        };
    }

    public function estRacine(): bool
    {
        return $this->parentsAutorises() === [];
    }

    public function accepteCommeParent(self $parent): bool
    {
        return in_array($parent, $this->parentsAutorises(), true);
    }

    public function libelle(): string
    {
        return match ($this) {
            self::MINISTERE => 'Ministère',
            self::DIRECTION_GENERALE => 'Direction générale',
            self::DIRECTION => 'Direction',
            self::SERVICE => 'Service',
        };
    }

    /** @return list<string> */
    public static function valeurs(): array
    {
        return array_column(self::cases(), 'value');
    }
}
