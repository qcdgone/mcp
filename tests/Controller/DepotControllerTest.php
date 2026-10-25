<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Qcdgone\Mcp\Controller\DepotController;
use Qcdgone\Mcp\Depot\Depots;
use Qcdgone\Mcp\Journal\IssueDAppel;
use Qcdgone\Mcp\Tests\Double\JournalDesDepotsEnMemoire;
use Qcdgone\Mcp\Tests\Double\ReceveurDEssai;
use Qcdgone\Mcp\Tests\Double\UtilisateurDEssai;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class DepotControllerTest extends TestCase
{
    private Depots $depots;
    private ReceveurDEssai $receveur;
    private JournalDesDepotsEnMemoire $journal;
    private DepotController $controleur;

    protected function setUp(): void
    {
        $routes = new RouteCollection();
        $routes->add(Depots::ROUTE, new Route('/api/mcp/depot/{jeton}'));
        $this->receveur = new ReceveurDEssai();
        $this->depots = new Depots([$this->receveur], new ArrayAdapter(), new UrlGenerator($routes, new RequestContext()));
        $this->journal = new JournalDesDepotsEnMemoire();
        $this->controleur = new DepotController($this->depots, $this->journal);
    }

    public function testLeFichierVaAuReceptionnaireEtSaReponseRevientALAgent(): void
    {
        $reponse = $this->controleur->recevoir($this->jeton(), self::put('PNG-octets'));

        self::assertSame(200, $reponse->getStatusCode());
        self::assertSame(['chemin' => '/uploads/essai.png', 'pour' => 42], json_decode((string) $reponse->getContent(), true));
        self::assertSame(['PNG-octets'], $this->receveur->recus);
        self::assertSame([['nature' => 'image', 'utilisateur' => 42, 'octets' => 10, 'issue' => IssueDAppel::Ok, 'code' => null]], $this->journal->lignes);
    }

    public function testUnSecondEnvoiSurLaMemeAdresseEstRefuse(): void
    {
        $jeton = $this->jeton();
        $this->controleur->recevoir($jeton, self::put('PNG-1'));

        self::assertSame(410, $this->controleur->recevoir($jeton, self::put('PNG-2'))->getStatusCode());
        self::assertSame(['PNG-1'], $this->receveur->recus);
    }

    /** ⚠ Consommée au premier envoi, même refusé : réessayer demande une nouvelle adresse. */
    public function testUnRefusConsommeAussiLAdresse(): void
    {
        $jeton = $this->jeton();
        $refus = $this->controleur->recevoir($jeton, self::put('<?php echo 1;'));

        self::assertSame(422, $refus->getStatusCode());
        self::assertSame('Ce n’est pas une image.', json_decode((string) $refus->getContent(), true)['error']);
        self::assertSame([], $this->receveur->recus);
        self::assertSame(IssueDAppel::Refuse, $this->journal->lignes[0]['issue']);
        self::assertSame(410, $this->controleur->recevoir($jeton, self::put('PNG'))->getStatusCode());
    }

    /** ⚠ Annoncé trop lourd : refusé sans être lu. */
    public function testUnFichierAnnonceTropLourdEstRefuseSansEtreLu(): void
    {
        $requete = self::put('PNG');
        $requete->headers->set('Content-Length', '5000000');

        self::assertSame(413, $this->controleur->recevoir($this->jeton(), $requete)->getStatusCode());
        self::assertSame([], $this->receveur->recus);
    }

    /** ⚠ Sans annonce de poids (envoi par morceaux), la lecture s'arrête au plafond. */
    public function testUnFichierTropLourdNonAnnonceEstRefuse(): void
    {
        $requete = self::put('PNG'.str_repeat('x', 200));
        $requete->headers->remove('Content-Length');

        self::assertSame(413, $this->controleur->recevoir($this->jeton(), $requete)->getStatusCode());
        self::assertSame('trop_lourd', $this->journal->lignes[0]['code']);
    }

    public function testUnCorpsVideEstRefuse(): void
    {
        self::assertSame(400, $this->controleur->recevoir($this->jeton(), self::put(''))->getStatusCode());
    }

    public function testUneAdresseInconnueEstRefusee(): void
    {
        self::assertSame(410, $this->controleur->recevoir(str_repeat('b', 64), self::put('PNG'))->getStatusCode());
        self::assertSame([], $this->journal->lignes);
    }

    private function jeton(): string
    {
        return substr($this->depots->ouvrir('image', new UtilisateurDEssai(42))['adresse'], -64);
    }

    private static function put(string $corps): Request
    {
        return Request::create('/api/mcp/depot/x', 'PUT', server: ['CONTENT_LENGTH' => (string) \strlen($corps)], content: $corps);
    }
}
