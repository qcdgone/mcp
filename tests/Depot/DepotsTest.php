<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Depot;

use PHPUnit\Framework\TestCase;
use Qcdgone\Mcp\Depot\Depots;
use Qcdgone\Mcp\Tests\Double\ReceveurDEssai;
use Qcdgone\Mcp\Tests\Double\UtilisateurDEssai;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class DepotsTest extends TestCase
{
    private ArrayAdapter $cache;

    public function testUneAdresseEstAbsolueAnnonceSonPlafondEtNePorteQueLeJeton(): void
    {
        $ouverte = $this->depots()->ouvrir('image', new UtilisateurDEssai(42));

        self::assertMatchesRegularExpression('#^https://essai\.exemple\.test/api/mcp/depot/[0-9a-f]{64}$#', $ouverte['adresse']);
        self::assertSame('PUT', $ouverte['methode']);
        self::assertSame(100, $ouverte['octets_max']);
        self::assertStringContainsString('--data-binary @fichier', $ouverte['exemple']);
    }

    /** ⚠ Une adresse qui traîne dans un journal ou un historique ne sert qu'une fois. */
    public function testUneAdresseNeSertQuUneFois(): void
    {
        $depots = $this->depots();
        $jeton = self::jeton($depots->ouvrir('image', new UtilisateurDEssai(42)));

        $depot = $depots->consommer($jeton);
        self::assertNotNull($depot);
        self::assertSame('image', $depot->nature);
        self::assertSame(42, $depot->utilisateurId);
        self::assertNull($depots->consommer($jeton));
    }

    /** ⚠ La clé du cache est l'empreinte : lire le cache ne donne pas d'adresse utilisable. */
    public function testLeJetonNEstPasGardeEnClair(): void
    {
        $jeton = self::jeton($this->depots()->ouvrir('image', new UtilisateurDEssai()));

        self::assertStringNotContainsString($jeton, (string) json_encode(array_keys($this->cache->getValues())));
    }

    public function testUneAdresseExpireeEstRefusee(): void
    {
        $depots = $this->depots(dureeS: 0);
        $jeton = self::jeton($depots->ouvrir('image', new UtilisateurDEssai()));

        self::assertNull($depots->consommer($jeton));
    }

    public function testUnJetonInventeEstRefuse(): void
    {
        self::assertNull($this->depots()->consommer(str_repeat('a', 64)));
    }

    /** ⚠ Avec un verrou, le second de deux envois simultanés est refusé. */
    public function testUneAdresseEnCoursDUtilisationEstRefusee(): void
    {
        $verrous = new LockFactory(new InMemoryStore());
        $depots = $this->depots(verrous: $verrous);
        $jeton = self::jeton($depots->ouvrir('image', new UtilisateurDEssai()));

        $pris = $verrous->createLock('qcdgone_mcp_depot_'.hash('sha256', $jeton));
        $pris->acquire();
        self::assertNull($depots->consommer($jeton));
        $pris->release();

        self::assertNotNull($depots->consommer($jeton), 'Le verrou rendu, l’adresse sert encore une fois.');
    }

    public function testUneNatureSansReceptionnaireNeSOuvrePas(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('« pdf »');

        $this->depots()->ouvrir('pdf', new UtilisateurDEssai());
    }

    /** ⚠ Le second masquerait le premier sans que rien ne le dise. */
    public function testDeuxReceptionnairesDeLaMemeNatureSontRefuses(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('servie deux fois');

        $this->depots(receveurs: [new ReceveurDEssai(), new ReceveurDEssai()])->receveur('image');
    }

    /** @param list<ReceveurDEssai>|null $receveurs */
    private function depots(int $dureeS = 900, ?LockFactory $verrous = null, ?array $receveurs = null): Depots
    {
        $routes = new RouteCollection();
        $routes->add(Depots::ROUTE, new Route('/api/mcp/depot/{jeton}'));
        $this->cache = new ArrayAdapter();

        return new Depots($receveurs ?? [new ReceveurDEssai()], $this->cache, new UrlGenerator($routes, new RequestContext('', 'GET', 'essai.exemple.test', 'https')), $dureeS, $verrous);
    }

    /** @param array{adresse: string} $ouverte */
    private static function jeton(array $ouverte): string
    {
        return substr($ouverte['adresse'], -64);
    }
}
