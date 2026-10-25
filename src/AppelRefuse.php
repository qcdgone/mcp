<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

/**
 * Un appel arrêté AVANT d'atteindre le domaine : droit absent, plafond de débit
 * atteint — ce que lèvent les {@see Garde\GardeDAppel}.
 *
 * ⚠ **Un RÉSULTAT en échec (`isError`), pas une erreur de protocole** : l'appel
 *   est bien formé, l'agent doit lire POURQUOI il est refusé et le dire à
 *   l'utilisateur, plutôt que de réessayer autrement. Le `code` est stable, et
 *   c'est lui que le journal retient.
 */
final class AppelRefuse extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, public readonly string $code_, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
