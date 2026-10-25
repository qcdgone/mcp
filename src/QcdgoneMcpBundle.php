<?php

declare(strict_types=1);

namespace Qcdgone\Mcp;

use Qcdgone\Mcp\Controller\McpController;
use Qcdgone\Mcp\DependencyInjection\AppelantDelegueParDefaut;
use Qcdgone\Mcp\Garde\GardeDAppel;
use Qcdgone\Mcp\Garde\LimiteParUtilisateur;
use Qcdgone\Mcp\Journal\JournalDesAppels;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Le serveur MCP des applications du parc.
 *
 * **Le bundle porte le protocole, l'application porte les outils.** Il parle
 * JSON-RPC, valide les arguments d'après le schéma, joue les gardes, journalise,
 * et rend les échecs comme l'agent sait les lire. Ce que les outils font, qui a
 * le droit de les appeler sur quoi, et ce que l'agent doit savoir avant de
 * commencer appartiennent à chaque application.
 *
 * ⚠ **L'identité vient de `qcdgone/sso`, jamais de `qcdgone/api`.** Le serveur
 * n'accepte que les jetons du hub : l'assistant agit comme la personne qui
 * s'est connectée, et voit ce qu'elle voit. Une clé d'API ne désigne personne.
 * `qcdgone/api`, s'il est installé, ne sert qu'à compter les appels.
 *
 * ⚠ **Le pare-feu n'est PAS déclaré ici.** L'ordre des pare-feux est arbitré
 * par l'application, et un bundle qui en ajoute un ne le place jamais là où il
 * faut. Le README donne le bloc à recopier.
 */
final class QcdgoneMcpBundle extends AbstractBundle
{
    protected string $extensionAlias = 'qcdgone_mcp';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('name')
                    ->info('L’identifiant du serveur (`serverInfo.name`), sans espace : « qcdeal », « serelios ».')
                    ->isRequired()->cannotBeEmpty()
                ->end()
                ->scalarNode('title')
                    ->info('Le nom affiché du connecteur : « QCDeal — pipe commercial ».')
                    ->isRequired()->cannotBeEmpty()
                ->end()
                ->scalarNode('version')->defaultValue('1.0')->end()
                ->scalarNode('instructions')
                    // ⚠ Le texte que l'agent lit AVANT tout appel : par où
                    //   commencer, les unités, ce qui est confidentiel. Sans
                    //   lui, il devine — et devine mal les montants.
                    ->info('Ce que l’agent doit savoir avant d’appeler un outil.')
                    ->isRequired()->cannotBeEmpty()
                ->end()
                ->scalarNode('website_path')
                    ->info('Chemin du site, rendu absolu sur l’hôte de la requête (`serverInfo.websiteUrl`) ; null pour ne rien annoncer.')
                    ->defaultValue('/')
                ->end()
                ->arrayNode('icon')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->info('Chemin de l’icône, rendu absolu sur l’hôte de la requête ; null pour aucune.')->defaultNull()->end()
                        ->scalarNode('mime_type')->defaultValue('image/png')->end()
                        ->arrayNode('sizes')->scalarPrototype()->end()->end()
                    ->end()
                ->end()
                ->scalarNode('limite_par_utilisateur')
                    // ⚠ Le limiteur est déclaré par l'APPLICATION
                    //   (`framework.rate_limiter`) : le bundle ne choisit pas
                    //   le débit d'une autre application.
                    ->info('Nom d’un limiteur de `framework.rate_limiter` (« mcp_utilisateur ») : plafond d’appels d’outils par personne. null pour aucun.')
                    ->defaultNull()
                ->end()
            ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AppelantDelegueParDefaut());
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // ⚠ L'autoconfiguration pose les tags : une application qui implémente
        //   l'interface n'a rien d'autre à déclarer.
        $builder->registerForAutoconfiguration(DomaineMcp::class)->addTag('qcdgone_mcp.domaine');
        $builder->registerForAutoconfiguration(GardeDAppel::class)->addTag('qcdgone_mcp.garde');

        $services = $container->services()
            ->defaults()->autowire()->autoconfigure();

        // Les services sont déclarés un par un, jamais par `->load()` sur
        // `src/` : le parc l'attend explicite.
        $services->set(CatalogueDesOutils::class)
            ->arg('$domaines', tagged_iterator('qcdgone_mcp.domaine'))
            ->arg('$gardes', tagged_iterator('qcdgone_mcp.garde'))
            // Pour les domaines réservés à un rôle ; sans lui, ils restent fermés.
            ->arg('$droits', service('security.authorization_checker')->nullOnInvalid());

        $services->set(ServeurMcp::class)
            ->arg('$presentation', [
                'name' => $config['name'],
                'title' => $config['title'],
                'version' => $config['version'],
                'instructions' => $config['instructions'],
                'website_path' => $config['website_path'],
                'icon' => $config['icon'],
            ])
            // ⚠ FACULTATIF : sans implémentation, rien n'est journalisé de plus
            //   que la ligne par requête de `qcdgone/api`.
            ->arg('$journal', service(JournalDesAppels::class)->nullOnInvalid());

        $services->set(McpController::class)
            ->public()
            ->tag('controller.service_arguments');

        if (null !== $config['limite_par_utilisateur']) {
            $services->set(LimiteParUtilisateur::class)
                // ⚠ Sans autoconfiguration : elle poserait le tag une seconde
                //   fois, sans priorité, et la garde jouerait deux fois.
                ->autoconfigure(false)
                ->arg('$limiteurs', service('limiter.'.$config['limite_par_utilisateur']))
                // Avant toute garde de l'application, qui viendrait sinon
                // apprendre à l'agent ce qui lui est ouvert.
                ->tag('qcdgone_mcp.garde', ['priority' => 100]);
        }
    }
}
