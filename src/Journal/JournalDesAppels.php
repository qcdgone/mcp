<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Journal;

/**
 * Où va la trace de chaque appel d'outil — facultatif : sans implémentation,
 * le serveur ne journalise rien de plus que `qcdgone/api` (une ligne par
 * requête HTTP, sans le nom de l'outil).
 *
 * ⚠ **Une panne du journal ne doit pas casser l'appel** : l'implémentation
 *   rattrape ses propres erreurs. L'inverse rendrait le MCP inutilisable pour
 *   une table pleine.
 *
 * ⚠ **Les arguments arrivent BRUTS** : l'implémentation les expurge, par
 *   {@see ParametresSansSecret}, avant de les écrire.
 */
interface JournalDesAppels
{
    /** @param array<string, mixed> $arguments */
    public function consigner(string $outil, array $arguments, int $dureeMs, IssueDAppel $issue, ?string $code = null): void;
}
