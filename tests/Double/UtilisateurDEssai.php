<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Double;

use Qcdgone\Mcp\Securite\UtilisateurMcpInterface;

final class UtilisateurDEssai implements UtilisateurMcpInterface
{
    public function __construct(private ?int $id = 7, private bool $revoque = false)
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isRevoked(): bool
    {
        return $this->revoque;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'personne@exemple.test';
    }
}
