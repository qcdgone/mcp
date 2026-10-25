<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Garde;

use Qcdgone\Mcp\AppelRefuse;
use Qcdgone\Mcp\Arguments;

/**
 * Un contrôle joué avant CHAQUE appel d'outil, lecture comprise, après la
 * validation des arguments et avant le domaine. Toute classe qui l'implémente
 * reçoit le tag `qcdgone_mcp.garde` ; `#[AsTaggedItem(priority: …)]` règle
 * l'ordre, la plus haute d'abord.
 *
 * ⚠ **L'ordre compte** : un plafond par utilisateur passe avant les droits,
 *   pour qu'un agent qui balaie les ressources une à une s'épuise sans apprendre
 *   lesquelles lui sont ouvertes.
 */
interface GardeDAppel
{
    /**
     * @param array<string, mixed> $definition la définition MCP de l'outil (`annotations.readOnlyHint`…)
     *
     * @throws AppelRefuse
     */
    public function verifier(string $outil, array $definition, Arguments $arguments): void;
}
