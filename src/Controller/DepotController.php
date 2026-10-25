<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Controller;

use Qcdgone\Api\Doc\ApiDoc;
use Qcdgone\Mcp\Depot\DepotOuvert;
use Qcdgone\Mcp\Depot\DepotRefuse;
use Qcdgone\Mcp\Depot\Depots;
use Qcdgone\Mcp\Journal\IssueDAppel;
use Qcdgone\Mcp\Journal\JournalDesDepots;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La réception d'un fichier par une adresse de dépôt ({@see Depots}).
 *
 * ⚠ **Sans jeton du hub, et c'est voulu** : l'adresse elle-même est le droit,
 *   à usage unique et pour quelques minutes. L'application ouvre la route dans
 *   son pare-feu (README) ; le compte au nom duquel on dépose est celui qui a
 *   demandé l'adresse.
 *
 * ⚠ **Le poids est vérifié AVANT la lecture** quand le client l'annonce, et la
 *   lecture s'arrête au plafond sinon : un envoi de plusieurs Go ne tient
 *   jamais en mémoire.
 */
final readonly class DepotController
{
    public function __construct(
        private Depots $depots,
        private ?JournalDesDepots $journal = null,
    ) {
    }

    #[ApiDoc(
        resume: 'Upload a file to a one-time address',
        description: 'Send the raw file as the request body (no encoding, no Authorization header) to the address returned by the application\'s MCP tool. '
            .'The address works once and expires after a few minutes. The answer is what the application made of the file (its path…).',
        ressource: 'mcp',
        erreurs: [
            '400' => 'Empty body.',
            '410' => 'Unknown, expired or already used address.',
            '413' => 'File larger than the limit given with the address.',
            '422' => 'Content refused by the application (not an image…).',
        ],
    )]
    public function recevoir(string $jeton, Request $requete): Response
    {
        $depot = $this->depots->consommer($jeton);
        if (null === $depot) {
            return new JsonResponse(['error' => 'Adresse de dépôt inconnue, expirée ou déjà utilisée : demandez-en une nouvelle.'], Response::HTTP_GONE);
        }
        $debut = hrtime(true);
        $receveur = $this->depots->receveur($depot->nature) ?? throw new \LogicException(\sprintf('Plus de réceptionnaire pour la nature « %s ».', $depot->nature));

        $annonce = $requete->headers->get('Content-Length');
        if (null !== $annonce && (int) $annonce > $depot->octetsMax) {
            return $this->refus($depot, (int) $annonce, $debut, Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'trop_lourd', \sprintf('Fichier trop lourd : %d octets au plus.', $depot->octetsMax));
        }
        $octets = $this->lire($requete, $depot->octetsMax + 1);
        if (\strlen($octets) > $depot->octetsMax) {
            return $this->refus($depot, \strlen($octets), $debut, Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'trop_lourd', \sprintf('Fichier trop lourd : %d octets au plus.', $depot->octetsMax));
        }
        if ('' === $octets) {
            return $this->refus($depot, 0, $debut, Response::HTTP_BAD_REQUEST, 'vide', 'Corps vide : envoyez le fichier lui-même (curl --data-binary @fichier).');
        }

        try {
            $reponse = $receveur->recevoir($octets, $depot);
        } catch (DepotRefuse $e) {
            return $this->refus($depot, \strlen($octets), $debut, Response::HTTP_UNPROCESSABLE_ENTITY, 'contenu_refuse', $e->getMessage(), $e->details);
        } catch (\Throwable $e) {
            $this->journal?->consignerDepot($depot, \strlen($octets), self::ms($debut), IssueDAppel::Erreur, (new \ReflectionClass($e))->getShortName());

            throw $e;
        }

        $this->journal?->consignerDepot($depot, \strlen($octets), self::ms($debut), IssueDAppel::Ok);

        return new JsonResponse($reponse);
    }

    /** Le corps, lu au plus jusqu'à `$plafond` octets. */
    private function lire(Request $requete, int $plafond): string
    {
        $flux = $requete->getContent(true);

        return (string) stream_get_contents($flux, $plafond);
    }

    /** @param array<string, mixed> $details */
    private function refus(DepotOuvert $depot, int $octets, int $debut, int $statut, string $code, string $message, array $details = []): Response
    {
        $this->journal?->consignerDepot($depot, $octets, self::ms($debut), IssueDAppel::Refuse, $code);

        return new JsonResponse(['error' => $message, 'code' => $code] + $details, $statut);
    }

    private static function ms(int $debut): int
    {
        return (int) ((hrtime(true) - $debut) / 1_000_000);
    }
}
