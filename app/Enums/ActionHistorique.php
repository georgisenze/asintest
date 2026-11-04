<?php

namespace App\Enums;

enum ActionHistorique: string
{
    case CREATION = 'CREATION';
    case MODIFICATION = 'MODIFICATION';
    case DESACTIVATION = 'DESACTIVATION';
}
