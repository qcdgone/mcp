<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Depot;

use Psr\Cache\CacheItemPoolInterface;
use Qcdgone\Mcp\Securite\UtilisateurMcpInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les adresses de dépôt : un agent ne peut pas joindre un fichier à un appel
 * d'outil, et recopier un fichier en base64 dans ses arguments coûte des
 * centaines de milliers de caractères (SWAN-330). Un outil de l'application
 * ouvre une adresse ; l'agent y envoie le binaire en PUT, sans jeton.
 *
 * ⚠ **Usage unique, consommé au premier PUT, réussi ou non** : un second envoi,
 *   même après un refus, demande une nouvelle adresse. Une adresse qui traîne
 *   dans un journal ou un historique ne sert donc qu'une fois.
 *
 * ⚠ **Le jeton n'est jamais gardé** : la clé du cache est son empreinte. Une
 *   lecture du cache ne donne pas d'adresse utilisable.
 *
 * ⚠ **Sans `symfony/lock`, deux PUT simultanés sur la même adresse peuvent
 *   passer tous deux** (lecture puis suppression du cache). Avec un
 *   `lock.factory`, le second est refusé.
 */
final class Depots
{
    public const string ROUTE = 'qcdgone_mcp_depot';

    /** @var array<string, ReceveurDeDepot>|null nature → réceptionnaire */
    private ?array $parNature = null;

    /** @param iterable<ReceveurDeDepot> $receveurs */
    public function __construct(
        private readonly iterable $receveurs,
        private readonly CacheItemPoolInterface $cache,
        private readonly UrlGeneratorInterface $adresses,
        private readonly int $dureeS = 900,
        private readonly ?LockFactory $verrous = null,
    ) {
    }

    /**
     * Ouvre une adresse pour un fichier de cette nature, au nom de l'appelant.
     *
     * @return array{adresse: string, methode: string, expire_le: string, octets_max: int, exemple: string}
     */
    public function ouvrir(string $nature, UtilisateurMcpInterface $pour): array
    {
        $receveur = $this->receveur($nature) ?? throw new \LogicException(\sprintf('Aucun réceptionnaire de dépôt pour la nature « %s ».', $nature));

        $jeton = bin2hex(random_bytes(32));
        $expire = new \DateTimeImmutable(\sprintf('+%d seconds', $this->dureeS));
        $element = $this->cache->getItem(self::cle($jeton));
        $element->set(new DepotOuvert($nature, $pour->getId(), $pour->getUserIdentifier(), $receveur->octetsMax(), $expire));
        $element->expiresAt($expire);
        $this->cache->save($element);

        $adresse = $this->adresses->generate(self::ROUTE, ['jeton' => $jeton], UrlGeneratorInterface::ABSOLUTE_URL);

        return [
            'adresse' => $adresse,
            'methode' => 'PUT',
            'expire_le' => $expire->format(\DATE_ATOM),
            'octets_max' => $receveur->octetsMax(),
            'exemple' => \sprintf('curl -sS -X PUT --data-binary @fichier "%s"', $adresse),
        ];
    }

    /** L'adresse, retirée au passage : `null` si elle est inconnue, expirée, déjà utilisée ou en cours d'utilisation. */
    public function consommer(string $jeton): ?DepotOuvert
    {
        $cle = self::cle($jeton);
        $verrou = $this->verrous?->createLock($cle);
        if (null !== $verrou && !$verrou->acquire()) {
            return null;
        }

        try {
            $element = $this->cache->getItem($cle);
            if (!$element->isHit()) {
                return null;
            }
            $this->cache->deleteItem($cle);
            $depot = $element->get();

            return $depot instanceof DepotOuvert && $depot->expireLe > new \DateTimeImmutable() ? $depot : null;
        } finally {
            $verrou?->release();
        }
    }

    public function receveur(string $nature): ?ReceveurDeDepot
    {
        if (null === $this->parNature) {
            $index = [];
            foreach ($this->receveurs as $receveur) {
                if (isset($index[$receveur->nature()])) {
                    throw new \LogicException(\sprintf('La nature de dépôt « %s » est servie deux fois : par %s et par %s.', $receveur->nature(), $index[$receveur->nature()]::class, $receveur::class));
                }
                $index[$receveur->nature()] = $receveur;
            }
            $this->parNature = $index;
        }

        return $this->parNature[$nature] ?? null;
    }

    private static function cle(string $jeton): string
    {
        return 'qcdgone_mcp_depot_'.hash('sha256', $jeton);
    }
}
