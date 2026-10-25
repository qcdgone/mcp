<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Qcdgone\Mcp\CatalogueDesOutils;
use Qcdgone\Mcp\Controller\McpController;
use Qcdgone\Mcp\ServeurMcp;
use Qcdgone\Mcp\Tests\Double\DomaineDEssai;
use Qcdgone\Mcp\Tests\Double\UtilisateurDEssai;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class McpControllerTest extends TestCase
{
    /** ⚠ Le hub est l'autorité : un compte révoqué ne lit plus rien, même avec un jeton encore valide. */
    public function testUnCompteRevoqueEstRefuse(): void
    {
        $reponse = $this->controleur(new UtilisateurDEssai(7, revoque: true))->traiter($this->requete(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));

        self::assertSame(403, $reponse->getStatusCode());
    }

    /** ⚠ Un oubli d'`implements` doit FERMER le serveur au premier essai, pas ouvrir l'accès aux comptes révoqués. */
    public function testUnAppelantQueLeBundleNeSaitPasLireEstRefuse(): void
    {
        $reponse = $this->controleur(new InMemoryUser('personne@exemple.test', null, ['ROLE_USER']))->traiter($this->requete(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));

        self::assertSame(403, $reponse->getStatusCode());
    }

    public function testSansAppelantAuthentifieRienNePasse(): void
    {
        self::assertSame(403, $this->controleur(null)->traiter($this->requete(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']))->getStatusCode());
    }

    public function testUnCorpsQuiNEstPasDuJsonEstUneErreurDAnalyse(): void
    {
        $reponse = $this->controleur(new UtilisateurDEssai())->traiter(Request::create('/api/mcp', 'POST', content: '{pas du json'));

        self::assertSame(400, $reponse->getStatusCode());
        self::assertSame(ServeurMcp::ERREUR_ANALYSE, json_decode((string) $reponse->getContent(), true)['error']['code']);
    }

    public function testUneNotificationEstAcquitteeSansCorps(): void
    {
        $reponse = $this->controleur(new UtilisateurDEssai())->traiter($this->requete(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        self::assertSame(202, $reponse->getStatusCode());
        self::assertSame('', $reponse->getContent());
    }

    public function testUneRequeteRecoitSaReponseEnJson(): void
    {
        $reponse = $this->controleur(new UtilisateurDEssai())->traiter($this->requete(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping']));

        self::assertSame(200, $reponse->getStatusCode());
        self::assertSame(['jsonrpc' => '2.0', 'id' => 4, 'result' => []], json_decode((string) $reponse->getContent(), true));
    }

    public function testLeServeurNOuvreAucunFlux(): void
    {
        $reponse = $this->controleur(null)->flux();

        self::assertSame(405, $reponse->getStatusCode());
        self::assertSame('POST', $reponse->headers->get('Allow'));
    }

    private function controleur(?UserInterface $utilisateur): McpController
    {
        $jetons = new TokenStorage();
        if (null !== $utilisateur) {
            $jetons->setToken(new UsernamePasswordToken($utilisateur, 'mcp', $utilisateur->getRoles()));
        }
        $conteneur = new Container();
        $conteneur->set('security.token_storage', $jetons);

        $serveur = new ServeurMcp(new CatalogueDesOutils([new DomaineDEssai()]), new RequestStack(), [
            'name' => 'essai', 'title' => 'Essai', 'version' => '1.0', 'instructions' => '…',
            'website_path' => null, 'icon' => ['path' => null, 'mime_type' => 'image/png', 'sizes' => []],
        ]);

        return new McpController($serveur, new Security($conteneur));
    }

    /** @param array<string, mixed> $message */
    private function requete(array $message): Request
    {
        return Request::create('/api/mcp', 'POST', content: (string) json_encode($message));
    }
}
