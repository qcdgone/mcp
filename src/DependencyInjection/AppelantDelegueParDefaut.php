<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\DependencyInjection;

use Qcdgone\Api\Security\DelegatedCallerResolverInterface;
use Qcdgone\Mcp\Securite\AppelantDelegue;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Branche {@see AppelantDelegue} quand `qcdgone/api` est là et que
 * l'application n'a pas déclaré son propre appelant délégué.
 *
 * ⚠ **Dans une passe, pas dans l'extension** : l'extension du bundle se charge
 *   avant `services.yaml`, elle ne peut pas savoir si l'application y aliase
 *   l'interface. Ici, toute la configuration est connue.
 */
final class AppelantDelegueParDefaut implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(DelegatedCallerResolverInterface::class) || $container->has(DelegatedCallerResolverInterface::class)) {
            return;
        }

        $container->register(AppelantDelegue::class, AppelantDelegue::class);
        $container->setAlias(DelegatedCallerResolverInterface::class, AppelantDelegue::class);
    }
}
