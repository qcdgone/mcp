<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

use Qcdgone\Mcp\Appel\EnveloppeDAppel;
use Qcdgone\Mcp\Appel\PreparationDesArguments;
use Qcdgone\Mcp\Garde\GardeDAppel;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

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
 *   en croyant appeler l'autre. Y compris entre deux domaines réservés à des
 *   rôles différents : le doublon dépendrait sinon de qui appelle.
 *
 * ⚠ **Un domaine réservé ({@see DomaineReserve}) n'existe que pour son rôle**,
 *   vérifié à CHAQUE lecture, jamais gardé d'une requête à l'autre : le service
 *   survit aux requêtes dans un worker ou un test, l'appelant non.
 *
 * L'ordre d'un appel : schéma, préparations ({@see PreparationDesArguments}),
 * gardes, puis le domaine entouré des enveloppes ({@see EnveloppeDAppel}).
 */
final class CatalogueDesOutils
{
    /** @var array<string, DomaineMcp>|null nom de l'outil → domaine */
    private ?array $index = null;

    /** @var array<string, array<string, mixed>> nom de l'outil → définition MCP */
    private array $definitions = [];

    /**
     * @param iterable<DomaineMcp>              $domaines
     * @param iterable<GardeDAppel>             $gardes       par priorité décroissante
     * @param iterable<PreparationDesArguments> $preparations par priorité décroissante
     * @param iterable<EnveloppeDAppel>         $enveloppes   la première la plus extérieure
     */
    public function __construct(
        private readonly iterable $domaines,
        private readonly iterable $gardes = [],
        private readonly ?AuthorizationCheckerInterface $droits = null,
        private readonly iterable $preparations = [],
        private readonly iterable $enveloppes = [],
    ) {
    }

    /** @return list<array<string, mixed>> les outils que l'appelant voit */
    public function definitions(): array
    {
        [$index, $definitions] = $this->tables();

        return array_values(array_filter($definitions, fn (string $nom): bool => $this->visible($index[$nom]), \ARRAY_FILTER_USE_KEY));
    }

    /** @return array<string, mixed>|null la définition de l'outil, `null` s'il n'existe pas pour l'appelant */
    public function definition(string $nom): ?array
    {
        return $this->existe($nom) ? $this->tables()[1][$nom] : null;
    }

    public function existe(string $nom): bool
    {
        $domaine = $this->tables()[0][$nom] ?? null;

        return null !== $domaine && $this->visible($domaine);
    }

    /** @return list<string> les consignes des domaines réservés que l'appelant voit */
    public function consignes(): array
    {
        $consignes = [];
        foreach ($this->domaines as $domaine) {
            if ($domaine instanceof DomaineReserve && $this->visible($domaine) && '' !== trim($domaine->consignes())) {
                $consignes[] = trim($domaine->consignes());
            }
        }

        return $consignes;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function appeler(string $nom, array $arguments): array
    {
        $domaine = $this->tables()[0][$nom] ?? null;
        // ⚠ Un outil réservé à un autre rôle est inconnu, mot pour mot.
        if (null === $domaine || !$this->visible($domaine)) {
            throw new ArgumentsInvalides(\sprintf('Outil inconnu : %s', $nom));
        }
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

        // ⚠ AVANT les gardes : elles contrôlent ce que le domaine exécutera,
        //   et un identifiant illisible n'entame aucun plafond.
        foreach ($this->preparations as $preparation) {
            $arguments = $preparation->preparer($nom, $definition, $arguments);
        }

        $lus = new Arguments($arguments);

        // ⚠ APRÈS la validation des arguments : un appel mal formé est une
        //   erreur de l'agent, qui ne doit pas entamer son plafond.
        foreach ($this->gardes as $garde) {
            $garde->verifier($nom, $definition, $lus);
        }

        $appel = static fn (): array => $domaine->appeler($nom, $lus);
        // La première enveloppe est la plus extérieure : on les pose de la
        // dernière à la première.
        foreach (array_reverse(iterator_to_array($this->enveloppes, false)) as $enveloppe) {
            $appel = static fn (): array => $enveloppe->envelopper($nom, $definition, $appel);
        }

        return $appel();
    }

    private function visible(DomaineMcp $domaine): bool
    {
        if (!$domaine instanceof DomaineReserve) {
            return true;
        }

        // ⚠ Le défaut ferme : sans vérificateur, personne ne voit un domaine réservé.
        return null !== $this->droits && $this->droits->isGranted($domaine->role());
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
