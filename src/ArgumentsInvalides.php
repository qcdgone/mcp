<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

/** Les arguments d'un appel ne respectent pas le schéma de l'outil : erreur JSON-RPC -32602. */
final class ArgumentsInvalides extends \InvalidArgumentException
{
}
