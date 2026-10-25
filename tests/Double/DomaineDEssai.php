<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Double;

use Qcdgone\Mcp\Arguments;
use Qcdgone\Mcp\DomaineMcp;
use Qcdgone\Mcp\EcritureRefusee;
use Qcdgone\Mcp\ObjetIntrouvable;
use Qcdgone\Mcp\Outil;

/** Un domaine dont chaque outil produit une issue connue. */
final class DomaineDEssai implements DomaineMcp
{
    /** @var list<string> les outils réellement exécutés */
    public array $appels = [];

    /** @param \Closure(string, Arguments): array<string, mixed>|null $comportement */
    public function __construct(private ?\Closure $comportement = null)
    {
    }

    public function definitions(): array
    {
        return [
            Outil::lecture('lire', 'Lire', 'Lit une fiche.', ['id' => Outil::entier('Identifiant'), 'filtre' => Outil::texte('Filtre')], ['id']),
            Outil::ecriture('ecrire', 'Écrire', 'Écrit une fiche.', ['titre' => Outil::texte('Titre')]),
            Outil::lecture('vide', 'Vide', 'Sans argument.'),
        ];
    }

    public function appeler(string $nom, Arguments $arguments): array
    {
        $this->appels[] = $nom;

        if (null !== $this->comportement) {
            return ($this->comportement)($nom, $arguments);
        }

        return match ($nom) {
            'lire' => 404 === $arguments->entier('id') ? throw new ObjetIntrouvable('Fiche introuvable.') : ['id' => $arguments->entier('id')],
            'ecrire' => null === $arguments->texte('titre') ? throw new EcritureRefusee('Rien n’a été écrit.', ['errors' => ['titre' => 'obligatoire']]) : ['ecrit' => true],
            default => [],
        };
    }
}
