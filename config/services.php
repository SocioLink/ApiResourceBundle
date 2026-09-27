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
	 * Description: Déclaration des services du bundle SocioLink\ApiResourceBundle, importée par
	 *              ApiResourceBundle::loadExtension().
	 *              La liste des services est EXPLICITE (aucun chargement par glob) pour ne jamais enregistrer par mégarde
	 *              une classe utilitaire ou un objet de valeur : analyseurs (FieldAnalyser, FilterDefinitionBuilder,
	 *              NamespaceResolver), générateurs (DtoBuilder, ProcessorBuilder, ProviderBuilder, TestBuilder), injecteur
	 *              d'attributs, découverte des entités, orchestrateur et services console (fabrique d'options, menu,
	 *              résumé).
	 *              Tous les services sont privés et autowirés ; les contrats ResourceGeneratorInterface et
	 *              EntityDiscoveryInterface sont résolus vers leur unique implémentation. Le moteur Twig dédié
	 *              (sociolink_api_resource.twig) est construit par TwigEnvironmentFactory ; seule la commande est
	 *              autoconfigurée (tag console.command déduit de #[AsCommand]).
	 */

	declare(strict_types=1);

	use Twig\Environment;
	use SocioLink\ApiResourceBundle\Source\DtoBuilder;
	use SocioLink\ApiResourceBundle\Source\TestBuilder;
	use SocioLink\ApiResourceBundle\Source\FieldAnalyser;
	use SocioLink\ApiResourceBundle\Source\GeneratorConfig;
	use SocioLink\ApiResourceBundle\Source\ProviderBuilder;
	use SocioLink\ApiResourceBundle\Source\EntityDiscovery;
	use SocioLink\ApiResourceBundle\Console\EntitySelector;
	use SocioLink\ApiResourceBundle\Console\SummaryPrinter;
	use SocioLink\ApiResourceBundle\Command\GenerateResourceCommand;
	use SocioLink\ApiResourceBundle\Source\ProcessorBuilder;
	use SocioLink\ApiResourceBundle\Source\NamespaceResolver;
	use SocioLink\ApiResourceBundle\Twig\TwigEnvironmentFactory;
	use SocioLink\ApiResourceBundle\Source\EntityAttributeInjector;
	use SocioLink\ApiResourceBundle\Source\FilterDefinitionBuilder;
	use SocioLink\ApiResourceBundle\Source\EntityDiscoveryInterface;
	use SocioLink\ApiResourceBundle\Service\GenerateResourceService;
	use SocioLink\ApiResourceBundle\Console\GenerationOptionsFactory;
	use SocioLink\ApiResourceBundle\Source\ResourceGeneratorInterface;
	use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
	use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

	/*
	 * Services du bundle : liste EXPLICITE (pas de chargement par glob) pour ne jamais enregistrer par
	 * mégarde une classe utilitaire. Tous les services sont privés ; seule la commande est autoconfigurée
	 * (tag console.command déduit de #[AsCommand]).
	 */
	return static function (ContainerConfigurator $container): void {
		$services = $container->services()->defaults()->autowire()->private();

		/* ── Services de génération ─── */
		foreach ([
			         NamespaceResolver::class, FieldAnalyser::class, FilterDefinitionBuilder::class, DtoBuilder::class,
			         ProcessorBuilder::class, ProviderBuilder::class, TestBuilder::class, EntityAttributeInjector::class,
			         EntityDiscovery::class, GenerateResourceService::class,
			         GenerationOptionsFactory::class, EntitySelector::class, SummaryPrinter::class,
		         ] as $class) {
			$services->set($class);
		}

		/* Les contrats sont résolus vers leur unique implémentation (la commande dépend des contrats). */
		$services->alias(ResourceGeneratorInterface::class, GenerateResourceService::class);
		$services->alias(EntityDiscoveryInterface::class, EntityDiscovery::class);

		/* ── Moteur de gabarits dédié ─── */
		$services->set('sociolink_api_resource.twig', Environment::class)
		         ->factory([TwigEnvironmentFactory::class, 'create'])
		         ->args([dirname(__DIR__) . '/templates', service(GeneratorConfig::class)]);

		/* ── Commande ─── */
		$services->set(GenerateResourceCommand::class)->autoconfigure();
	};
