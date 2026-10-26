<?php

declare(strict_types=1);

namespace Qcdgone\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use Qcdgone\Api\Security\DelegatedCallerResolverInterface;
use Qcdgone\Mcp\CatalogueDesOutils;
use Qcdgone\Mcp\Controller\DepotController;
use Qcdgone\Mcp\Controller\McpController;
use Qcdgone\Mcp\Depot\Depots;
use Qcdgone\Mcp\Garde\LimiteParUtilisateur;
use Qcdgone\Mcp\QcdgoneMcpBundle;
use Qcdgone\Mcp\Securite\AppelantDelegue;
use Qcdgone\Mcp\ServeurMcp;
use Qcdgone\Mcp\Tests\Double\DomaineDEssai;
use Qcdgone\Mcp\Tests\Double\EnveloppeDEssai;
use Qcdgone\Mcp\Tests\Double\PreparationDEssai;
use Qcdgone\Mcp\Tests\Double\ReceveurDEssai;
use Qcdgone\Mcp\Tests\Double\UtilisateurDEssai;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Le bundle se CHARGE, et son conteneur se COMPILE.
 *
 * ⚠ **Des tests unitaires sur des services ne prouvent RIEN du câblage** — la
 * v0.4.0 de `qcdgone/api` est partie avec une extension qui ne compilait pas,
 * toutes suites vertes. Ce test passe par l'extension, comme une application.
 */
final class ChargementDuBundleTest extends TestCase
{
    public function testUnDomaineDeLApplicationEstRecenseSansRienDeclarer(): void
    {
        $conteneur = $this->compiler(['name' => 'essai', 'title' => 'Essai', 'instructions' => 'Lire d’abord.']);

        $catalogue = $conteneur->get(CatalogueDesOutils::class);
        \assert($catalogue instanceof CatalogueDesOutils);

        self::assertTrue($catalogue->existe('lire'), 'L’autoconfiguration doit poser le tag sur un DomaineMcp.');
        self::assertTrue($conteneur->has(ServeurMcp::class));
        self::assertTrue($conteneur->has(McpController::class));
    }

    /** ⚠ L'autoconfiguration recense un réceptionnaire de l'application sans rien déclarer. */
    public function testUnReceptionnaireDeLApplicationEstRecense(): void
    {
        $conteneur = $this->compiler(['name' => 'essai', 'title' => 'Essai', 'instructions' => '…'], static function (ContainerBuilder $builder): void {
            $builder->register(ReceveurDEssai::class, ReceveurDEssai::class)->setAutoconfigured(true);
        });

        $depots = $conteneur->get(Depots::class);
        \assert($depots instanceof Depots);
        self::assertInstanceOf(ReceveurDEssai::class, $depots->receveur('image'));
        self::assertTrue($conteneur->has(DepotController::class));
    }

    /** ⚠ Une préparation ou une enveloppe de l'application jouent sans rien déclarer. */
    public function testUnePreparationEtUneEnveloppeDeLApplicationSontRecensees(): void
    {
        $conteneur = $this->compiler(['name' => 'essai', 'title' => 'Essai', 'instructions' => '…'], static function (ContainerBuilder $builder): void {
            $builder->register(PreparationDEssai::class, PreparationDEssai::class)->setAutoconfigured(true)->setPublic(true);
            $builder->register(EnveloppeDEssai::class, EnveloppeDEssai::class)->setAutoconfigured(true)->setPublic(true);
        });

        $catalogue = $conteneur->get(CatalogueDesOutils::class);
        \assert($catalogue instanceof CatalogueDesOutils);
        $catalogue->appeler('lire', ['id' => 1]);

        $preparation = $conteneur->get(PreparationDEssai::class);
        $enveloppe = $conteneur->get(EnveloppeDEssai::class);
        \assert($preparation instanceof PreparationDEssai && $enveloppe instanceof EnveloppeDEssai);
        self::assertSame(['lire'], $preparation->outils, 'L’autoconfiguration doit poser le tag sur une PreparationDesArguments.');
        self::assertSame(['lire'], $enveloppe->outils, 'L’autoconfiguration doit poser le tag sur une EnveloppeDAppel.');
    }

    /** ⚠ Le texte que l'agent lit avant tout appel : sans lui, il devine. */
    public function testLesInstructionsSontObligatoires(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->configurer(['name' => 'essai', 'title' => 'Essai']);
    }

    /** ⚠ Sans lui, les domaines réservés à un rôle seraient fermés à tous, administrateurs compris. */
    public function testLeCatalogueRecoitLeVerificateurDeDroits(): void
    {
        $conteneur = $this->construire(['name' => 'essai', 'title' => 'Essai', 'instructions' => '…']);
        (new MergeExtensionConfigurationPass())->process($conteneur);

        $droits = $conteneur->getDefinition(CatalogueDesOutils::class)->getArgument('$droits');
        self::assertInstanceOf(Reference::class, $droits);
        self::assertSame('security.authorization_checker', (string) $droits);
    }

