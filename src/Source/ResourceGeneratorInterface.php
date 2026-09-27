<?php

	/*
	 * Copyright (c) 2026.
	 * Date: 21/09/2026
	 * Author: Xavier KONGOLO <xsompwe@gmail.com>
	 * Description: Contrat de l'orchestrateur de génération des artefacts d'une entité.
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
