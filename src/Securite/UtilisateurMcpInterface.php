<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Securite;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Ce que le serveur doit savoir de la personne qui appelle : qui elle est, et
 * si le hub lui a retiré l'accès. L'entité `User` de l'application l'implémente
 * — les quatre du parc portent déjà ces deux méthodes.
 *
 * ⚠ **Un appelant qui ne l'implémente pas est REFUSÉ** (403), jamais laissé
 *   passer : un oubli d'`implements` doit fermer le serveur au premier essai,
 *   pas ouvrir silencieusement l'accès aux comptes révoqués.
 */
interface UtilisateurMcpInterface extends UserInterface
{
    public function getId(): ?int;

    /**
     * Vrai quand le hub a retiré l'accès : le compte ne lit plus rien, même avec
     * un jeton encore valide quelques minutes.
     */
    public function isRevoked(): bool;
}
