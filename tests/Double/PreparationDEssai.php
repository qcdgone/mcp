<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Double;

use Qcdgone\Mcp\Appel\PreparationDesArguments;

/** Une préparation qui garde la trace des outils qu'elle a vus passer. */
final class PreparationDEssai implements PreparationDesArguments
{
    /** @var list<string> */
    public array $outils = [];

    public function preparer(string $outil, array $definition, array $arguments): array
    {
        $this->outils[] = $outil;

        return $arguments;
    }
}
