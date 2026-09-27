<?php

	/*
	 * Copyright (c) 2026.
	 * Date: 21/09/2026
	 * Author: Xavier KONGOLO <xsompwe@gmail.com>
	 * Description: Contrat de découverte des entités Doctrine du projet.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Source;

	use Symfony\Component\Console\Style\SymfonyStyle;

	/**
	 * Contrat de découverte et de validation des entités à traiter.
	 *
	 * Séparé de l'implémentation Doctrine pour que la commande reste de l'orchestration pure
	 * et puisse être testée sans base de métadonnées.
	 */
	interface EntityDiscoveryInterface {
		/**
		 * Résout un argument CLI (nom court ou FQCN) en liste d'une entité valide.
		 *
		 * @return list<string>|null Liste d'un FQCN, ou null si l'argument est invalide (l'erreur est déjà affichée)
		 */
		public function resolveEntityArgument(string $arg, SymfonyStyle $io): ?array;

		/**
		 * Découvre toutes les entités Doctrine du projet, triées alphabétiquement.
		 *
		 * @return list<string> Liste de FQCN (vide si le dossier des entités n'existe pas)
		 */
		public function discoverAllEntities(SymfonyStyle $io): array;
	}
