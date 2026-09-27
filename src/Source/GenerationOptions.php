<?php


    /*
     * Copyright (c) 2026.
     * Date: 27/08/2026 12:50
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description:
     */

    declare(strict_types=1);

    /*
     * Copyright (c) 2026.
     * Date: 22/08/2026 14:12
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Value Object pour les options de la commande generate:resource.
     */

    namespace BlackSheep\Symfony\ApiResourceBundle\Source;

    /**
     * Objet de valeur immuable transportant l'ensemble des options de génération
     * choisies par l'utilisateur via les flags de la commande generate:resource.
     *
     * Les options mutuellement exclusives (validées par GenerationOptionsFactory) :
     * une seule parmi onlyResource / withProvider / toggleBoolean / detachBoolean / all peut être active.
     *
     * Les options indépendantes (dryRun, preview, interactive, withTests, subResources, graphqlFilters, withMercure, publicMercure)
     * peuvent être combinées avec n'importe quel mode.
     *
     * @see \BlackSheep\Symfony\ApiResourceBundle\Console\GenerationOptionsFactory Validation de l'exclusivité des flags
     */
    final readonly class GenerationOptions {
        /**
         * @param bool $force          --force / -f : écrase les fichiers existants et réinjecte les attributs
         * @param bool $reinit         --reinit : utilisé UNIQUEMENT avec --force ; supprime les répertoires avant
         *                             régénération (DTO/, State/, AppService si pas d'entité ciblée)
         * @param bool $onlyResource   --only-resource : injecte #[ApiResource] + filtres uniquement.
         *                             Aucun DTO, Processor ou Provider n'est créé ni référencé
         * @param bool $withProvider   --with-provider : génère un Provider pour l'opération GET {id}.
         *                             GET Collection reste géré nativement par API Platform
         * @param bool $toggleBoolean  --toggle-boolean : crée un ToggleDto + ToggleProcessor pour TOUS les booléens
         *                             (y compris itDeleted et itErased). Les booléens sont retirés de UpdateDto/UpdateProcessor.
         * @param bool $detachBoolean  --detach-boolean : crée un DTO + Processor PATCH individuel
         *                             pour chaque attribut booléen de l'entité.
         *                             Sans ce flag, les booléens sont exclus des DTOs/Processors
         * @param bool $all            --all : injecte un #[ApiResource] libre (sans input/processor/provider)
         *                             ET génère tous les artefacts (DTOs, Processors, Providers) sans les lier
         * @param bool $dryRun         --dry-run : simule la génération sans écrire aucun fichier.
         *                             Affiche les fichiers qui seraient créés/modifiés/supprimés
         * @param bool $preview        --preview : affiche le code généré dans la console sans écrire.
         *                             Imply --dry-run. Utile pour inspecter le résultat avant de l'appliquer
         * @param bool $interactive    --interactive / -i : mode interactif — affiche la liste des entités
         *                             trouvées et demande lesquelles traiter
         * @param bool $withTests      --with-tests : génère un fichier de test fonctionnel ApiTestCase
         *                             pour chaque endpoint créé (POST, PATCH, GET)
         * @param bool $subResources   --sub-resources : génère des sous-ressources API Platform pour les
         *                             relations OneToMany (ex. GET /api/articles/{id}/comments)
         * @param bool $withMercure    --with-mercure : injecte la directive mercure dans #[ApiResource].
         *                             Privée par défaut (mercure: ['private' => true]). Sans ce flag, aucune
         *                             directive mercure n'est injectée
         * @param bool $publicMercure  --public : avec --with-mercure, injecte mercure: true (mises à jour
         *                             publiées publiquement). Sans effet, et refusé par la commande, sans --with-mercure
         * @param bool $graphqlFilters --graphql-filters : reporte les paramètres de filtrage (QueryParameter)
         *                             sur l'opération GraphQL QueryCollection. Sans ce flag, la collection
         *                             GraphQL n'est plus filtrable (les paramètres ne sont posés que sur GetCollection)
         */
        public function __construct(
            public bool $force = false,
            public bool $reinit = false,
            public bool $onlyResource = false,
            public bool $withProvider = false,
            public bool $toggleBoolean = false,
            public bool $detachBoolean = false,
            public bool $all = false,
            public bool $dryRun = false,
            public bool $preview = false,
            public bool $interactive = false,
            public bool $withTests = false,
            public bool $subResources = false,
            public bool $graphqlFilters = false,
            public bool $withMercure = false,
            public bool $publicMercure = false,
        ) {}

        /**
         * Fabrique nommée pour construire depuis les valeurs brutes des options CLI.
         *
         * @param bool|null $force
         * @param bool|null $reinit
         * @param bool|null $onlyResource
         * @param bool|null $withProvider
         * @param bool|null $toggleBoolean
         * @param bool|null $detachBoolean
         * @param bool|null $all
         * @param bool|null $dryRun
         * @param bool|null $preview
         * @param bool|null $interactive
         * @param bool|null $withTests
         * @param bool|null $subResources
         * @param bool|null $graphqlFilters
         * @param bool|null $withMercure
         * @param bool|null $publicMercure
         */
        public static function fromFlags(
            bool|null $force = null,
            bool|null $reinit = null,
            bool|null $onlyResource = null,
            bool|null $withProvider = null,
            bool|null $toggleBoolean = null,
            bool|null $detachBoolean = null,
            bool|null $all = null,
            bool|null $dryRun = null,
            bool|null $preview = null,
            bool|null $interactive = null,
            bool|null $withTests = null,
            bool|null $subResources = null,
            bool|null $graphqlFilters = null,
            bool|null $withMercure = null,
            bool|null $publicMercure = null,
        ): self {
            $detachBoolean ??= false;
            $toggleBoolean ??= false;
            $withProvider  ??= false;
            $onlyResource  ??= false;
            $reinit        ??= false;
            $force         ??= false;
            $all           ??= false;
            $dryRun        ??= false;
            $preview       ??= false;
            $interactive   ??= false;
            $withTests     ??= false;
            $subResources  ??= false;
            $graphqlFilters ??= false;
            $withMercure    ??= false;
            $publicMercure  ??= false;

            /* --preview implique --dry-run */
            if ($preview) {
                $dryRun = true;
            }

            return new self(
                force        : $force, reinit: $reinit, onlyResource: $onlyResource, withProvider: $withProvider,
                toggleBoolean: $toggleBoolean, detachBoolean: $detachBoolean, all: $all,
                dryRun       : $dryRun, preview: $preview, interactive: $interactive,
                withTests    : $withTests, subResources: $subResources, graphqlFilters: $graphqlFilters,
                withMercure  : $withMercure, publicMercure: $publicMercure,
            );
        }

        /**
         * Indique si le mode actif génère des DTOs et Processors.
         *
         * Retourne false uniquement pour le mode --only-resource (aucun artefact généré).
         */
        public function generatesArtifacts(): bool {
            return !$this->onlyResource;
        }

        /**
         * Indique si les artefacts générés doivent être référencés dans #[ApiResource].
         *
         * Retourne false pour les modes --only-resource et --all (artefacts "libres").
         */
        public function linksArtifactsToResource(): bool {
            return !$this->onlyResource && !$this->all;
        }

        /**
         * Indique si les booléens sont gérés par un Toggle unique.
         *
         * true  → ToggleDto + ToggleProcessor (mode --toggle-boolean)
         * false → les booléens sont exclus des DTOs/Processors (mode par défaut)
         */
        public function usesToggleEndpoint(): bool {
            return $this->toggleBoolean;
        }

        /**
         * Indique si itDeleted et itErased doivent être inclus dans l'UpdateDto/UpdateProcessor.
         *
         * true  → mode par défaut (pas de --toggle-boolean, pas de --detach-boolean)
         * false → --toggle-boolean ou --detach-boolean actif
         */
        public function includesSoftDeleteInUpdate(): bool {
            return !$this->toggleBoolean && !$this->detachBoolean;
        }

        /**
         * Indique si les booléens standards (hors itDeleted/itErased) doivent être
         * exclus des DTOs/Processors.
         *
         * true  → mode par défaut ou --toggle-boolean (booléens gérés ailleurs)
         * false → --detach-boolean (chaque booléen a son propre DTO/Processor)
         */
        public function excludesStandardBooleans(): bool {
            return !$this->detachBoolean;
        }

        /**
         * Indique si aucun fichier ne doit être écrit sur le disque.
         *
         * --dry-run et --preview impliquent tous les deux cette vérification.
         */
        public function isReadOnly(): bool {
            return $this->dryRun || $this->preview;
        }
    }
