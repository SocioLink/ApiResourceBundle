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
	 * Creation Date: 04/10/2026
	 *
	 * Description: Génération du catalogue de filtres d'une entité (<Entité>Filters), classe de l'application qui implémente
	 *              son contrat (CollectionFiltersInterface) et retourne, dans parameters(), les QueryParameter issus de
	 *              FilterDefinitionBuilder. L'entité y fait référence par `extraProperties: ['collection_filters' => ...]`
	 *              sur GetCollection et QueryCollection (voir EntityAttributeInjector).
	 *              Le contrat, StatusParameter et RealmParameter appartiennent à l'application : leurs noms se règlent par la
	 *              configuration (filters.catalogue_interface, filters.status_parameter, filters.realm_parameter).
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Source;

	use Twig\Environment;
	use Symfony\Component\DependencyInjection\Attribute\Autowire;

	/**
	 * Produit le code PHP du catalogue de filtres d'une entité.
	 *
	 * Le catalogue est le seul endroit où les paramètres de filtrage sont déclarés : l'entité ne porte aucun
	 * `parameters:` et se contente de désigner la classe. Une entité sans champ filtrable n'a pas de catalogue.
	 *
	 * @internal Réservé à l'usage interne de la commande generate:resource.
	 */
	final class FilterCatalogueBuilder {
		/* $twig est le moteur de gabarits dédié du bundle (service sociolink_api_resource.twig). */
		public function __construct(
			private readonly NamespaceResolver                                               $namespaceResolver,
			private readonly FilterDefinitionBuilder                                         $filterBuilder,
			private readonly GeneratorConfig                                                 $config,
			#[Autowire(service: 'sociolink_api_resource.twig')] private readonly Environment $twig,
		) {}

		/*
		 * Génère le code du catalogue de filtres de l'entité.
		 *
		 * Paramètre $entityClass : FQCN complet de l'entité.
		 * Paramètre $fields      : champs de l'entité (FieldAnalyser::getEntityFields()).
		 *
		 * Retour : code PHP complet du fichier, ou null si aucun champ n'est filtrable.
		 */
		/**
		 * @param array<string, array<string, mixed>> $fields
		 */
		public function build(string $entityClass, array $fields): string|null {
			$definitions = $this->filterBuilder->buildDefinitions($fields);

			if ($definitions === []) {
				return null;
			}

			$namespace = $this->namespaceResolver->getFilterNamespace($entityClass);

			/* Une classe du même espace de noms que le catalogue (ex. le contrat, pour une entité sans sous-espace) ne s'importe pas. */
			$fqcns = array_values(array_filter(
				array_unique([$this->config->filterInterface, ...$this->filterBuilder->requiredFqcns($definitions)]),
				static fn(string $fqcn): bool => substr($fqcn, 0, (int)strrpos($fqcn, '\\')) !== $namespace,
			));
			sort($fqcns);

			return $this->twig->render('filters.php.twig', [
				'namespace'        => $namespace,
				'uses_block'       => implode("\n", array_map(static fn(string $fqcn): string => "use {$fqcn};", $fqcns)),
				'entity_name'      => $this->namespaceResolver->getShortClassName($entityClass),
				'interface_name'   => $this->namespaceResolver->getShortClassName($this->config->filterInterface),
				'parameters_block' => implode("\n", $this->filterBuilder->renderParameters($definitions, '            ')),
			]);
		}
	}
