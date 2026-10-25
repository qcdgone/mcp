<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Journal;

use Qcdgone\Mcp\Depot\DepotOuvert;

/**
 * Où va la trace d'un dépôt reçu par adresse — facultatif, comme
 * {@see JournalDesAppels}, qui ne le voit pas : le PUT n'est pas un appel
 * d'outil. L'émission de l'adresse, elle, EST un appel d'outil, et passe par
 * `JournalDesAppels`.
 *
 * ⚠ Le contenu du fichier n'arrive jamais ici : seulement son poids.
 * ⚠ Une panne du journal ne doit pas casser le dépôt.
 */
interface JournalDesDepots
{
    public function consignerDepot(DepotOuvert $depot, int $octets, int $dureeMs, IssueDAppel $issue, ?string $code = null): void;
}
