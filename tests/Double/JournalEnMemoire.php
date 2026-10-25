<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Double;

use Qcdgone\Mcp\Journal\IssueDAppel;
use Qcdgone\Mcp\Journal\JournalDesAppels;

final class JournalEnMemoire implements JournalDesAppels
{
    /** @var list<array{outil: string, issue: IssueDAppel, code: string|null}> */
    public array $lignes = [];

    public function consigner(string $outil, array $arguments, int $dureeMs, IssueDAppel $issue, ?string $code = null): void
    {
        $this->lignes[] = ['outil' => $outil, 'issue' => $issue, 'code' => $code];
    }
}
