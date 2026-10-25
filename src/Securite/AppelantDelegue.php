<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Securite;

use Qcdgone\Api\Security\DelegatedCallerResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Pour qui agit un appelant du serveur MCP : une personne, pas une clé.
 *
 * ⚠ **Sans cette classe, les appels MCP échappent au quota ET au journal** de
 * `qcdgone/api` — le cas de QCDeal jusqu'à son passage sur ce bundle, et rien
 * ne le disait. Le bundle la branche donc d'office dès que `qcdgone/api` est
 * installé, sauf si l'application a déclaré la sienne.
 *
 * ⚠ **La référence n'est JAMAIS `null`** : un `null` sortirait l'appel du quota.
 * ⚠ **Le format `utilisateur:{id}` est celui de serelios et de carport** : le
 *   changer remettrait à zéro les décomptes du jour et couperait l'historique
 *   du journal en deux.
 */
final class AppelantDelegue implements DelegatedCallerResolverInterface
{
    public function ownerReferenceOf(UserInterface $caller): ?string
    {
        return $caller instanceof UtilisateurMcpInterface && null !== $caller->getId()
            ? 'utilisateur:'.$caller->getId()
            : 'utilisateur:inconnu';
    }
}
