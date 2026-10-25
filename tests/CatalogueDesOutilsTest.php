<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use Qcdgone\Mcp\Arguments;
use Qcdgone\Mcp\ArgumentsInvalides;
use Qcdgone\Mcp\CatalogueDesOutils;
use Qcdgone\Mcp\Garde\GardeDAppel;
use Qcdgone\Mcp\Tests\Double\DomaineDEssai;
use Qcdgone\Mcp\Tests\Double\DomaineReserveDEssai;
use Qcdgone\Mcp\Tests\Double\DroitsDEssai;

final class CatalogueDesOutilsTest extends TestCase
{
    /** ⚠ Un champ mal orthographié serait sinon ignoré, et l'agent croirait avoir modifié ce qu'il n'a pas touché. */
    public function testUnChampInconnuEstRefuseEtLesChampsAcceptesSontDits(): void
    {
        $domaine = new DomaineDEssai();

        try {
            (new CatalogueDesOutils([$domaine]))->appeler('lire', ['id' => 1, 'filtr' => 'x']);
            self::fail('Le champ inconnu aurait dû être refusé.');
        } catch (ArgumentsInvalides $e) {
            self::assertStringContainsString('filtr', $e->getMessage());
            self::assertStringContainsString('Champs acceptés : id, filtre', $e->getMessage());
        }

        self::assertSame([], $domaine->appels, 'Le domaine ne doit jamais voir un appel mal formé.');
    }

    public function testUnChampObligatoireManquantEstRefuse(): void
    {
        $this->expectException(ArgumentsInvalides::class);
        $this->expectExceptionMessage('Champ(s) obligatoire(s) pour lire : id.');

        (new CatalogueDesOutils([new DomaineDEssai()]))->appeler('lire', []);
    }

    /** ⚠ Un appel mal formé est une erreur de l'agent : il ne doit pas entamer son plafond. */
    public function testLesGardesJouentApresLaValidationEtAvantLeDomaine(): void
    {
        $trace = new \ArrayObject();
        $garde = fn (string $nom): GardeDAppel => new class($trace, $nom) implements GardeDAppel {
            public function __construct(private \ArrayObject $trace, private string $nom)
            {
            }

            public function verifier(string $outil, array $definition, Arguments $arguments): void
            {
                $this->trace[] = $this->nom.':'.$outil.':'.(true === $definition['annotations']['readOnlyHint'] ? 'lecture' : 'ecriture');
            }
        };
        $catalogue = new CatalogueDesOutils([new DomaineDEssai()], [$garde('plafond'), $garde('droits')]);

        try {
            $catalogue->appeler('lire', []);
        } catch (ArgumentsInvalides) {
        }
        self::assertSame([], $trace->getArrayCopy());

        $catalogue->appeler('ecrire', ['titre' => 'T']);
        self::assertSame(['plafond:ecrire:ecriture', 'droits:ecrire:ecriture'], $trace->getArrayCopy());
    }

    /** ⚠ Le second masquerait le premier sans que rien ne le dise. */
    public function testDeuxOutilsDuMemeNomSontRefuses(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('« lire » est déclaré deux fois');

        (new CatalogueDesOutils([new DomaineDEssai(), new DomaineDEssai()]))->definitions();
    }

    public function testLaDefinitionDUnOutilSeLitParSonNom(): void
    {
        $catalogue = new CatalogueDesOutils([new DomaineDEssai()]);

        self::assertSame('Écrire', $catalogue->definition('ecrire')['title']);
        self::assertNull($catalogue->definition('inexistant'));
        self::assertTrue($catalogue->existe('vide'));
    }

    /** ⚠ Pour qui n'a pas le rôle, l'outil n'existe pas : ni listé, ni appelable, ni nommé dans les consignes. */
    public function testUnDomaineReserveNExistePasSansSonRole(): void
    {
        $reserve = new DomaineReserveDEssai();
        $catalogue = new CatalogueDesOutils([new DomaineDEssai(), $reserve], [], new DroitsDEssai(['ROLE_USER']));

        self::assertSame(['lire', 'ecrire', 'vide'], array_column($catalogue->definitions(), 'name'));
        self::assertFalse($catalogue->existe('factures_lister'));
        self::assertNull($catalogue->definition('factures_lister'));
        self::assertSame([], $catalogue->consignes());

        try {
            $catalogue->appeler('factures_lister', []);
            self::fail('L’outil réservé aurait dû être inconnu.');
        } catch (ArgumentsInvalides $e) {
            // Mot pour mot le message d'un nom qui n'existe nulle part.
            self::assertSame('Outil inconnu : factures_lister', $e->getMessage());
        }
        self::assertSame([], $reserve->appels);
    }

    public function testUnDomaineReserveSeVoitEtSAppelleAvecSonRole(): void
    {
        $reserve = new DomaineReserveDEssai();
        $catalogue = new CatalogueDesOutils([new DomaineDEssai(), $reserve], [], new DroitsDEssai(['ROLE_COMPTABLE']));

        self::assertContains('factures_lister', array_column($catalogue->definitions(), 'name'));
        self::assertSame(['factures' => []], $catalogue->appeler('factures_lister', []));
        self::assertSame(['Facturation : commencez par factures_lister.'], $catalogue->consignes());
    }

    /** ⚠ Le défaut ferme : sans vérificateur de droits, personne ne voit un domaine réservé. */
    public function testSansVerificateurDeDroitsUnDomaineReserveResteFerme(): void
    {
        self::assertFalse((new CatalogueDesOutils([new DomaineReserveDEssai()]))->existe('factures_lister'));
    }

    /** ⚠ Le service survit aux requêtes (worker, tests) : la visibilité suit l'appelant du moment. */
    public function testLaVisibiliteSuitLAppelantDuMoment(): void
    {
        $droits = new DroitsDEssai(['ROLE_COMPTABLE']);
        $catalogue = new CatalogueDesOutils([new DomaineReserveDEssai()], [], $droits);
        self::assertTrue($catalogue->existe('factures_lister'));

        $droits->roles = [];

        self::assertFalse($catalogue->existe('factures_lister'));
        self::assertSame([], $catalogue->definitions());
    }
}
