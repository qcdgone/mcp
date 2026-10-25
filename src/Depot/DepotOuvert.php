<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Depot;

/**
 * Une adresse de dépôt émise et pas encore utilisée : pour quelle nature, au nom
 * de qui, jusqu'à quand.
 *
 * ⚠ Le PUT arrive SANS jeton du hub : c'est ce qui est gardé ici qui dit pour
 *   qui le fichier est déposé. Le jeton de l'adresse, lui, n'est jamais gardé :
 *   seule son empreinte sert de clé.
 */
final readonly class DepotOuvert
{
    public function __construct(
        public string $nature,
        public ?int $utilisateurId,
        public string $utilisateur,
        public int $octetsMax,
        public \DateTimeImmutable $expireLe,
    ) {
    }
}
