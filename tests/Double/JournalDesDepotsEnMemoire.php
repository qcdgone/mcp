<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Double;

use Qcdgone\Mcp\Depot\DepotOuvert;
use Qcdgone\Mcp\Journal\IssueDAppel;
use Qcdgone\Mcp\Journal\JournalDesDepots;

final class JournalDesDepotsEnMemoire implements JournalDesDepots
{
    /** @var list<array{nature: string, utilisateur: int|null, octets: int, issue: IssueDAppel, code: string|null}> */
    public array $lignes = [];

    public function consignerDepot(DepotOuvert $depot, int $octets, int $dureeMs, IssueDAppel $issue, ?string $code = null): void
    {
        $this->lignes[] = ['nature' => $depot->nature, 'utilisateur' => $depot->utilisateurId, 'octets' => $octets, 'issue' => $issue, 'code' => $code];
    }
}
