<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

/**
 * Fabrique des définitions d'outils MCP et de leurs schémas JSON, pour que
 * chaque domaine décrive ses outils en quelques lignes.
 *
 * ⚠ `additionalProperties: false` partout : un champ mal orthographié par
 *   l'agent doit être refusé, pas ignoré en silence. Le catalogue l'applique
 *   réellement ({@see CatalogueDesOutils::appeler()}), le schéma ne fait que
 *   l'annoncer.
 *
 * ⚠ `openWorldHint` dit si l'outil sort de l'application (une API tierce,
 *   Google Ads…). `false` par défaut : la plupart des outils du parc ne lisent
 *   que leur propre base.
 */
final class Outil
{
    /**
     * @param array<string, array<string, mixed>> $proprietes
     * @param list<string>                        $obligatoires
     *
     * @return array<string, mixed>
     */
    public static function lecture(string $nom, string $titre, string $description, array $proprietes = [], array $obligatoires = [], bool $exterieur = false): array
    {
        return self::definition($nom, $titre, $description, $proprietes, $obligatoires, ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => $exterieur]);
    }

    /**
     * @param array<string, array<string, mixed>> $proprietes
     * @param list<string>                        $obligatoires
     *
     * @return array<string, mixed>
     */
    public static function ecriture(string $nom, string $titre, string $description, array $proprietes, array $obligatoires = [], bool $destructive = false, bool $idempotent = true, bool $exterieur = false): array
    {
        return self::definition($nom, $titre, $description, $proprietes, $obligatoires, ['readOnlyHint' => false, 'destructiveHint' => $destructive, 'idempotentHint' => $idempotent, 'openWorldHint' => $exterieur]);
    }

    /** @return array<string, mixed> */
    public static function entier(string $description, ?int $min = null, ?int $max = null): array
    {
        return array_filter(['type' => 'integer', 'description' => $description, 'minimum' => $min, 'maximum' => $max], static fn ($v): bool => null !== $v);
    }

    /** @return array<string, mixed> */
    public static function nombre(string $description): array
    {
        return ['type' => 'number', 'description' => $description];
    }

    /** @return array<string, mixed> */
    public static function texte(string $description, bool $nullable = false): array
    {
        return ['type' => $nullable ? ['string', 'null'] : 'string', 'description' => $description];
    }

    /** @return array<string, mixed> */
    public static function booleen(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    /**
     * @param list<string> $valeurs
     *
     * @return array<string, mixed>
     */
    public static function choix(string $description, array $valeurs): array
    {
        return ['type' => 'string', 'enum' => $valeurs, 'description' => $description];
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return array<string, mixed>
     */
    public static function liste(string $description, array $element): array
    {
        return ['type' => 'array', 'items' => $element, 'description' => $description];
    }

    /**
     * @param array<string, array<string, mixed>> $proprietes
     * @param list<string>                        $obligatoires
     *
     * @return array<string, mixed>
     */
    public static function objet(array $proprietes, array $obligatoires = [], string $description = ''): array
    {
        $schema = ['type' => 'object', 'properties' => [] === $proprietes ? new \stdClass() : $proprietes, 'additionalProperties' => false];
        if ([] !== $obligatoires) {
            $schema['required'] = $obligatoires;
        }
        if ('' !== $description) {
            $schema['description'] = $description;
        }

        return $schema;
    }

    /**
     * @param array<string, array<string, mixed>> $proprietes
     * @param list<string>                        $obligatoires
     * @param array<string, bool>                 $annotations
     *
     * @return array<string, mixed>
     */
    private static function definition(string $nom, string $titre, string $description, array $proprietes, array $obligatoires, array $annotations): array
    {
        return [
            'name' => $nom,
            'title' => $titre,
            'description' => $description,
            'inputSchema' => self::objet($proprietes, $obligatoires),
            'annotations' => ['title' => $titre] + $annotations,
        ];
    }
}
