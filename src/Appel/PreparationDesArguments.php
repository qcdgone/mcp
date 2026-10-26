<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Appel;

use Qcdgone\Mcp\ArgumentsInvalides;
use Qcdgone\Mcp\ObjetIntrouvable;

/**
 * Les arguments d'un appel, remis en forme AVANT les gardes : un identifiant
 * normalisé (`123-456-7890` → `1234567890`), un objet désigné autrement résolu
 * en son identifiant. Toute classe qui l'implémente reçoit le tag
 * `qcdgone_mcp.preparation` ; `#[AsTaggedItem(priority: …)]` règle l'ordre.
 *
 * ⚠ **Jouée après la validation du schéma, avant les gardes et le domaine**, et
 *   ce qu'elle rend est ce que les gardes ET le domaine lisent. Une garde qui
 *   normaliserait pour elle seule contrôlerait autre chose que ce que le domaine
 *   exécute.
 *
 * ⚠ **Un identifiant illisible est une erreur d'argument** (`ArgumentsInvalides`,
 *   -32602) : levée ici, elle n'entame aucun plafond, puisqu'une ressource
 *   qu'on ne sait pas lire ne peut être ni autorisée ni refusée.
 *
 * ⚠ Le journal reçoit les arguments tels que l'agent les a ENVOYÉS, pas ceux-ci.
 */
interface PreparationDesArguments
{
    /**
     * @param array<string, mixed> $definition la définition MCP de l'outil
     * @param array<string, mixed> $arguments  les arguments validés par le schéma
     *
     * @return array<string, mixed> les arguments que liront les gardes et le domaine
     *
     * @throws ArgumentsInvalides
     * @throws ObjetIntrouvable
     */
    public function preparer(string $outil, array $definition, array $arguments): array;
}
