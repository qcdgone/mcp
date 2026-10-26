<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use Qcdgone\Mcp\AppelRefuse;
use Qcdgone\Mcp\Appel\PreparationDesArguments;
use Qcdgone\Mcp\ArgumentsInvalides;
use Qcdgone\Mcp\Arguments;
use Qcdgone\Mcp\CatalogueDesOutils;
use Qcdgone\Mcp\EchecPourLAgent;
use Qcdgone\Mcp\Garde\GardeDAppel;
use Qcdgone\Mcp\Journal\IssueDAppel;
use Qcdgone\Mcp\ObjetIntrouvable;
use Qcdgone\Mcp\ServeurMcp;
use Qcdgone\Mcp\Tests\Double\DomaineDEssai;
use Qcdgone\Mcp\Tests\Double\DomaineReserveDEssai;
use Qcdgone\Mcp\Tests\Double\DroitsDEssai;
use Qcdgone\Mcp\Tests\Double\JournalEnMemoire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le protocole, testé UNE fois, ici — il l'était quatre fois, dans quatre
 * applications, avec quatre jeux d'oublis différents.
 */
final class ServeurMcpTest extends TestCase
{
    private JournalEnMemoire $journal;

    public function testUneRequeteQuiNEstPasDuJsonRpcEstRefusee(): void
    {
        foreach ([null, 'texte', ['method' => 'ping', 'id' => 1], ['jsonrpc' => '2.0', 'id' => 1]] as $message) {
            self::assertSame(ServeurMcp::REQUETE_INVALIDE, $this->serveur()->traiter($message)['error']['code']);
        }
    }

