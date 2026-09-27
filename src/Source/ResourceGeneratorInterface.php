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
	 * Description: Contrat de l'orchestrateur de génération : traitement complet d'une entité et nettoyage préalable
	 *              --reinit.
	 *              La commande dépend de ce contrat plutôt que de GenerateResourceService, ce qui permet de tester le menu,
	 *              la validation des options et le flux de la commande sans base de données ni écriture disque.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Source;

	use Symfony\Component\Console\Style\SymfonyStyle;

	/**
	 * Contrat de l'orchestrateur de génération : traitement d'une entité et nettoyage --reinit.
	 *
	 * La commande dépend de ce contrat plutôt que de la classe concrète, ce qui permet de la tester
	 * (menu interactif, validation des options) sans base de données ni écriture disque.
	 */
	interface ResourceGeneratorInterface {
		/**
		 * Supprime les dossiers DTO et State des entités données avant leur régénération (--force --reinit).
		 *
		 * @param list<string> $entityClasses FQCN des entités traitées
		 * @param SymfonyStyle $io
		 */
		public function cleanBeforeReinit(array $entityClasses, SymfonyStyle $io): void;

		/**
		 * Traite une entité et retourne le résultat de chaque opération.
		 *
		 * @param string            $entityClass FQCN complet de l'entité
		 * @param GenerationOptions $options     Options de génération actives
		 * @param SymfonyStyle      $io
		 *
		 * @return array<string, string|array{booleanCount: int, uploadCount: int}> Résultat par artefact ('entity', 'createDto', …) et '_stats'
		 */
		public function processEntity(string $entityClass, GenerationOptions $options, SymfonyStyle $io): array;
	}
