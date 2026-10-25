<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Depot;

/**
 * Le contenu reçu est refusé par le réceptionnaire (ce n'est pas une image, type
 * non autorisé…) : réponse 422 avec la raison, rien n'est écrit.
 */
final class DepotRefuse extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
