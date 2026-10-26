<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Appel;

/**
 * Ce qui entoure l'appel au domaine, APRÈS les gardes : un cache ouvert le
 * temps d'un outil de lecture, une mesure. Toute classe qui l'implémente reçoit
 * le tag `qcdgone_mcp.enveloppe` ; `#[AsTaggedItem(priority: …)]` règle l'ordre,
 * la plus haute la plus extérieure.
 *
 * ⚠ **Elle n'entoure que le domaine** : un appel mal formé ou refusé par une
 *   garde ne l'atteint pas. Elle décide d'après la définition
 *   (`annotations.readOnlyHint`…), pas d'après le nom de l'outil.
 *
 * ⚠ Elle rend ce que rend `$appel()`, et laisse passer ses exceptions : le
 *   serveur doit les voir pour les rendre à l'agent ou les laisser remonter.
 */
interface EnveloppeDAppel
{
    /**
     * @param array<string, mixed>                $definition la définition MCP de l'outil
     * @param \Closure(): array<string, mixed>    $appel      l'appel au domaine (ou à l'enveloppe suivante)
     *
     * @return array<string, mixed>
     */
    public function envelopper(string $outil, array $definition, \Closure $appel): array;
}
