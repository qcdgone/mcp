<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Double;

use Qcdgone\Mcp\Depot\DepotOuvert;
use Qcdgone\Mcp\Depot\DepotRefuse;
use Qcdgone\Mcp\Depot\ReceveurDeDepot;

/** Reçoit les « images » de 100 octets au plus : un contenu qui commence par PNG. */
final class ReceveurDEssai implements ReceveurDeDepot
{
    /** @var list<string> les contenus réellement reçus */
    public array $recus = [];

    public function __construct(private string $nature = 'image')
    {
    }

    public function nature(): string
    {
        return $this->nature;
    }

    public function octetsMax(): int
    {
        return 100;
    }

    public function recevoir(string $octets, DepotOuvert $depot): array
    {
        if (!str_starts_with($octets, 'PNG')) {
            throw new DepotRefuse('Ce n’est pas une image.');
        }
        $this->recus[] = $octets;

        return ['chemin' => '/uploads/essai.png', 'pour' => $depot->utilisateurId];
    }
}
