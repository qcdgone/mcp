<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

/**
 * Une exception de l'application que le serveur rend comme un RÉSULTAT en
 * échec (`isError`), avec sa propre charge — l'erreur d'une API tierce, par
 * exemple, que l'agent doit lire plutôt que de recevoir une erreur 500.
 *
 * ⚠ **Sans cette interface, l'exception remonte** : le serveur ne transforme
 *   en résultat que ce qu'on lui a dit savoir présenter. Le reste est un défaut,
 *   et doit se voir comme tel.
 */
interface EchecPourLAgent extends \Throwable
{
    /** @return array<string, mixed> la charge rendue à l'agent, `error` compris */
    public function pourLAgent(): array;

    /** Le code stable que retient le journal (`quotaError.RESOURCE_EXHAUSTED`…). */
    public function codeDEchec(): string;
}
