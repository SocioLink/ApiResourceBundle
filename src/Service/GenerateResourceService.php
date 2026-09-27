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
     * Description: Orchestreur de la génération de ressources API Platform.
     */

    namespace BlackSheep\Symfony\ApiResourceBundle\Service;

    use Exception;
    use Doctrine\ORM\EntityManagerInterface;
    use Symfony\Component\Filesystem\Filesystem;
    use Symfony\Component\Console\Style\SymfonyStyle;
    use BlackSheep\Symfony\ApiResourceBundle\Source\DtoBuilder;
    use BlackSheep\Symfony\ApiResourceBundle\Source\TestBuilder;
    use BlackSheep\Symfony\ApiResourceBundle\Source\FieldAnalyser;
    use BlackSheep\Symfony\ApiResourceBundle\Source\ProviderBuilder;
    use BlackSheep\Symfony\ApiResourceBundle\Source\ProcessorBuilder;
    use BlackSheep\Symfony\ApiResourceBundle\Source\GenerationOptions;
    use BlackSheep\Symfony\ApiResourceBundle\Source\GeneratorConfig;
    use BlackSheep\Symfony\ApiResourceBundle\Source\NamespaceResolver;
    use BlackSheep\Symfony\ApiResourceBundle\Source\ResourceGeneratorInterface;
    use BlackSheep\Symfony\ApiResourceBundle\Source\EntityAttributeInjector;

    /**
     * Orchestre la génération complète des artefacts API Platform pour une entité Doctrine.
     *
     * Comportement selon le mode actif :
     *  - Défaut          : DTOs + Processors + injection #[ApiResource] liés
     *  --only-resource   : injection #[ApiResource] + filtres uniquement, aucun artefact
     *  --with-provider   : mode défaut + Provider pour GET {id}
     *  --toggle-boolean  : mode défaut + ToggleDto/ToggleProcessor pour TOUS les booléens
     *  --detach-boolean  : mode défaut mais un DTO+Processor par booléen (pas de Toggle)
     *  --all             : #[ApiResource] libre + tous les artefacts générés mais non liés
     *  --dry-run / --preview : simulation sans écriture disque
     */
    final readonly class GenerateResourceService implements ResourceGeneratorInterface {
        private Filesystem  $filesystem;

        public function __construct(
            private NamespaceResolver                               $namespaceResolver,
            private FieldAnalyser                                   $fieldAnalyser,
            private DtoBuilder                                      $dtoBuilder,
            private ProcessorBuilder                                $processorBuilder,
            private ProviderBuilder                                 $providerBuilder,
            private EntityAttributeInjector                         $entityAttributeInjector,
            private TestBuilder                                     $testBuilder,
            private EntityManagerInterface                          $entityManager,
            private GeneratorConfig                                 $config,
        ) {
            $this->filesystem = new Filesystem();
        }

        /* ── Nettoyage (--force --reinit) ─── */

        /**
         * Supprime les répertoires et fichiers générés avant une régénération complète.
         *
         * @param string|null  $entityClass FQCN de l'entité ciblée, ou null pour tout supprimer
         * @param SymfonyStyle $io
         */
        public function cleanBeforeReinit(string|null $entityClass, SymfonyStyle $io): void {
            if ($entityClass === null) {
                $dtoDir   = $this->namespaceResolver->dtoRootDir();
                $stateDir = $this->namespaceResolver->stateRootDir();

                foreach ([$dtoDir, $stateDir] as $dir) {
                    if (is_dir($dir)) {
                        $this->filesystem->remove($dir);
                        $io->text(sprintf('  ✕ Supprimé : %s', $dir));
                    }
                }
            }
            else {
                $dtoDir   = $this->namespaceResolver->namespaceToDir($this->namespaceResolver->getDtoNamespace($entityClass));
                $stateDir = $this->namespaceResolver->namespaceToDir($this->namespaceResolver->getStateNamespace($entityClass));

                foreach ([$dtoDir, $stateDir] as $dir) {
                    if (is_dir($dir)) {
                        $this->filesystem->remove($dir);
                        $io->text(sprintf('  ✕ Supprimé : %s', $dir));
                    }
                }
            }
        }

        /* ── Traitement d'une entité ───────────────────────────────────────── */

        /**
         * Traite une entité Doctrine et génère tous les artefacts requis selon les options.
         *
         * @param string            $entityClass FQCN complet de l'entité
         * @param GenerationOptions $options     Options de génération actives
         * @param SymfonyStyle      $io
         *
         * @return array<string, string|array<string, int>>
         *
         * @throws \Doctrine\ORM\Mapping\MappingException
         * @throws \ReflectionException
         */
        public function processEntity(string $entityClass, GenerationOptions $options, SymfonyStyle $io): array {
            $result = ['entity' => 'error', '_stats' => ['booleanCount' => 0, 'uploadCount' => 0]];

            try {
                $metadata = $this->entityManager->getClassMetadata($entityClass);
            }
            catch (Exception) {
                return $result;
            }

            /* ── Résolution des coordonnées ──────────────────────────────────────── */
            $entityName = $this->namespaceResolver->getShortClassName($entityClass);
            $dtoNs      = $this->namespaceResolver->getDtoNamespace($entityClass);
            $stateNs    = $this->namespaceResolver->getStateNamespace($entityClass);
            $dtoDir     = $this->namespaceResolver->namespaceToDir($dtoNs);
            $stateDir   = $this->namespaceResolver->namespaceToDir($stateNs);

            /* ── Analyse des champs ──────────────────────────────────────────────── */
            $fields       = $this->fieldAnalyser->getEntityFields($metadata);
            $hasStatus    = isset($fields['status']);
            $hasUpdatedAt = isset($fields['updatedAt']);
            $uploadFields = $this->fieldAnalyser->detectUploadFields($entityClass);

            $allBooleanFields = $this->fieldAnalyser->getBooleanFields($fields);

            /* ── Sous-ressources OneToMany (--sub-resources) ──────────────────────── */
            $oneToManyFields = [];

            if ($options->subResources) {
                $oneToManyFields = array_filter(
                    $fields,
                    static fn(array $info): bool => $info['isRelation'] && ($info['isOneToMany'] ?? false),
                );
            }

            /* ── Génération conditionnelle des artefacts ─────────────────────────── */
            if ($options->generatesArtifacts()) {
                /* DTOs et Processors de base (Create + Update) */
                $result['createDto'] = $this->writeFile(
                    "{$dtoDir}/{$entityName}CreateDto.php",
                    $this->dtoBuilder->buildCreateDto($entityClass, $entityName, $dtoNs, $fields),
                    $options,
                    $io,
                );

                $result['updateDto'] = $this->writeFile(
                    "{$dtoDir}/{$entityName}UpdateDto.php",
                    $this->dtoBuilder->buildUpdateDto($entityClass, $entityName, $dtoNs, $fields, $options),
                    $options,
                    $io,
                );

                $result['createProcessor'] = $this->writeFile(
                    "{$stateDir}/{$entityName}CreateProcessor.php",
                    $this->processorBuilder->buildCreateProcessor($entityClass, $entityName, $stateNs, $dtoNs, $fields),
                    $options,
                    $io,
                );

                $result['updateProcessor'] = $this->writeFile(
                    "{$stateDir}/{$entityName}UpdateProcessor.php",
                    $this->processorBuilder->buildUpdateProcessor($entityClass, $entityName, $stateNs, $dtoNs, $fields, $options),
                    $options,
                    $io,
                );

                /* Provider GET {id} (--with-provider) */
                if ($options->withProvider) {
                    $result['provider'] = $this->writeFile(
                        "{$stateDir}/{$entityName}Provider.php",
                        $this->providerBuilder->buildGetProvider($entityClass, $entityName, $stateNs, $fields),
                        $options,
                        $io,
                    );
                }

                /* Toggle unique pour TOUS les booléens (--toggle-boolean) */
                if ($options->usesToggleEndpoint() && !empty($allBooleanFields)) {
                    $result['toggleDto'] = $this->writeFile(
                        "{$dtoDir}/{$entityName}ToggleDto.php",
                        $this->dtoBuilder->buildToggleDto($entityName, $dtoNs, $allBooleanFields),
                        $options,
                        $io,
                    );

                    $result['toggleProcessor'] = $this->writeFile(
                        "{$stateDir}/{$entityName}ToggleProcessor.php",
                        $this->processorBuilder->buildToggleProcessor($entityClass, $entityName, $stateNs, $dtoNs, $allBooleanFields),
                        $options,
                        $io,
                    );
                }

                /* DTO + Processor individuel par booléen (--detach-boolean) */
                if ($options->detachBoolean) {
                    foreach ($allBooleanFields as $fieldName => $info) {
                        $pascal = ucfirst($fieldName);

                        $result["dto{$pascal}"] = $this->writeFile(
                            "{$dtoDir}/{$entityName}{$pascal}Dto.php",
                            $this->dtoBuilder->buildSingleBooleanDto($entityName, $dtoNs, $fieldName, $pascal),
                            $options,
                            $io,
                        );

                        $result["processor{$pascal}"] = $this->writeFile(
                            "{$stateDir}/{$entityName}{$pascal}Processor.php",
                            $this->processorBuilder->buildSingleBooleanProcessor($entityClass, $entityName, $stateNs, $dtoNs, $fieldName, $pascal),
                            $options,
                            $io,
                        );
                    }
                }

                /* Uploads (VichUploader) */
                foreach ($uploadFields as $uploadField) {
                    $suffix = $uploadField['propSuffix'];

                    $result["upload{$suffix}Dto"] = $this->writeFile(
                        "{$dtoDir}/{$entityName}Upload{$suffix}Dto.php",
                        $this->dtoBuilder->buildUploadDto($entityName, $dtoNs, $uploadField),
                        $options,
                        $io,
                    );

                    $result["upload{$suffix}Processor"] = $this->writeFile(
                        "{$stateDir}/{$entityName}Upload{$suffix}Processor.php",
                        $this->processorBuilder->buildUploadProcessor($entityClass, $entityName, $stateNs, $dtoNs, $uploadField, $hasUpdatedAt),
                        $options,
                        $io,
                    );
                }

                /* Tests fonctionnels (--with-tests) */
                if ($options->withTests) {
                    /* Les tests vivent dans le dossier de tests configuré (par défaut tests/Functional/), pas dans src/. */
                    $testNs  = $this->namespaceResolver->getTestNamespace($entityClass);
                    $testDir = $this->namespaceResolver->getTestDir($entityClass);

                    $result['test'] = $this->writeFile(
                        "{$testDir}/{$entityName}ApiTest.php",
                        $this->testBuilder->buildFunctionalTest($entityClass, $entityName, $testNs, $fields, $dtoNs),
                        $options,
                        $io,
                    );
                }

                /* Sous-ressources OneToMany (--sub-resources) */
                if (!empty($oneToManyFields)) {
                    $this->generateSubResources(
                        $entityClass, $entityName, $stateNs, $dtoNs,
                        $dtoDir, $stateDir, $oneToManyFields, $options, $io, $result,
                    );
                }
            }

            /* Injection #[ApiResource] dans l'entité */
            $result['entity'] = $this->entityAttributeInjector->injectAttributesIntoEntity(
                entityClass : $entityClass, entityName: $entityName, fields: $fields,
                dtoNs       : $dtoNs, stateNs: $stateNs, options: $options, io: $io,
                hasStatus   : $hasStatus, booleanFields: [], allBooleanFields: $allBooleanFields,
                uploadFields: $uploadFields,
            );

            /* Statistiques */
            $result['_stats'] = ['booleanCount' => count($allBooleanFields), 'uploadCount' => count($uploadFields)];

            return $result;
        }

        /* ── Sous-ressources (--sub-resources) ─── */

        /**
         * Génère les sous-ressources API Platform pour les relations OneToMany.
         *
         * Pour chaque relation OneToMany (ex. Profile::getAddresses()), injecte :
         *  - Un attribut #[ApiResource] sub-resource sur l'entité ENFANT (la cible)
         *    avec uriTemplate, uriVariables (Link) et GetCollection
         *
         * @param string               $entityClass
         * @param string               $entityName
         * @param string               $stateNs
         * @param string               $dtoNs
         * @param string               $dtoDir
         * @param string               $stateDir
         * @param array<string, array> $oneToManyFields
         * @param GenerationOptions    $options
         * @param SymfonyStyle         $io
         * @param array<string, mixed> $result
         */
        private function generateSubResources(
            string            $entityClass,
            string            $entityName,
            string            $stateNs,
            string            $dtoNs,
            string            $dtoDir,
            string            $stateDir,
            array             $oneToManyFields,
            GenerationOptions $options,
            SymfonyStyle      $io,
            array             &$result,
        ): void {
            foreach ($oneToManyFields as $fieldName => $info) {
                $targetClass = $info['doctrineType'];
                $targetShort = $this->namespaceResolver->getShortClassName($targetClass);
                $mappedBy    = $info['mappedBy'] ?? lcfirst($entityName);

                /*
                 * Champs de l'entité ENFANT : la sous-ressource reprend ses paramètres de filtrage
                 * (avant la migration vers QueryParameter, elle héritait des #[ApiFilter] de classe de l'enfant).
                 * Si l'analyse échoue, la sous-ressource est générée sans paramètre plutôt que de bloquer la génération.
                 */
                try {
                    $targetFields = $this->fieldAnalyser->getEntityFields($this->entityManager->getClassMetadata($targetClass));
                }
                catch (Exception) {
                    $targetFields = [];
                }

                /* Injecte un #[ApiResource] sub-resource sur l'entité ENFANT (la cible de la OneToMany) */
                $result["subResource{$targetShort}"] = $this->entityAttributeInjector->injectSubResourceAttributes(
                    parentClass : $entityClass,
                    parentName  : $entityName,
                    targetClass : $targetClass,
                    targetName  : $targetShort,
                    fieldName   : $fieldName,
                    mappedBy    : $mappedBy,
                    options     : $options,
                    io          : $io,
                    targetFields: $targetFields,
                );
            }
        }

        /* ── Écriture de fichier ─── */

        /**
         * Écrit le contenu PHP dans le fichier au chemin indiqué.
         *
         * En mode --dry-run : retourne le chemin sans écrire.
         * En mode --preview : affiche le contenu dans la console puis retourne le chemin.
         * En mode normal : écrit via Filesystem::dumpFile().
         *
         * @param string            $path    Chemin absolu du fichier cible
         * @param string            $content Contenu PHP complet
         * @param GenerationOptions $options Options de génération (pour dry-run/preview)
         * @param SymfonyStyle      $io      Interface console (pour preview)
         *
         * @return string 'skipped' | chemin absolu du fichier (écrit ou simulé)
         */
        private function writeFile(string $path, string $content, GenerationOptions $options, SymfonyStyle $io): string {
            if (!$options->force && file_exists($path)) {
                return 'skipped';
            }

            /* Mode aperçu : afficher le code généré */
            if ($options->preview) {
                $relativePath = str_replace($this->config->projectDir . '/', '', str_replace('\\', '/', $path));
                $io->newLine();
                $io->section(sprintf('📄 %s', $relativePath));
                $io->write($content);
            }

            /* Mode lecture seule : ne pas écrire */
            if ($options->isReadOnly()) {
                return $path;
            }

            $this->filesystem->dumpFile($path, $content);

            return $path;
        }
    }
