<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Configuration résolue du générateur (namespaces, chemins, champs système, rôle d'administration).
     */

    declare(strict_types=1);

    namespace SocioLink\ApiResourceBundle\Source;

    /**
     * Configuration résolue du bundle : tout ce qui, dans la version « commande de projet », était
     * codé en dur (espace de noms `App\`, dossier `src/`, rôle `ROLE_ADMIN`, champs système).
     *
     * Les valeurs par défaut reproduisent exactement le comportement historique : un projet qui
     * suit les conventions actuelles n'a AUCUNE configuration à écrire.
     *
     * Les espaces de noms `entity`, `dto` et `state` sont exprimés RELATIVEMENT à `rootNamespace`
     * en entrée (ex. 'Entity'), et stockés en absolu (ex. 'App\Entity').
     *
     * @internal Construit par ApiResourceBundle à partir de la configuration `sociolink_api_resource`.
     */
    final readonly class GeneratorConfig {
        /**
         * Champs exclus du DTO de CRÉATION (CreateDto).
         *
         * Auto-gérés par Doctrine/listeners, ou exposés via des endpoints dédiés (status, toggle, soft-delete).
         *
         * @var list<string>
         */
        public const array DEFAULT_SYSTEM_FIELDS = [
            'details', 'status', 'createdAt', 'updatedAt', 'deletedAt', 'erasedAt',
            'createdBy', 'updatedBy', 'deletedBy', 'erasedBy', 'itDeleted', 'itErased',
        ];

        /**
         * Champs exclus du DTO de MISE À JOUR (UpdateDto).
         *
         * Contrairement à DEFAULT_SYSTEM_FIELDS, status/itDeleted/itErased n'y figurent PAS : ils sont
         * inclus dans UpdateDto/UpdateProcessor avec une logique dédiée.
         *
         * @var list<string>
         */
        public const array DEFAULT_UPDATE_SYSTEM_FIELDS = [
            'details', 'createdAt', 'updatedAt', 'deletedAt', 'erasedAt', 'createdBy', 'updatedBy', 'deletedBy', 'erasedBy',
        ];

        /**
         * Champs booléens à logique métier spéciale (soft-delete / soft-erase).
         *
         * @var list<string>
         */
        public const array DEFAULT_BOOLEAN_SPECIAL_FIELDS = ['itDeleted', 'itErased'];

        /** Racine PSR-4 du projet (ex. 'App'), sans antislash final. */
        public string $rootNamespace;

        /** Espace de noms absolu des entités (ex. 'App\Entity'). */
        public string $entityNamespace;

        /** Espace de noms absolu des DTOs générés (ex. 'App\DTO'). */
        public string $dtoNamespace;

        /** Espace de noms absolu des Processors/Providers générés (ex. 'App\State'). */
        public string $stateNamespace;

        /** Espace de noms absolu des tests fonctionnels générés (ex. 'App\Tests\Functional'). */
        public string $testNamespace;

        /** Dossier absolu du projet, séparateurs normalisés en « / », sans « / » final. */
        public string $projectDir;

        /*
         * Paramètre $projectDir           : chemin absolu du projet (kernel.project_dir).
         * Paramètre $rootNamespace        : racine PSR-4 correspondant à $sourceDir.
         * Paramètre $sourceDir            : dossier des sources, relatif au projet.
         * Paramètres $entity/$dto/$state  : espaces de noms relatifs à $rootNamespace.
         * Paramètre $testNamespace        : espace de noms absolu des tests générés.
         * Paramètre $testDirectory        : dossier des tests générés, relatif au projet.
         * Paramètre $adminRole            : rôle requis pour lever un soft-erase (itErased).
         * Paramètre $templatesDirectory   : dossier de gabarits Twig prioritaires (surcharge), ou null.
         * Paramètre $filterExcludedFields : champs exclus des paramètres de filtrage et de tri.
         * Paramètre $sortOnToOneRelations : autorise le tri sur les relations ToOne (order[author]=asc).
         */
        /**
         * @param list<string> $systemFields
         * @param list<string> $updateSystemFields
         * @param list<string> $booleanSpecialFields
         * @param list<string> $filterExcludedFields
         */
        public function __construct(
            string         $projectDir,
            string         $rootNamespace = 'App',
            public string  $sourceDir = 'src',
            string         $entityNamespace = 'Entity',
            string         $dtoNamespace = 'DTO',
            string         $stateNamespace = 'State',
            string         $testNamespace = 'App\\Tests\\Functional',
            public string  $testDirectory = 'tests/Functional',
            public string  $adminRole = 'ROLE_ADMIN',
            public array   $systemFields = self::DEFAULT_SYSTEM_FIELDS,
            public array   $updateSystemFields = self::DEFAULT_UPDATE_SYSTEM_FIELDS,
            public array   $booleanSpecialFields = self::DEFAULT_BOOLEAN_SPECIAL_FIELDS,
            public ?string $templatesDirectory = null,
            public array   $filterExcludedFields = [],
            public bool    $sortOnToOneRelations = false,
        ) {
            $this->projectDir      = rtrim(str_replace('\\', '/', $projectDir), '/');
            $this->rootNamespace   = trim($rootNamespace, '\\');
            $this->entityNamespace = $this->rootNamespace . '\\' . trim($entityNamespace, '\\');
            $this->dtoNamespace    = $this->rootNamespace . '\\' . trim($dtoNamespace, '\\');
            $this->stateNamespace  = $this->rootNamespace . '\\' . trim($stateNamespace, '\\');
            $this->testNamespace   = trim($testNamespace, '\\');
        }
    }
