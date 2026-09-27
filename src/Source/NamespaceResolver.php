<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Résolution des namespaces, chemins de fichiers et segments d'URI liés à une entité.
     */

    declare(strict_types=1);

    namespace SocioLink\ApiResourceBundle\Source;

    use Doctrine\Inflector\InflectorFactory;

    /**
     * Résout les namespaces PHP, les chemins de fichiers absolus et les segments d'URI
     * liés à une entité Doctrine.
     *
     * Ce service est purement déterministe (transformations de chaînes) et sans effet de bord.
     * Tout ce qui dépend de la structure du projet (espace de noms racine, dossier des sources,
     * sous-espaces DTO/State/Entity) provient de {@see GeneratorConfig}.
     *
     * @internal Réservé à l'usage interne du bundle.
     */
    final readonly class NamespaceResolver {
        /* $config fournit la racine PSR-4 du projet, le dossier des sources et les espaces de noms des artefacts générés. */
        public function __construct(private GeneratorConfig $config) {}

        /* ── Nom de classe ─── */

        /*
         * Extrait le nom court d'une classe depuis son FQCN.
         *
         * Exemple : 'App\Entity\Blog\Article' → 'Article'
         */
        public function getShortClassName(string $class): string {
            return substr($class, (int)strrpos($class, '\\') + 1); /* strrpos : DERNIER séparateur ; (int) couvre une classe sans namespace */
        }

        /*
         * Retourne le sous-chemin de namespace de l'entité sous l'espace de noms des entités.
         *
         * Exemple : 'App\Entity\Blog\Article' → 'Blog' ; 'App\Entity\Article' → '' ;
         * une classe hors de l'espace de noms des entités → ''.
         */
        public function getSubPath(string $entityClass): string {
            $prefix = $this->config->entityNamespace . '\\';

            if (!str_starts_with($entityClass, $prefix)) {
                return '';
            }

            $parts = explode('\\', substr($entityClass, strlen($prefix)));
            array_pop($parts); /* retire le nom de la classe : il ne reste que les sous-espaces */

            return implode('\\', $parts);
        }

        /* ── Namespaces PHP ─── */

        /*
         * Espace de noms des DTOs de l'entité.
         *
         * Exemple : 'App\Entity\Blog\Article' → 'App\DTO\Blog\Article'
         */
        public function getDtoNamespace(string $entityClass): string {
            return $this->artifactNamespace($this->config->dtoNamespace, $entityClass);
        }

        /*
         * Espace de noms des Processors et Providers de l'entité.
         *
         * Exemple : 'App\Entity\Blog\Article' → 'App\State\Blog\Article'
         */
        public function getStateNamespace(string $entityClass): string {
            return $this->artifactNamespace($this->config->stateNamespace, $entityClass);
        }

        /*
         * Espace de noms des tests fonctionnels de l'entité.
         *
         * Exemple : 'App\Entity\Blog\Article' → 'App\Tests\Functional\Blog\Article'
         */
        public function getTestNamespace(string $entityClass): string {
            return $this->artifactNamespace($this->config->testNamespace, $entityClass);
        }

        /* ── Chemins de fichiers ─── */

        /*
         * Convertit un espace de noms du projet en dossier absolu.
         *
         * Exemple : 'App\DTO\Article' → '/projet/src/DTO/Article' (avec sourceDir = 'src')
         */
        public function namespaceToDir(string $namespace): string {
            return $this->config->projectDir . '/' . trim($this->config->sourceDir, '/') . '/' . $this->relativePath($namespace);
        }

        /*
         * Chemin absolu du fichier source de l'entité.
         */
        public function entityFilePath(string $entityClass): string {
            return $this->namespaceToDir($entityClass) . '.php';
        }

        /*
         * Dossier racine de tous les DTOs générés (supprimé par --reinit global).
         */
        public function dtoRootDir(): string {
            return $this->namespaceToDir($this->config->dtoNamespace);
        }

        /*
         * Dossier racine de tous les Processors et Providers générés (supprimé par --reinit global).
         */
        public function stateRootDir(): string {
            return $this->namespaceToDir($this->config->stateNamespace);
        }

        /*
         * Dossier absolu des tests fonctionnels de l'entité.
         *
         * Exemple : 'App\Entity\Blog\Article' → '/projet/tests/Functional/Blog/Article'
         */
        public function getTestDir(string $entityClass): string {
            $relative = substr($this->getTestNamespace($entityClass), strlen($this->config->testNamespace) + 1);

            return $this->config->projectDir . '/' . trim($this->config->testDirectory, '/') . '/' . str_replace('\\', '/', $relative);
        }

        /* ── Segments d'URI API Platform ─── */

        /*
         * Préfixe de route de l'entité (routePrefix), déduit de son sous-namespace.
         *
         * Exemple : 'App\Entity\Blog\Article' → '/blog' ; 'App\Entity\Article' → ''
         */
        public function getRoutePrefix(string $entityClass): string {
            $sub = $this->getSubPath($entityClass);

            return $sub !== '' ? '/' . strtolower(str_replace('\\', '/', $sub)) : '';
        }

        /*
         * Segment d'URI pluralisé en snake_case pour un nom d'entité.
         *
         * Exemple : 'BlogPost' → 'blog_posts'
         */
        public function toApiPlatformUriBase(string $entityName): string {
            $snake = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $entityName));

            return InflectorFactory::create()->build()->pluralize($snake);
        }

        /*
         * Segment d'URI de l'endpoint de basculement individuel d'un booléen (--detach-boolean).
         *
         * Exemple : 'itDeleted' → 'toggle-it-deleted'
         */
        public function toggleUriSegment(string $fieldName): string {
            return 'toggle-' . strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', str_replace('_', '-', $fieldName)));
        }

        /* ── Utilitaires ─── */

        /*
         * Compose l'espace de noms d'un artefact : {racine}\{sous-espace}\{NomEntité}.
         */
        private function artifactNamespace(string $base, string $entityClass): string {
            $sub  = $this->getSubPath($entityClass);
            $name = $this->getShortClassName($entityClass);

            return $sub !== '' ? "{$base}\\{$sub}\\{$name}" : "{$base}\\{$name}";
        }

        /*
         * Retire l'espace de noms racine PSR-4 d'un FQCN et le convertit en chemin relatif.
         */
        private function relativePath(string $namespace): string {
            $prefix = $this->config->rootNamespace . '\\';

            if (str_starts_with($namespace, $prefix)) {
                $namespace = substr($namespace, strlen($prefix));
            }

            return str_replace('\\', '/', $namespace);
        }
    }
