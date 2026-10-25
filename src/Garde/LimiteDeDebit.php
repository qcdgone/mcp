<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Garde;

use Qcdgone\Mcp\AppelRefuse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Consomme un jeton du limiteur, ou refuse en disant QUAND réessayer. */
final class LimiteDeDebit
{
    public static function consommer(RateLimiterFactoryInterface $limiteurs, string $cle, string $code, string $message): void
    {
        $limite = $limiteurs->create($cle)->consume();

        if ($limite->isAccepted()) {
            return;
        }

        $secondes = max(1, $limite->getRetryAfter()->getTimestamp() - time());

        throw new AppelRefuse(\sprintf('%s Réessayer dans %d s.', $message, $secondes), $code, ['reessayer_dans_s' => $secondes]);
    }
}
