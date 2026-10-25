<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Journal;

/** Comment un appel d'outil s'est terminé. */
enum IssueDAppel: string
{
    case Ok = 'ok';
    /** Arrêté par une garde (droit, plafond) : l'appel était bien formé. */
    case Refuse = 'refused';
    case Erreur = 'error';
}
