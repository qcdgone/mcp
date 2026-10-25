<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

use Qcdgone\Mcp\Garde\GardeDAppel;

/**
 * Tous les outils du serveur MCP, rangés par domaine, et les gardes jouées
 * avant chacun d'eux.
 *
 * ⚠ **Les gardes sont ICI, et nulle part ailleurs** : un domaine n'a pas à
 *   vérifier un plafond de débit, et ne peut donc pas l'oublier. Les droits
 *   métier (qui voit quelle entreprise, quel compte) restent l'affaire de
 *   l'application — par une garde qu'elle déclare, ou dans son domaine.
 *
 * ⚠ **Deux outils du même nom sont refusés**, au premier usage : le second
 *   masquerait le premier sans que rien ne le dise, et l'agent appellerait l'un
 *   en croyant appeler l'autre.
 */
final class CatalogueDesOutils
{
    /** @var array<string, DomaineMcp>|null nom de l'outil → domaine */
    private ?array $index = null;

    /** @var array<string, array<string, mixed>> nom de l'outil → définition MCP */
    private array $definitions = [];

    /**
     * @param iterable<DomaineMcp>  $domaines
     * @param iterable<GardeDAppel> $gardes   par priorité décroissante
     */
    public function __construct(
        private readonly iterable $domaines,
        private readonly iterable $gardes = [],
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        return array_values($this->tables()[1]);
    }

    /** @return array<string, mixed>|null la définition de l'outil, `null` s'il n'existe pas */
    public function definition(string $nom): ?array
    {
        return $this->tables()[1][$nom] ?? null;
    }

    public function existe(string $nom): bool
    {
        return isset($this->tables()[0][$nom]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function appeler(string $nom, array $arguments): array
    {
        $domaine = $this->tables()[0][$nom] ?? throw new ArgumentsInvalides(\sprintf('Outil inconnu : %s', $nom));
        $definition = $this->definitions[$nom];

        // ⚠ Le schéma annonce `additionalProperties: false` : un champ mal
        //   orthographié serait sinon ignoré en silence, et l'agent croirait
        //   avoir modifié ce qu'il n'a pas touché.
        $schema = (array) ($definition['inputSchema'] ?? []);
        $proprietes = (array) ($schema['properties'] ?? []);
        $inconnus = array_diff(array_keys($arguments), array_keys($proprietes));
        if ([] !== $inconnus) {
            throw new ArgumentsInvalides(\sprintf('Champ(s) inconnu(s) pour %s : %s. Champs acceptés : %s.', $nom, implode(', ', $inconnus), implode(', ', array_keys($proprietes))));
        }
        $manquants = array_diff((array) ($schema['required'] ?? []), array_keys($arguments));
        if ([] !== $manquants) {
            throw new ArgumentsInvalides(\sprintf('Champ(s) obligatoire(s) pour %s : %s.', $nom, implode(', ', $manquants)));
        }

        $lus = new Arguments($arguments);

        // ⚠ APRÈS la validation des arguments : un appel mal formé est une
        //   erreur de l'agent, qui ne doit pas entamer son plafond.
        foreach ($this->gardes as $garde) {
            $garde->verifier($nom, $definition, $lus);
        }

        return $domaine->appeler($nom, $lus);
    }

    /** @return array{0: array<string, DomaineMcp>, 1: array<string, array<string, mixed>>} */
    private function tables(): array
    {
        if (null === $this->index) {
            $index = [];
            foreach ($this->domaines as $domaine) {
                foreach ($domaine->definitions() as $definition) {
                    $nom = $definition['name'];
                    if (isset($index[$nom])) {
                        throw new \LogicException(\sprintf('L’outil MCP « %s » est déclaré deux fois : par %s et par %s.', $nom, $index[$nom]::class, $domaine::class));
                    }
                    $index[$nom] = $domaine;
                    $this->definitions[$nom] = $definition;
                }
            }
            $this->index = $index;
        }

        return [$this->index, $this->definitions];
    }
}
