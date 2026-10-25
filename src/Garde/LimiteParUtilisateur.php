<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Garde;

use Qcdgone\Mcp\AppelRefuse;
use Qcdgone\Mcp\Arguments;
use Qcdgone\Mcp\Securite\UtilisateurMcpInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Plafond d'appels d'outils par personne, sur TOUS les outils. Déclarée par le
 * bundle quand `qcdgone_mcp.limite_par_utilisateur` nomme un limiteur.
 *
 * ⚠ **Distinct du quota de `qcdgone/api`**, qui compte des requêtes HTTP sur
 *   tout le préfixe d'API : celui-ci compte des appels d'outils, et rend un
 *   RÉSULTAT que l'agent lit (`reessayer_dans_s`) au lieu d'un 429 qu'il ne
 *   saurait pas présenter.
 */
final readonly class LimiteParUtilisateur implements GardeDAppel
{
    public function __construct(
        private RateLimiterFactoryInterface $limiteurs,
        private Security $securite,
    ) {
    }

    public function verifier(string $outil, array $definition, Arguments $arguments): void
    {
        $utilisateur = $this->securite->getUser();
        if (!$utilisateur instanceof UtilisateurMcpInterface) {
            // Le contrôleur a déjà refusé tout autre appelant : ne rien laisser passer quand même.
            throw new AppelRefuse('Appelant inconnu.', 'appelant_inconnu');
        }

        LimiteDeDebit::consommer(
            $this->limiteurs,
            'utilisateur:'.$utilisateur->getId(),
            'plafond_utilisateur',
            'Trop d’appels d’outils en une minute pour votre compte.',
        );
    }
}
