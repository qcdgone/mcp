<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

/**
 * Une écriture refusée par les règles (champ invalide, doublon, droit
 * d'écriture absent…).
 *
 * ⚠ **C'est un RÉSULTAT en échec (`isError`), pas une erreur de protocole** :
 * l'agent a bien appelé l'outil, et il doit pouvoir lire POURQUOI rien n'a été
 * écrit, champ par champ, pour corriger et réessayer.
 */
final class EcritureRefusee extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
