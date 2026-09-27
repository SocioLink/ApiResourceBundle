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
	 * Description: Fabrique du moteur Twig dédié au bundle (service sociolink_api_resource.twig), indépendant de TwigBundle
	 *              et de la configuration Twig de l'application.
	 *              Les gabarits sont cherchés d'abord dans templates_directory (surcharge du projet, chemin relatif ou
	 *              absolu, Windows compris), puis dans les gabarits livrés avec le bundle : un projet peut surcharger un
	 *              seul gabarit sans dupliquer les autres.
	 *              Configuré pour générer du PHP et non du HTML : aucun échappement automatique, et strict_variables actif
	 *              pour qu'une variable manquante dans un gabarit soit une erreur plutôt qu'une chaîne vide silencieuse.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Twig;

	use Twig\Environment;
	use Twig\Loader\FilesystemLoader;
	use SocioLink\ApiResourceBundle\Source\GeneratorConfig;

	/**
	 * Fabrique l'environnement Twig propre au bundle (service `sociolink_api_resource.twig`).
	 *
	 * Le bundle possède son PROPRE moteur : il ne dépend ni de TwigBundle ni de la configuration Twig
	 * de l'application, et les gabarits générés ne sont jamais échappés ni mis en cache par erreur.
	 *
	 * Ordre de résolution des gabarits :
	 *  1. `templates_directory` de la configuration (surcharge du projet), s'il est défini et existe ;
	 *  2. les gabarits livrés avec le bundle.
	 *
	 * Un projet peut donc surcharger un seul gabarit (ex. `create_dto.php.twig`) en le copiant dans son
	 * dossier de surcharge, sans dupliquer les autres.
	 *
	 * @internal Réservé à l'usage interne du bundle.
	 */
	final class TwigEnvironmentFactory {
		/*
		 * Crée l'environnement Twig.
		 *
		 * Paramètre $bundleTemplatesDir : dossier des gabarits livrés avec le bundle.
		 * Paramètre $config             : configuration résolue (dossier de surcharge éventuel).
		 */
		public static function create(string $bundleTemplatesDir, GeneratorConfig $config): Environment {
			$paths = [];

			if ($config->templatesDirectory !== null && $config->templatesDirectory !== '') {
				$override = self::isAbsolute($config->templatesDirectory) ? $config->templatesDirectory : $config->projectDir . '/' . trim($config->templatesDirectory, '/');

				if (is_dir($override)) {
					$paths[] = $override; /* prioritaire : Twig cherche dans l'ordre des chemins */
				}
			}

			$paths[] = $bundleTemplatesDir;

			/* Du code PHP est généré, pas du HTML : aucun échappement ; une variable manquante est une erreur, pas une chaîne vide. */
			return new Environment(new FilesystemLoader($paths), ['autoescape' => false, 'strict_variables' => true]);
		}

		/* Indique si le chemin est absolu (Unix « / », ou Windows « C:\ » / « C:/ »). */
		private static function isAbsolute(string $path): bool {
			return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
		}
	}
