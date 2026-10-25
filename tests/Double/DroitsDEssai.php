<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Double;

use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Accorde les rôles donnés, refuse tout le reste. */
final class DroitsDEssai implements AuthorizationCheckerInterface
{
    /** @param list<string> $roles */
    public function __construct(public array $roles = [])
    {
    }

    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
    {
        return \in_array($attribute, $this->roles, true);
    }
}