    public function testLaGardeDeDebitNExisteQueSiUnLimiteurEstNomme(): void
    {
        $sans = $this->construire(['name' => 'essai', 'title' => 'Essai', 'instructions' => '…']);
        $avec = $this->construire(['name' => 'essai', 'title' => 'Essai', 'instructions' => '…', 'limite_par_utilisateur' => 'mcp_utilisateur']);
        // L'extension ne se charge qu'à la fusion des configurations, première passe de la compilation.
        (new MergeExtensionConfigurationPass())->process($sans);
        (new MergeExtensionConfigurationPass())->process($avec);

        self::assertFalse($sans->hasDefinition(LimiteParUtilisateur::class));
        self::assertTrue($avec->hasDefinition(LimiteParUtilisateur::class));
        self::assertEquals(new Reference('limiter.mcp_utilisateur'), $avec->getDefinition(LimiteParUtilisateur::class)->getArgument('$limiteurs'));
        self::assertSame([['priority' => 100]], $avec->getDefinition(LimiteParUtilisateur::class)->getTag('qcdgone_mcp.garde'));
    }

    /** ⚠ Sans appelant délégué, les appels MCP échappaient au quota ET au journal de `qcdgone/api` — le cas de QCDeal. */
    public function testLAppelantDelegueEstBrancheDOffice(): void
    {
        $conteneur = $this->compiler(['name' => 'essai', 'title' => 'Essai', 'instructions' => '…']);
        $resolveur = $conteneur->get(DelegatedCallerResolverInterface::class);
        \assert($resolveur instanceof DelegatedCallerResolverInterface);

        self::assertInstanceOf(AppelantDelegue::class, $resolveur);
        self::assertSame('utilisateur:42', $resolveur->ownerReferenceOf(new UtilisateurDEssai(42)));
    }

    public function testLAppelantDelegueDeLApplicationEstRespecte(): void
    {
        $conteneur = $this->compiler(['name' => 'essai', 'title' => 'Essai', 'instructions' => '…'], static function (ContainerBuilder $builder): void {
            $builder->register('appelant.de.l.application', AppelantDeLApplication::class);
            $builder->setAlias(DelegatedCallerResolverInterface::class, 'appelant.de.l.application')->setPublic(true);
        });

        self::assertInstanceOf(AppelantDeLApplication::class, $conteneur->get(DelegatedCallerResolverInterface::class));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function configurer(array $config): array
    {
        $extension = (new QcdgoneMcpBundle())->getContainerExtension();
        \assert(null !== $extension);

        return (new Processor())->processConfiguration($extension->getConfiguration([], new ContainerBuilder()), [$config]);
    }

    /** @param array<string, mixed> $config */
    private function construire(array $config, ?\Closure $application = null): ContainerBuilder
    {
        $bundle = new QcdgoneMcpBundle();
        $builder = new ContainerBuilder();
        $builder->registerExtension($bundle->getContainerExtension());
        $builder->loadFromExtension('qcdgone_mcp', $config);
        $bundle->build($builder);

        // Ce que l'application et le framework apportent.
        $builder->register(RequestStack::class, RequestStack::class);
        $builder->register('security.helper', \stdClass::class);
        $builder->setAlias(\Symfony\Bundle\SecurityBundle\Security::class, 'security.helper');
        $builder->register('limiter.mcp_utilisateur', \stdClass::class);
        $builder->register('cache.app', \Symfony\Component\Cache\Adapter\ArrayAdapter::class);
        $builder->register('router', \Symfony\Component\Routing\Generator\UrlGenerator::class)
            ->setArguments([new \Symfony\Component\Routing\RouteCollection(), new \Symfony\Component\Routing\RequestContext()]);
        $builder->register(DomaineDEssai::class, DomaineDEssai::class)->setAutoconfigured(true);
        if (null !== $application) {
            $application($builder);
        }

        return $builder;
    }

    /** @param array<string, mixed> $config */
    private function compiler(array $config, ?\Closure $application = null): ContainerBuilder
    {
        $builder = $this->construire($config, $application);
        // Rendus publics pour être lus après compilation.
        $builder->addCompilerPass(new class implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ([CatalogueDesOutils::class, ServeurMcp::class, McpController::class, Depots::class, DepotController::class] as $id) {
                    $container->getDefinition($id)->setPublic(true);
                }
                if ($container->hasAlias(DelegatedCallerResolverInterface::class)) {
                    $container->getAlias(DelegatedCallerResolverInterface::class)->setPublic(true);
                }
            }
        }, \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_REMOVING);
        $builder->compile();

        return $builder;
    }
}

final class AppelantDeLApplication implements DelegatedCallerResolverInterface
{
    public function ownerReferenceOf(UserInterface $caller): ?string
    {
        return 'application';
    }
}
