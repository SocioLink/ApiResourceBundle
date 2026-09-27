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
	 * Creation Date: 22/08/2026 14:12
	 *
	 * Description: Orchestrateur de la génération des artefacts API Platform d'une entité Doctrine (implémentation de
	 *              ResourceGeneratorInterface).
	 *              Pour chaque entité : chargement des métadonnées, analyse des champs, puis selon le mode actif génération
	 *              des DTOs et Processors Create/Update, du Provider GET {id}, du Toggle global ou des endpoints par
	 *              booléen, des endpoints d'upload VichUploader, des tests fonctionnels et des sous-ressources OneToMany,
	 *              et enfin injection de #[ApiResource] dans le fichier de l'entité.
	 *              Les fichiers existants ne sont écrasés qu'avec --force ; --dry-run et --preview n'écrivent rien
	 *              (--preview affiche le code). Une erreur sur une entité est affichée et comptée sans interrompre les
	 *              autres. cleanBeforeReinit() supprime uniquement les dossiers DTO et State des entités traitées.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Service;

	use Throwable;
	use Doctrine\ORM\Mapping\ClassMetadata;
	use Doctrine\ORM\EntityManagerInterface;
	use Symfony\Component\Filesystem\Filesystem;
	use Symfony\Component\Console\Style\SymfonyStyle;
	use SocioLink\ApiResourceBundle\Source\DtoBuilder;
	use SocioLink\ApiResourceBundle\Source\TestBuilder;
	use SocioLink\ApiResourceBundle\Source\FieldAnalyser;
	use SocioLink\ApiResourceBundle\Source\ProviderBuilder;
	use SocioLink\ApiResourceBundle\Source\GeneratorConfig;
	use SocioLink\ApiResourceBundle\Source\ProcessorBuilder;
	use SocioLink\ApiResourceBundle\Source\GenerationOptions;
	use SocioLink\ApiResourceBundle\Source\NamespaceResolver;
	use SocioLink\ApiResourceBundle\Source\EntityAttributeInjector;
	use SocioLink\ApiResourceBundle\Source\ResourceGeneratorInterface;

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
		private Filesystem $filesystem;

		public function __construct(
			private NamespaceResolver       $namespaceResolver,
			private FieldAnalyser           $fieldAnalyser,
			private DtoBuilder              $dtoBuilder,
			private ProcessorBuilder        $processorBuilder,
			private ProviderBuilder         $providerBuilder,
			private EntityAttributeInjector $entityAttributeInjector,
			private TestBuilder             $testBuilder,
			private EntityManagerInterface  $entityManager,
			private GeneratorConfig         $config,
		) {
			$this->filesystem = new Filesystem();
		}

		/* ── Nettoyage (--force --reinit) ─── */

		/**
		 * Supprime les dossiers DTO et State de chaque entité donnée (jamais les dossiers racines :
		 * les artefacts des autres entités et le code écrit à côté sont préservés).
		 *
		 * @param list<string> $entityClasses FQCN des entités traitées
		 * @param SymfonyStyle $io
		 */
		public function cleanBeforeReinit(array $entityClasses, SymfonyStyle $io): void {
			foreach ($entityClasses as $entityClass) {
				$dirs = [
					$this->namespaceResolver->namespaceToDir($this->namespaceResolver->getDtoNamespace($entityClass)),
					$this->namespaceResolver->namespaceToDir($this->namespaceResolver->getStateNamespace($entityClass)),
				];

				foreach ($dirs as $dir) {
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
		 * Une erreur sur une entité est affichée et comptée, sans interrompre le traitement des autres.
		 *
		 * @param string            $entityClass FQCN complet de l'entité
		 * @param GenerationOptions $options     Options de génération actives
		 * @param SymfonyStyle      $io
		 *
		 * @return array<string, string|array{booleanCount: int, uploadCount: int}>
		 */
		public function processEntity(string $entityClass, GenerationOptions $options, SymfonyStyle $io): array {
			try {
				$metadata = $this->entityManager->getClassMetadata($entityClass);
			}
			catch (Throwable $e) {
				$io->error(sprintf('%s : métadonnées Doctrine introuvables (%s).', $entityClass, $e->getMessage()));

				return ['entity' => 'error', '_stats' => ['booleanCount' => 0, 'uploadCount' => 0]];
			}

			try {
				return $this->generate($entityClass, $metadata, $options, $io);
			}
			catch (Throwable $e) {
				$io->error(sprintf('%s : échec de la génération (%s).', $entityClass, $e->getMessage()));

				return ['entity' => 'error', '_stats' => ['booleanCount' => 0, 'uploadCount' => 0]];
			}
		}

		/**
		 * Génère les artefacts d'une entité dont les métadonnées sont chargées.
		 *
		 * @param ClassMetadata<object> $metadata
		 *
		 * @return array<string, string|array{booleanCount: int, uploadCount: int}>
		 */
		private function generate(string $entityClass, ClassMetadata $metadata, GenerationOptions $options, SymfonyStyle $io): array {
			$result = [];

			/* ── Résolution des coordonnées ──────────────────────────────────────── */
			$entityName = $this->namespaceResolver->getShortClassName($entityClass);
			$dtoNs      = $this->namespaceResolver->getDtoNamespace($entityClass);
			$stateNs    = $this->namespaceResolver->getStateNamespace($entityClass);
			$dtoDir     = $this->namespaceResolver->namespaceToDir($dtoNs);
			$stateDir   = $this->namespaceResolver->namespaceToDir($stateNs);

			/* ── Analyse des champs ──────────────────────────────────────────────── */
			$fields       = $this->fieldAnalyser->getEntityFields($metadata);
			$hasUpdatedAt = isset($fields['updatedAt']);
			$uploadFields = $this->fieldAnalyser->detectUploadFields($entityClass);

			$allBooleanFields = $this->fieldAnalyser->getBooleanFields($fields);

			/* ── Sous-ressources OneToMany (--sub-resources) ──────────────────────── */
			$oneToManyFields = [];

			if ($options->subResources) {
				$oneToManyFields = array_filter(
					$fields,
					static fn(array $info): bool => $info['isRelation'] && $info['isOneToMany'],
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
					$this->processorBuilder->buildCreateProcessor($entityClass, $entityName, $stateNs, $dtoNs),
					$options,
					$io,
				);

				$result['updateProcessor'] = $this->writeFile(
					"{$stateDir}/{$entityName}UpdateProcessor.php",
					$this->processorBuilder->buildUpdateProcessor($entityClass, $entityName, $stateNs, $dtoNs),
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
						$this->testBuilder->buildFunctionalTest($entityClass, $entityName, $testNs, $fields),
						$options,
						$io,
					);
				}

				/* Sous-ressources OneToMany (--sub-resources) */
				if (!empty($oneToManyFields)) {
					$this->generateSubResources($entityClass, $entityName, $oneToManyFields, $options, $io, $result);
				}
			}

			/* Injection #[ApiResource] dans l'entité */
			$result['entity'] = $this->entityAttributeInjector->injectAttributesIntoEntity(
				entityClass     : $entityClass, entityName: $entityName, fields: $fields,
				dtoNs           : $dtoNs, stateNs: $stateNs, options: $options, io: $io,
				allBooleanFields: $allBooleanFields, uploadFields: $uploadFields,
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
		 * @param string                                   $entityClass
		 * @param string                                   $entityName
		 * @param array<string, array<string, mixed>>      $oneToManyFields
		 * @param GenerationOptions                        $options
		 * @param SymfonyStyle                             $io
		 * @param array<string, string|array<string, int>> $result
		 */
		private function generateSubResources(
			string            $entityClass,
			string            $entityName,
			array             $oneToManyFields,
			GenerationOptions $options,
			SymfonyStyle      $io,
			array             &$result,
		): void {
			foreach ($oneToManyFields as $fieldName => $info) {
				$targetClass = (string)$info['doctrineType'];
				$targetShort = $this->namespaceResolver->getShortClassName($targetClass);
				$mappedBy    = (string)($info['mappedBy'] ?? '') !== '' ? (string)$info['mappedBy'] : lcfirst($entityName);

				/*
				 * Champs de l'entité ENFANT : la sous-ressource reprend ses paramètres de filtrage
				 * (avant la migration vers QueryParameter, elle héritait des #[ApiFilter] de classe de l'enfant).
				 * Si l'analyse échoue, la sous-ressource est générée sans paramètre plutôt que de bloquer la génération.
				 */
				try {
					$targetFields = $this->fieldAnalyser->getEntityFields($this->entityManager->getClassMetadata($targetClass));
				}
				catch (Throwable) {
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
