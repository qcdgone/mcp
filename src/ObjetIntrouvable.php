<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

/**
 * L'objet demandé n'existe pas — ou n'est pas visible de l'appelant, ce qui doit
 * se lire pareil : un RÉSULTAT en échec (`isError`), que l'agent lit.
 *
 * ⚠ **« Introuvable », jamais « interdit »**, pour un objet hors de portée :
 *   la différence apprendrait à l'agent qu'il existe.
 */
final class ObjetIntrouvable extends \RuntimeException
{
}
