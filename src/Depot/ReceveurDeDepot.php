<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Depot;

/**
 * Ce que l'application fait d'un fichier reçu par une adresse de dépôt, pour une
 * nature donnée (« image », « pdf »…). L'autoconfiguration le recense : il n'y a
 * rien d'autre à déclarer.
 *
 * ⚠ **Le contrôle du contenu est ICI, pas dans le bundle** : le bundle ne sait
 *   rien d'une image ou d'un PDF. Pour une image, l'application passe les octets
 *   à `qcdgone/builder` (`ImageUploadHandler::depuisDesOctets()`).
 *
 * ⚠ Un contenu refusé lève {@see DepotRefuse} : rien ne doit avoir été écrit.
 */
interface ReceveurDeDepot
{
    /** La nature servie, unique dans l'application : c'est elle que l'outil MCP passe à {@see Depots::ouvrir()}. */
    public function nature(): string;

    /** Le poids maximal accepté, en octets : au-delà, le dépôt est refusé (413) sans être lu. */
    public function octetsMax(): int;

    /**
     * @return array<string, mixed> ce que l'agent reçoit en réponse au PUT (le chemin du fichier…)
     *
     * @throws DepotRefuse
     */
    public function recevoir(string $octets, DepotOuvert $depot): array;
}
