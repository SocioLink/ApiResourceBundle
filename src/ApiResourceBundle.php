<?php

	/*
	 * Copyright (c) 2026, Xavier KONGOLO.
	 * All rights reserved.
	 *
	 * This source code is proprietary and confidential.
	 * Unauthorized copying, distribution, modification, publication,
	 * or use of this source code, in whole or in part, is strictly prohibited
	 * without the prior written authorization of the copyright owner.
	 *
	 * Author: Xavier KONGOLO <xsompwe@gmail.com>
	 * Creation Date: 21/09/2026
	 *
	 * Description: Point d'entrée du bundle Symfony SocioLink\ApiResourceBundle, outil de DÉVELOPPEMENT qui génère les
	 *              ressources API Platform (DTOs, State Processors et Providers, paramètres #[QueryParameter], tests
	 *              fonctionnels) à partir des entités Doctrine.
	 *              Déclare l'arbre de configuration `sociolink_api_resource` (espaces de noms et dossiers du projet,
	 *              dossier et espace de noms des tests, rôle d'administration, champs système des DTOs, booléens
	 *              soft-delete/soft-erase, réglages des filtres, dossier de surcharge des gabarits) ; toutes les clés sont
	 *              optionnelles et reproduisent par défaut les conventions d'une application Symfony standard (App\ dans
	 *              src/).
	 *              loadExtension() transforme cette configuration en service GeneratorConfig puis importe
	 *              config/services.php. Enregistré uniquement pour les environnements dev et test : le code généré ne
	 *              référence jamais le bundle.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle;

	use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
	use SocioLink\ApiResourceBundle\Source\GeneratorConfig;
	use Symfony\Component\DependencyInjection\ContainerBuilder;
	use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
	use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

	/**
	 * Bundle de génération des ressources API Platform (commande `generate:resource`).
	 *
	 * Outil de DÉVELOPPEMENT : installé avec `composer require --dev` et enregistré uniquement pour
	 * les environnements `dev` et `test` dans `config/bundles.php` :
	 *
	 *     SocioLink\ApiResourceBundle\ApiResourceBundle::class => ['dev' => true, 'test' => true],
	 *
	 * Le code généré ne référence jamais le bundle : il ne dépend que d'API Platform, de Doctrine et
	 * de Symfony, et fonctionne donc en production sans le bundle.
	 *
	 * Configuration (fichier `config/packages/dev/sociolink_api_resource.yaml`) : toutes les clés
	 * sont optionnelles, les valeurs par défaut correspondent à une application Symfony standard
	 * (`App\` dans `src/`).
	 */
	final class ApiResourceBundle extends AbstractBundle {
		/** Alias de l'extension : nom de la racine de configuration. */
		protected string $extensionAlias = 'sociolink_api_resource';

		/* ── Arbre de configuration ─── */

		/*
		 * Déclare les clés de configuration `sociolink_api_resource`.
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
			           ->arrayNode('boolean_special_fields')
			           ->info('Booléens à logique soft-delete/soft-erase (restauration contrôlée par l\'auteur ou l\'admin).')
			           ->scalarPrototype()->end()
			           ->defaultValue(GeneratorConfig::DEFAULT_BOOLEAN_SPECIAL_FIELDS)
			           ->end()
			           ->arrayNode('filters')
			           ->addDefaultsIfNotSet()
			           ->children()
			           ->arrayNode('excluded_fields')->info('Champs exclus des paramètres de filtrage et de tri.')->scalarPrototype()->end()->defaultValue([])->end()
			           ->booleanNode('sort_on_to_one_relations')->defaultFalse()->info('Autorise le tri sur les relations ToOne (order[author]=asc).')->end()
			           ->end()
			           ->end()
			           ->scalarNode('templates_directory')->defaultNull()->info('Dossier de gabarits Twig prioritaires (surcharge), relatif au projet ou absolu.')->end()
			           ->end();
		}

		/* ── Chargement des services ─── */

		/**
		 * Enregistre GeneratorConfig (valeurs de configuration) puis importe les services du bundle.
		 *
		 * @param array{
		 *     root_namespace: string, source_dir: string, entity_namespace: string, dto_namespace: string,
		 *     state_namespace: string, tests: array{namespace: string, directory: string}, admin_role: string,
		 *     system_fields: list<string>, update_system_fields: list<string>, boolean_special_fields: list<string>,
		 *     filters: array{excluded_fields: list<string>, sort_on_to_one_relations: bool},
		 *     templates_directory: string|null
		 * } $config
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
				                 '$booleanSpecialFields' => $config['boolean_special_fields'],
				                 '$templatesDirectory'   => $config['templates_directory'],
				                 '$filterExcludedFields' => $config['filters']['excluded_fields'],
				                 '$sortOnToOneRelations' => $config['filters']['sort_on_to_one_relations'],
			                 ]);

			$container->import('../config/services.php');
		}
	}
