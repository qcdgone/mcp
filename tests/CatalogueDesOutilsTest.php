<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use Qcdgone\Mcp\AppelRefuse;
use Qcdgone\Mcp\Appel\EnveloppeDAppel;
use Qcdgone\Mcp\Appel\PreparationDesArguments;
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

    /**
     * ⚠ La forme normalisée d'un identifiant doit atteindre les gardes ET le
     *   domaine : une garde qui lirait « 123-456 » et un domaine « 123456 »
     *   contrôleraient deux choses différentes.
     */
    public function testUnePreparationNormaliseLesArgumentsAvantLesGardesEtLeDomaine(): void
    {
        $vus = new \ArrayObject();
        $domaine = new DomaineDEssai(static function (string $nom, Arguments $arguments) use ($vus): array {
            $vus[] = 'domaine:'.$arguments->texte('filtre');

            return [];
        });
        $preparation = new class implements PreparationDesArguments {
            public function preparer(string $outil, array $definition, array $arguments): array
            {
                if (isset($arguments['filtre'])) {
                    $arguments['filtre'] = str_replace('-', '', (string) $arguments['filtre']);
                }

                return $arguments;
            }
        };
        $garde = new class($vus) implements GardeDAppel {
            public function __construct(private \ArrayObject $vus)
            {
            }

            public function verifier(string $outil, array $definition, Arguments $arguments): void
            {
                $this->vus[] = 'garde:'.$arguments->texte('filtre');
            }
        };

        (new CatalogueDesOutils([$domaine], [$garde], null, [$preparation]))->appeler('lire', ['id' => 1, 'filtre' => '123-456']);

        self::assertSame(['garde:123456', 'domaine:123456'], $vus->getArrayCopy());
    }

    /** ⚠ Un identifiant illisible est une erreur d'argument : ni garde (pas de plafond entamé), ni domaine. */
    public function testUnePreparationQuiRefuseArreteAvantLesGardes(): void
    {
        $domaine = new DomaineDEssai();
        $gardes = new \ArrayObject();
        $garde = new class($gardes) implements GardeDAppel {
            public function __construct(private \ArrayObject $gardes)
            {
            }

            public function verifier(string $outil, array $definition, Arguments $arguments): void
            {
                $this->gardes[] = $outil;
            }
        };
        $preparation = new class implements PreparationDesArguments {
            public function preparer(string $outil, array $definition, array $arguments): array
            {
                throw new ArgumentsInvalides('filtre : illisible.');
            }
        };

        try {
            (new CatalogueDesOutils([$domaine], [$garde], null, [$preparation]))->appeler('lire', ['id' => 1, 'filtre' => '?']);
            self::fail('La préparation aurait dû refuser.');
        } catch (ArgumentsInvalides $e) {
            self::assertSame('filtre : illisible.', $e->getMessage());
        }

        self::assertSame([], $gardes->getArrayCopy());
        self::assertSame([], $domaine->appels);
    }

    /** L'enveloppe entoure le SEUL appel au domaine, après les gardes ; la première est la plus extérieure. */
    public function testLesEnveloppesEntourentLAppelAuDomaineApresLesGardes(): void
    {
        $trace = new \ArrayObject();
        $domaine = new DomaineDEssai(static function (string $nom) use ($trace): array {
            $trace[] = 'domaine';

            return ['fait' => true];
        });
        $garde = new class($trace) implements GardeDAppel {
            public function __construct(private \ArrayObject $trace)
            {
            }

            public function verifier(string $outil, array $definition, Arguments $arguments): void
            {
                $this->trace[] = 'garde';
            }
        };
        $enveloppe = fn (string $nom): EnveloppeDAppel => new class($trace, $nom) implements EnveloppeDAppel {
            public function __construct(private \ArrayObject $trace, private string $nom)
            {
            }

            public function envelopper(string $outil, array $definition, \Closure $appel): array
            {
                $lecture = true === $definition['annotations']['readOnlyHint'] ? 'lecture' : 'ecriture';
                $this->trace[] = $this->nom.':avant:'.$lecture;
                try {
                    return $appel();
                } finally {
                    $this->trace[] = $this->nom.':apres';
                }
            }
        };

        $resultat = (new CatalogueDesOutils([$domaine], [$garde], null, [], [$enveloppe('cache'), $enveloppe('chrono')]))->appeler('vide', []);

        self::assertSame(['fait' => true], $resultat);
        self::assertSame(['garde', 'cache:avant:lecture', 'chrono:avant:lecture', 'domaine', 'chrono:apres', 'cache:apres'], $trace->getArrayCopy());
    }

    /** ⚠ Un appel refusé par une garde n'atteint pas l'enveloppe : un cache ne s'ouvre pas pour rien. */
    public function testUnAppelRefuseParUneGardeNAtteintPasLEnveloppe(): void
    {
        $enveloppe = new class implements EnveloppeDAppel {
            public int $appels = 0;

            public function envelopper(string $outil, array $definition, \Closure $appel): array
            {
                ++$this->appels;

                return $appel();
            }
        };
        $garde = new class implements GardeDAppel {
            public function verifier(string $outil, array $definition, Arguments $arguments): void
            {
                throw new AppelRefuse('Non.', 'refuse');
            }
        };

        try {
            (new CatalogueDesOutils([new DomaineDEssai()], [$garde], null, [], [$enveloppe]))->appeler('vide', []);
            self::fail('La garde aurait dû refuser.');
        } catch (AppelRefuse) {
        }

        self::assertSame(0, $enveloppe->appels);
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
