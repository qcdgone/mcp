<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

/**
 * Un domaine de l'application piloté par MCP (affaires, leads, backlinks…) :
 * ses outils et leur exécution. Toute classe de l'application qui l'implémente
 * est recensée par {@see CatalogueDesOutils} — l'autoconfiguration pose le tag
 * `qcdgone_mcp.domaine`.
 *
 * ⚠ **Le domaine n'a pas à valider les champs inconnus ou manquants** : le
 *   catalogue l'a fait d'après le schéma, avant de l'appeler. Il lit ses
 *   arguments par {@see Arguments}, qui type et refuse.
 */
interface DomaineMcp
{
    /**
     * Les définitions MCP des outils : `name`, `title`, `description`,
     * `inputSchema`, `annotations` (voir {@see Outil}).
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array;

    /** @return array<string, mixed> le résultat structuré */
    public function appeler(string $nom, Arguments $arguments): array;
}