    /** ⚠ Les lots ont été retirés du protocole (2025-06-18) : refusés, pas traités à moitié. */
    public function testUnLotEstRefuse(): void
    {
        $reponse = $this->serveur()->traiter([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']]);

        self::assertSame(ServeurMcp::REQUETE_INVALIDE, $reponse['error']['code']);
    }

    public function testUneNotificationNAppelleAucuneReponse(): void
    {
        self::assertNull($this->serveur()->traiter(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    }

    public function testLaVersionDemandeeEstRendueSiElleEstComprise(): void
    {
        $serveur = $this->serveur();

        self::assertSame('2025-03-26', $this->initialiser($serveur, '2025-03-26')['protocolVersion']);
        self::assertSame(ServeurMcp::VERSIONS[0], $this->initialiser($serveur, '1999-01-01')['protocolVersion']);
    }

    public function testLaPresentationVientDeLaConfigurationEtSAncreSurLHoteDeLaRequete(): void
    {
        $resultat = $this->initialiser($this->serveur(['icon' => ['path' => '/icone.png', 'mime_type' => 'image/png', 'sizes' => ['256x256']]]), '2025-06-18');

        self::assertSame('essai', $resultat['serverInfo']['name']);
        self::assertSame('Essai — application', $resultat['serverInfo']['title']);
        self::assertSame('https://essai.exemple.test/icone.png', $resultat['serverInfo']['icons'][0]['src']);
        self::assertSame(['256x256'], $resultat['serverInfo']['icons'][0]['sizes']);
        self::assertSame('https://essai.exemple.test/', $resultat['serverInfo']['websiteUrl']);
        self::assertSame('Commencez par lire.', $resultat['instructions']);
        self::assertSame(['tools' => ['listChanged' => false]], $resultat['capabilities']);
    }

    public function testSansIconeNiSiteRienNEstAnnonce(): void
    {
        $resultat = $this->initialiser($this->serveur(['website_path' => null]), '2025-06-18');

        self::assertArrayNotHasKey('icons', $resultat['serverInfo']);
        self::assertArrayNotHasKey('websiteUrl', $resultat['serverInfo']);
    }

    /** ⚠ Les consignes d'un domaine réservé ne s'affichent qu'à qui le voit : elles le nommeraient. */
    public function testLesConsignesDUnDomaineReserveSuiventLeRole(): void
    {
        $serveur = fn (array $roles): ServeurMcp => new ServeurMcp(
            new CatalogueDesOutils([new DomaineDEssai(), new DomaineReserveDEssai()], [], new DroitsDEssai($roles)),
            new RequestStack(),
            ['name' => 'essai', 'title' => 'Essai', 'version' => '1.0', 'instructions' => 'Commencez par lire.', 'website_path' => null, 'icon' => ['path' => null, 'mime_type' => 'image/png', 'sizes' => []]],
        );

        self::assertSame('Commencez par lire.', $this->initialiser($serveur([]), '2025-06-18')['instructions']);
        self::assertSame('Commencez par lire. Facturation : commencez par factures_lister.', $this->initialiser($serveur(['ROLE_COMPTABLE']), '2025-06-18')['instructions']);
    }

    public function testUneMethodeInconnueEstUneErreurDeProtocole(): void
    {
        $reponse = $this->serveur()->traiter(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/list']);

        self::assertSame(ServeurMcp::METHODE_INCONNUE, $reponse['error']['code']);
        self::assertSame(3, $reponse['id']);
    }

    public function testLaListeDesOutilsVientDesDomaines(): void
    {
        $outils = $this->serveur()->traiter(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'];

        self::assertSame(['lire', 'ecrire', 'vide'], array_column($outils, 'name'));
    }

    public function testUnOutilInconnuOuDesArgumentsEnListeSontDesErreursDeParametres(): void
    {
        $serveur = $this->serveur();

        self::assertSame(ServeurMcp::PARAMETRES_INVALIDES, $this->appeler($serveur, 'inexistant')['error']['code']);
        self::assertSame(ServeurMcp::PARAMETRES_INVALIDES, $this->appeler($serveur, 'lire', [1, 2])['error']['code']);
        self::assertSame(ServeurMcp::PARAMETRES_INVALIDES, $this->appeler($serveur, 'lire', ['id' => 'abc'])['error']['code']);
    }

    public function testUnSuccesPorteLeTexteEtLaChargeStructuree(): void
    {
        $resultat = $this->appeler($this->serveur(), 'lire', ['id' => 12])['result'];

        self::assertFalse($resultat['isError']);
        self::assertSame(['id' => 12], $resultat['structuredContent']);
        self::assertSame('{"id":12}', $resultat['content'][0]['text']);
    }

    /** ⚠ Un objet hors de portée est un RÉSULTAT en échec, que l'agent lit — pas une erreur de protocole. */
    public function testUnObjetIntrouvableEstUnResultatEnEchec(): void
    {
        $resultat = $this->appeler($this->serveur(), 'lire', ['id' => 404])['result'];

        self::assertTrue($resultat['isError']);
        self::assertSame('Fiche introuvable.', $resultat['content'][0]['text']);
    }

    public function testUneEcritureRefuseeDitPourquoiChampParChamp(): void
    {
        $resultat = $this->appeler($this->serveur(), 'ecrire')['result'];

        self::assertTrue($resultat['isError']);
        self::assertSame(['error' => 'Rien n’a été écrit.', 'errors' => ['titre' => 'obligatoire']], $resultat['structuredContent']);
    }

    public function testUnAppelRefuseParUneGardePorteSonCode(): void
    {
        $garde = new class implements GardeDAppel {
            public function verifier(string $outil, array $definition, Arguments $arguments): void
            {
                throw new AppelRefuse('Trop d’appels.', 'plafond_utilisateur', ['reessayer_dans_s' => 30]);
            }
        };

        $resultat = $this->appeler($this->serveur([], new DomaineDEssai(), [$garde]), 'vide')['result'];

        self::assertTrue($resultat['isError']);
        self::assertSame(['error' => 'Trop d’appels.', 'code' => 'plafond_utilisateur', 'reessayer_dans_s' => 30], $resultat['structuredContent']);
        self::assertSame([['outil' => 'vide', 'issue' => IssueDAppel::Refuse, 'code' => 'plafond_utilisateur']], $this->journal->lignes);
    }

    public function testUneExceptionQuiSaitSePresenterDevientUnResultat(): void
    {
        $echec = new class('Quota Google épuisé.') extends \RuntimeException implements EchecPourLAgent {
            public function pourLAgent(): array
            {
                return ['error' => $this->getMessage(), 'code' => 'quotaError'];
            }

            public function codeDEchec(): string
            {
                return 'quotaError';
            }
        };

        $resultat = $this->appeler($this->serveur([], new DomaineDEssai(static fn () => throw $echec)), 'vide')['result'];

        self::assertTrue($resultat['isError']);
        self::assertSame(['error' => 'Quota Google épuisé.', 'code' => 'quotaError'], $resultat['structuredContent']);
        self::assertSame('quotaError', $this->journal->lignes[0]['code']);
    }

    /** ⚠ Un défaut reste un défaut : il remonte, mais il est journalisé d'abord. */
    public function testUneExceptionInattendueRemonteApresAvoirEteJournalisee(): void
    {
        $serveur = $this->serveur([], new DomaineDEssai(static fn () => throw new \DomainException('panne')));

        try {
            $this->appeler($serveur, 'vide');
            self::fail('L’exception aurait dû remonter.');
        } catch (\DomainException) {
        }

        self::assertSame([['outil' => 'vide', 'issue' => IssueDAppel::Erreur, 'code' => 'DomainException']], $this->journal->lignes);
    }

    /**
     * ⚠ Un identifiant illisible pour la préparation est une erreur de l'agent
     *   (-32602), journalisée ; un objet qu'elle ne trouve pas est un résultat.
     */
    public function testUnRefusDeLaPreparationSuitLesDeuxCanaux(): void
    {
        $preparation = new class implements PreparationDesArguments {
            public function preparer(string $outil, array $definition, array $arguments): array
            {
                return match ($arguments['id']) {
                    1 => throw new ArgumentsInvalides('id : illisible.'),
                    2 => throw new ObjetIntrouvable('Fiche 2 introuvable.'),
                    default => $arguments,
                };
            }
        };
        $this->journal = new JournalEnMemoire();
        $serveur = new ServeurMcp(
            new CatalogueDesOutils([$domaine = new DomaineDEssai()], [], null, [$preparation]),
            new RequestStack(),
            ['name' => 'essai', 'title' => 'Essai', 'version' => '1.0', 'instructions' => '…', 'website_path' => null, 'icon' => ['path' => null, 'mime_type' => 'image/png', 'sizes' => []]],
            $this->journal,
        );

        $illisible = $this->appeler($serveur, 'lire', ['id' => 1]);
        self::assertSame(ServeurMcp::PARAMETRES_INVALIDES, $illisible['error']['code']);
        self::assertSame('id : illisible.', $illisible['error']['message']);

        $introuvable = $this->appeler($serveur, 'lire', ['id' => 2])['result'];
        self::assertTrue($introuvable['isError']);
        self::assertSame('Fiche 2 introuvable.', $introuvable['content'][0]['text']);

        self::assertSame([], $domaine->appels);
        self::assertSame([
            ['outil' => 'lire', 'issue' => IssueDAppel::Erreur, 'code' => 'arguments_invalides'],
            ['outil' => 'lire', 'issue' => IssueDAppel::Erreur, 'code' => 'objet_introuvable'],
        ], $this->journal->lignes);
    }

    public function testChaqueAppelDUnOutilConnuEstJournaliseQuelleQuEnSoitLIssue(): void
    {
        $serveur = $this->serveur();
        $this->appeler($serveur, 'lire', ['id' => 1]);
        $this->appeler($serveur, 'lire', ['id' => 404]);
        $this->appeler($serveur, 'ecrire');
        $this->appeler($serveur, 'lire', ['inconnu' => 1]);
        $this->appeler($serveur, 'inexistant');

        self::assertSame([
            ['outil' => 'lire', 'issue' => IssueDAppel::Ok, 'code' => null],
            ['outil' => 'lire', 'issue' => IssueDAppel::Erreur, 'code' => 'objet_introuvable'],
            ['outil' => 'ecrire', 'issue' => IssueDAppel::Erreur, 'code' => 'ecriture_refusee'],
            ['outil' => 'lire', 'issue' => IssueDAppel::Erreur, 'code' => 'arguments_invalides'],
        ], $this->journal->lignes, 'Un outil inconnu n’est pas journalisé : il n’y a rien à dire de lui.');
    }

    /**
     * @param array<string, mixed>    $presentation
     * @param list<GardeDAppel>       $gardes
     */
    private function serveur(array $presentation = [], ?DomaineDEssai $domaine = null, array $gardes = []): ServeurMcp
    {
        $requetes = new RequestStack();
        $requetes->push(Request::create('https://essai.exemple.test/api/mcp', 'POST'));
        $this->journal = new JournalEnMemoire();

        return new ServeurMcp(
            new CatalogueDesOutils([$domaine ?? new DomaineDEssai()], $gardes),
            $requetes,
            $presentation + [
                'name' => 'essai',
                'title' => 'Essai — application',
                'version' => '1.0',
                'instructions' => 'Commencez par lire.',
                'website_path' => '/',
                'icon' => ['path' => null, 'mime_type' => 'image/png', 'sizes' => []],
            ],
            $this->journal,
        );
    }

    /** @return array<string, mixed> */
    private function initialiser(ServeurMcp $serveur, string $version): array
    {
        return $serveur->traiter(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => $version]])['result'];
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function appeler(ServeurMcp $serveur, string $outil, array $arguments = []): array
    {
        return $serveur->traiter(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => $outil, 'arguments' => $arguments]]);
    }
}
