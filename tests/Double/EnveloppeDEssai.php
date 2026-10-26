<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Double;

use Qcdgone\Mcp\Appel\EnveloppeDAppel;

/** Une enveloppe qui garde la trace des outils qu'elle a entourés. */
final class EnveloppeDEssai implements EnveloppeDAppel
{
    /** @var list<string> */
    public array $outils = [];

    public function envelopper(string $outil, array $definition, \Closure $appel): array
    {
        $this->outils[] = $outil;

        return $appel();
    }
}
