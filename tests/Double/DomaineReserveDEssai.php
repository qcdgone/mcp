<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Double;

use Qcdgone\Mcp\Arguments;
use Qcdgone\Mcp\DomaineReserve;
use Qcdgone\Mcp\Outil;

/** Un domaine réservé au rôle `ROLE_COMPTABLE`. */
final class DomaineReserveDEssai implements DomaineReserve
{
    /** @var list<string> les outils réellement exécutés */
    public array $appels = [];

    public function role(): string
    {
        return 'ROLE_COMPTABLE';
    }

    public function consignes(): string
    {
        return 'Facturation : commencez par factures_lister.';
    }

    public function definitions(): array
    {
        return [Outil::lecture('factures_lister', 'Factures', 'Toutes les factures.')];
    }

    public function appeler(string $nom, Arguments $arguments): array
    {
        $this->appels[] = $nom;

        return ['factures' => []];
    }
}
