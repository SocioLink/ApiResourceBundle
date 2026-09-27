<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Fabrique du moteur de gabarits Twig dédié du bundle.
     */

    declare(strict_types=1);

    namespace BlackSheep\Symfony\ApiResourceBundle\Twig;

    use Twig\Environment;
    use Twig\Loader\FilesystemLoader;
    use BlackSheep\Symfony\ApiResourceBundle\Source\GeneratorConfig;

    /**
     * Fabrique l'environnement Twig propre au bundle (service `black_sheep_api_resource.twig`).
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
                $override = self::isAbsolute($config->templatesDirectory)
                    ? $config->templatesDirectory
                    : $config->projectDir . '/' . trim($config->templatesDirectory, '/');

                if (is_dir($override)) {
                    $paths[] = $override; /* prioritaire : Twig cherche dans l'ordre des chemins */
                }
            }

            $paths[] = $bundleTemplatesDir;

            return new Environment(new FilesystemLoader($paths));
        }

        /* Indique si le chemin est absolu (Unix « / », ou Windows « C:\ » / « C:/ »). */
        private static function isAbsolute(string $path): bool {
            return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
        }
    }
