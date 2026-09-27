<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Bundle Symfony BlackSheep\Symfony\ApiResourceBundle (outil de développement).
     */

    declare(strict_types=1);

    namespace BlackSheep\Symfony\ApiResourceBundle;

    use BlackSheep\Symfony\ApiResourceBundle\Source\GeneratorConfig;
    use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
    use Symfony\Component\DependencyInjection\ContainerBuilder;
    use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
    use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

    /**
     * Bundle de génération des ressources API Platform (commande `generate:resource`).
     *
     * Outil de DÉVELOPPEMENT : installé avec `composer require --dev` et enregistré uniquement pour
     * les environnements `dev` et `test` dans `config/bundles.php` :
     *
     *     BlackSheep\Symfony\ApiResourceBundle\ApiResourceBundle::class => ['dev' => true, 'test' => true],
     *
     * Le code généré ne référence jamais le bundle : il ne dépend que d'API Platform, de Doctrine et
     * de Symfony, et fonctionne donc en production sans le bundle.
     *
     * Configuration (fichier `config/packages/dev/black_sheep_api_resource.yaml`) : toutes les clés
     * sont optionnelles, les valeurs par défaut correspondent à une application Symfony standard
     * (`App\` dans `src/`).
     */
    final class ApiResourceBundle extends AbstractBundle {
        /** Alias de l'extension : nom de la racine de configuration. */
        protected string $extensionAlias = 'black_sheep_api_resource';

        /* ── Arbre de configuration ─── */

        /*
         * Déclare les clés de configuration `black_sheep_api_resource`.
         *
         * Les espaces de noms `entity_namespace`, `dto_namespace` et `state_namespace` sont relatifs à
         * `root_namespace` ; `tests.namespace` est absolu.
         */
        public function configure(DefinitionConfigurator $definition): void {
            $definition->rootNode()
                ->children()
                    ->scalarNode('root_namespace')->defaultValue('App')->cannotBeEmpty()->info('Racine PSR-4 du projet.')->end()
                    ->scalarNode('source_dir')->defaultValue('src')->cannotBeEmpty()->info('Dossier des sources, relatif au projet.')->end()
                    ->scalarNode('entity_namespace')->defaultValue('Entity')->cannotBeEmpty()->info('Espace de noms des entités, relatif à root_namespace.')->end()
                    ->scalarNode('dto_namespace')->defaultValue('DTO')->cannotBeEmpty()->info('Espace de noms des DTOs générés, relatif à root_namespace.')->end()
                    ->scalarNode('state_namespace')->defaultValue('State')->cannotBeEmpty()->info('Espace de noms des Processors/Providers générés, relatif à root_namespace.')->end()
                    ->arrayNode('tests')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->scalarNode('namespace')->defaultValue('App\\Tests\\Functional')->cannotBeEmpty()->info('Espace de noms absolu des tests générés.')->end()
                            ->scalarNode('directory')->defaultValue('tests/Functional')->cannotBeEmpty()->info('Dossier des tests générés, relatif au projet.')->end()
                        ->end()
                    ->end()
                    ->scalarNode('admin_role')->defaultValue('ROLE_ADMIN')->cannotBeEmpty()->info('Rôle requis pour lever un soft-erase (itErased).')->end()
                    ->arrayNode('system_fields')
                        ->info('Champs exclus du CreateDto.')
                        ->scalarPrototype()->end()
                        ->defaultValue(GeneratorConfig::DEFAULT_SYSTEM_FIELDS)
                    ->end()
                    ->arrayNode('update_system_fields')
                        ->info('Champs exclus de l\'UpdateDto.')
                        ->scalarPrototype()->end()
                        ->defaultValue(GeneratorConfig::DEFAULT_UPDATE_SYSTEM_FIELDS)
                    ->end()
                    ->scalarNode('templates_directory')->defaultNull()->info('Dossier de gabarits Twig prioritaires (surcharge), relatif au projet ou absolu.')->end()
                ->end();
        }

        /* ── Chargement des services ─── */

        /*
         * Enregistre GeneratorConfig (valeurs de configuration) puis importe les services du bundle.
         */
        public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void {
            $container->services()
                ->set(GeneratorConfig::class)
                ->args([
                    '$projectDir'           => '%kernel.project_dir%',
                    '$rootNamespace'        => $config['root_namespace'],
                    '$sourceDir'            => $config['source_dir'],
                    '$entityNamespace'      => $config['entity_namespace'],
                    '$dtoNamespace'         => $config['dto_namespace'],
                    '$stateNamespace'       => $config['state_namespace'],
                    '$testNamespace'        => $config['tests']['namespace'],
                    '$testDirectory'        => $config['tests']['directory'],
                    '$adminRole'            => $config['admin_role'],
                    '$systemFields'         => $config['system_fields'],
                    '$updateSystemFields'   => $config['update_system_fields'],
                    '$templatesDirectory'   => $config['templates_directory'],
                ]);

            $container->import('../config/services.php');
        }
    }
