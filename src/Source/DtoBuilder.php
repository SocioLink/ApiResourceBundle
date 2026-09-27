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
	 * Description: Générateur du code source des Data Transfer Objects (DTOs) utilisés en entrée des opérations API
	 *              Platform.
	 *              CreateDto (POST) : champs non système, nullabilité réelle de l'entité. UpdateDto (PATCH partiel) :
	 *              propriétés nullables où null signifie « non envoyé » ; NotBlank y accepte null et NotNull en est retiré.
	 *              ToggleDto (--toggle-boolean) : tous les booléens. DTO par booléen (--detach-boolean) et DTO d'upload
	 *              VichUploader.
	 *              Les contraintes #[Assert\*] déclarées sur l'entité sont recopiées attribut par attribut, avec leurs
	 *              dépendances : self:: est réécrit en NomEntité::, et les classes et alias référencés (ex. use … as
	 *              AssertPhone) sont importés. Les associations ToMany ne sont jamais exposées, et les imports
	 *              DateTimeImmutable, Uuid et Assert ne sont ajoutés que s'ils sont utilisés.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Source;

	use Twig\Environment;
	use Symfony\Component\DependencyInjection\Attribute\Autowire;

	/**
	 * Génère le code source PHP des Data Transfer Objects (DTOs) pour les opérations API Platform.
	 *
	 * Règles de génération selon les options :
	 *
	 * Mode par défaut (sans option booléenne) :
	 *  - CreateDto  : champs réguliers non-système, booléens compris
	 *  - UpdateDto  : champs réguliers + status + tous les booléens non-système (itDeleted/itErased compris)
	 *  - Pas de ToggleDto
	 *
	 * Mode --toggle-boolean :
	 *  - CreateDto  : champs réguliers non-système, booléens compris
	 *  - UpdateDto  : champs réguliers + status (sans booléens)
	 *  - ToggleDto  : TOUS les booléens (y compris itDeleted et itErased)
	 *
	 * Mode --detach-boolean :
	 *  - CreateDto  : champs réguliers non-système, booléens compris
	 *  - UpdateDto  : champs réguliers + status (sans booléens)
	 *  - {Field}Dto : un DTO individuel par champ booléen (itDeleted/itErased inclus)
	 *
	 * Champs toujours exclus des DTOs Create :   {@see GeneratorConfig::$systemFields}
	 * Champs toujours exclus des DTOs Update :   {@see GeneratorConfig::$updateSystemFields}
	 *
	 * @internal Réservé à l'usage interne de la commande generate:resource.
	 */
	final class DtoBuilder {
		/**
		 * @param NamespaceResolver $namespaceResolver Résolution des noms courts de classes
		 * @param FieldAnalyser     $fieldAnalyser     Conversion de types et génération de contraintes
		 * @param GeneratorConfig   $config            Champs système exclus des DTOs
		 * @param Environment       $twig              Moteur de gabarits Twig dédié du bundle
		 */
		public function __construct(
			private readonly NamespaceResolver                                               $namespaceResolver,
			private readonly FieldAnalyser                                                   $fieldAnalyser,
			private readonly GeneratorConfig                                                 $config,
			#[Autowire(service: 'sociolink_api_resource.twig')] private readonly Environment $twig,
		) {}

		/* ── DTO de création (POST) ─── */

		/**
		 * Génère le code source PHP d'un DTO de création pour une entité donnée.
		 *
		 * Ce DTO est construit en répliquant les contraintes #[Assert\*] existantes,
		 * résolues depuis la source de l'entité. Les propriétés du DTO respectent
		 * également les types et contraintes de nullabilité des champs définis en base.
		 *
		 * Les associations ToMany (OneToMany/ManyToMany) sont exclues du DTO, car leur
		 * manipulation est supposée passer par des endpoints dédiés plutôt que par un
		 * simple payload de création.
		 *
		 * Les imports nécessaires (entités, assertions, DateTimeImmutable, etc.) sont
		 * générés dynamiquement en fonction des champs et des contraintes rencontrées.
		 *
		 * Le gabarit rendu est basé sur un fichier Twig (`create_dto.php.twig`),
		 * dans lequel les blocs `namespace`, `uses_block`, et `props_block` sont injectés.
		 *
		 * @param string                                                                                                                              $entityClass Nom complet (FQCN) de la classe source de l'entité
		 * @param string                                                                                                                              $entityName  Nom court de l'entité (ex. 'User', 'Schoolyear')
		 * @param string                                                                                                                              $namespace   Namespace du DTO généré (ex. 'App\DTO')
		 * @param array<string, array{doctrineType: string, nullable: bool, isRelation: bool, isToMany: bool, isOneToMany?: bool, length?: int|null}> $fields
		 *
		 * @return string Code source PHP du DTO de création au format string
		 *
		 * @throws \Twig\Error\LoaderError
		 * @throws \Twig\Error\RuntimeError
		 * @throws \Twig\Error\SyntaxError
		 */
		public function buildCreateDto(string $entityClass, string $entityName, string $namespace, array $fields): string {
			$uses              = [];
			$props             = [];
			$needsAssert       = false;
			$needsDt           = false;
			$needsUuid         = false;
			$needsEntityImport = false;

			/*
			 * $assertMap et $useMap proviennent du parsing AST du fichier source de
			 * l'ENTITÉ elle-même (FieldAnalyser::extractAssertAttributes/extractUseStatements) :
			 * on récupère ainsi les contraintes #[Assert\*] déjà posées sur l'entité pour
			 * les reproduire fidèlement sur le DTO, plutôt que de les redéduire bêtement
			 * du seul type Doctrine (ce qui perdrait des règles métier fines déjà écrites
			 * à la main par le développeur sur l'entité).
			 */
			$assertMap = $this->fieldAnalyser->extractAssertAttributes($entityClass);
			$useMap    = $this->fieldAnalyser->extractUseStatements($entityClass);

			foreach ($fields as $fieldName => $info) {
				/* 'id' est généré par Doctrine/UUID listener ; SYSTEM_FIELDS regroupe tous les champs auto-gérés (timestamps, audit, soft-delete) — aucun des deux n'a sa place dans un payload de création envoyé par le client. */
				if ($fieldName === 'id' || in_array($fieldName, $this->config->systemFields, true)) {
					continue;
				}

				if ($info['isRelation']) {
					/*
					 * Les associations ToMany (OneToMany/ManyToMany) ne sont jamais exposées
					 * en propriété directe d'un DTO de création : la création d'une collection
					 * liée se fait via des endpoints dédiés, pas via un tableau d'IDs/objets
					 * imbriqués dans le payload POST.
					 */
					if ($info['isToMany']) {
						continue;
					}

					$target   = $this->namespaceResolver->getShortClassName($info['doctrineType']);
					$uses[]   = 'use ' . $info['doctrineType'] . ';';
					$typeHint = $info['nullable'] ? "{$target}|null" : $target;
					$default  = $info['nullable'] ? ' = null' : '';

					$extracted = $assertMap[$fieldName] ?? [];
					$attrBlock = '';

					if (!empty($extracted)) {
						$needsAssert = true;
						[$attrBlock, $needsEntityImport, $extraUses] = $this->buildExtractedConstraintBlock($extracted, $entityName, $needsEntityImport, $useMap);
						array_push($uses, ...$extraUses);
						$attrBlock .= "\n";
					}

					$props[] = "{$attrBlock}    public {$typeHint} \${$fieldName}{$default};\n";
					continue;
				}

				$phpType = $this->fieldAnalyser->toPhpType($info['doctrineType']);

				$needsDt   = $needsDt || $phpType === 'DateTimeImmutable';
				$needsUuid = $needsUuid || $phpType === 'Uuid';
				array_push($uses, ...$this->customTypeUses($info['doctrineType']));

				$extracted = $assertMap[$fieldName] ?? [];
				$attrBlock = '';

				if (!empty($extracted)) {
					$needsAssert = true;
					[$attrBlock, $needsEntityImport, $extraUses] = $this->buildExtractedConstraintBlock($extracted, $entityName, $needsEntityImport, $useMap);
					array_push($uses, ...$extraUses);
					$attrBlock .= "\n";
				}

				/*
				 * Contrairement à l'UpdateDto (toujours nullable car PATCH partiel), le
				 * CreateDto respecte la nullabilité RÉELLE du champ côté entité : un champ
				 * obligatoire en base doit être obligatoire dès la création (POST).
				 */
				$typeHint = $info['nullable'] ? "{$phpType}|null" : $phpType;
				$default  = $info['nullable'] ? ' = null' : '';

				$props[] = ($attrBlock) . "    public {$typeHint} \${$fieldName}{$default};\n";
			}

			if ($needsEntityImport) {
				$uses[] = 'use ' . $entityClass . ';';
			}

			$usesBlock  = $this->buildUsesBlock($uses, $needsDt, $needsAssert, $needsUuid);
			$propsBlock = rtrim(implode("\n", $props));

			return $this->twig->render('create_dto.php.twig', [
				'namespace'   => $namespace,
				'uses_block'  => $usesBlock,
				'entity_name' => $entityName,
				'props_block' => $propsBlock,
			]);
		}

		/* ── DTO de mise à jour partielle (PATCH) ─── */

		/**
		 * Génère le DTO de mise à jour partielle — opération PATCH.
		 *
		 * Toutes les propriétés sont nullable avec valeur par défaut null
		 * (null = champ non envoyé = valeur conservée).
		 *
		 * Inclut toujours : champs réguliers non-système + status (si présent).
		 * Inclut en mode par défaut (sans --toggle-boolean et sans --detach-boolean) :
		 *   TOUS les booléens (y compris itDeleted et itErased)
		 * En mode --toggle-boolean ou --detach-boolean :
		 *   Les booléens sont exclus (gérés par Toggle ou per-boolean)
		 *
		 * @param string                                                                                                         $entityName Nom court de l'entité
		 * @param string                                                                                                         $namespace  Namespace PHP du DTO
		 * @param array<string, array{doctrineType: string, nullable: bool, isRelation: bool, isToMany: bool, length: int|null}> $fields
		 * @param GenerationOptions                                                                                              $options    Options de génération actives
		 *
		 * @return string Code source PHP complet du fichier {$entityName}UpdateDto.php
		 * @throws \Twig\Error\LoaderError
		 * @throws \Twig\Error\RuntimeError
		 * @throws \Twig\Error\SyntaxError
		 */
		public function buildUpdateDto(string $entityClass, string $entityName, string $namespace, array $fields, GenerationOptions $options): string {
			$uses              = [];
			$props             = [];
			$needsAssert       = false;
			$needsDt           = false;
			$needsUuid         = false;
			$needsEntityImport = false;

			$assertMap = $this->fieldAnalyser->extractAssertAttributes($entityClass);
			$useMap    = $this->fieldAnalyser->extractUseStatements($entityClass);

			/*
			 * 'status' est traité À PART après la boucle principale (cf. plus bas) car il
			 * a une règle propre : toujours présent dans l'UpdateDto quel que soit le mode,
			 * sans passer par la logique générique de contraintes héritées de l'entité.
			 * On le retire donc explicitement de la boucle pour éviter un double traitement.
			 */
			/* Champs spéciaux à sauter dans la boucle principale (traités après) */
			$skipInLoop = ['status'];

			foreach ($fields as $fieldName => $info) {
				if (
					$fieldName === 'id'
					|| in_array($fieldName, $this->config->updateSystemFields, true)
					|| in_array($fieldName, $skipInLoop, true)
				) {
					continue;
				}

				/* Gestion des booléens selon l'option */
				if ($info['doctrineType'] === 'boolean') {
					/*
					 * includesSoftDeleteInUpdate() === false signifie qu'un mode "booléen
					 * séparé" est actif (--toggle-boolean ou --detach-boolean) : TOUS les
					 * booléens (pas seulement itDeleted/itErased) doivent alors être absents
					 * de l'UpdateDto, puisqu'ils sont pris en charge par un endpoint Toggle
					 * dédié ou par un DTO/Processor individuel.
					 */
					/* En mode --toggle-boolean ou --detach-boolean, les booléens sont exclus de l'UpdateDto */
					if (!$options->includesSoftDeleteInUpdate()) {
						continue;
					}
					/* En mode par défaut, on inclut TOUS les booléens (y compris itDeleted et itErased) */
				}

				if ($info['isRelation']) {
					/* Les associations ToMany ne sont jamais exposées dans un DTO (endpoints dédiés), comme pour le CreateDto. */
					if ($info['isToMany']) {
						continue;
					}

					$target    = $this->namespaceResolver->getShortClassName($info['doctrineType']);
					$uses[]    = 'use ' . $info['doctrineType'] . ';';
					$attrBlock = '';

					$extracted = $this->relaxForPartialUpdate($assertMap[$fieldName] ?? []);
					if (!empty($extracted)) {
						$needsAssert = true;
						[$attrBlock, $needsEntityImport, $extraUses] = $this->buildExtractedConstraintBlock($extracted, $entityName, $needsEntityImport, $useMap);
						array_push($uses, ...$extraUses);
						$attrBlock .= "\n";
					}

					/*
					 * Toutes les propriétés de l'UpdateDto sont nullable avec défaut null,
					 * QUELLE QUE SOIT la nullabilité réelle du champ côté entité : c'est le
					 * principe du PATCH partiel — "null = non envoyé = valeur conservée".
					 * Envoyer explicitement null pour effacer une valeur n'est pas supporté
					 * par ce design (limitation connue et acceptée du PATCH "simple").
					 */
					$props[] = ($attrBlock) . "    public {$target}|null \${$fieldName} = null;\n";
					continue;
				}

				$phpType = $this->fieldAnalyser->toPhpType($info['doctrineType']);

				$needsDt   = $needsDt || $phpType === 'DateTimeImmutable';
				$needsUuid = $needsUuid || $phpType === 'Uuid';
				array_push($uses, ...$this->customTypeUses($info['doctrineType']));

				$extracted = $this->relaxForPartialUpdate($assertMap[$fieldName] ?? []);
				$attrBlock = '';
				if (!empty($extracted)) {
					$needsAssert = true;
					[$attrBlock, $needsEntityImport, $extraUses] = $this->buildExtractedConstraintBlock($extracted, $entityName, $needsEntityImport, $useMap);
					array_push($uses, ...$extraUses);
					$attrBlock .= "\n";
				}

				$props[] = ($attrBlock) . "    public {$phpType}|null \${$fieldName} = null;\n";
			}

			/* ── Status : toujours dans UpdateDto si le champ existe ─────────────── */
			if (isset($fields['status'])) {
				$statusType = $this->fieldAnalyser->toPhpType($fields['status']['doctrineType']);
				$needsDt    = $needsDt || $statusType === 'DateTimeImmutable';
				$needsUuid  = $needsUuid || $statusType === 'Uuid';
				array_push($uses, ...$this->customTypeUses($fields['status']['doctrineType']));
				$props[] = "    public {$statusType}|null \$status = null;\n";
			}

			if ($needsEntityImport) {
				$uses[] = 'use ' . $entityClass . ';';
			}

			$usesBlock  = $this->buildUsesBlock($uses, $needsDt, $needsAssert, $needsUuid);
			$propsBlock = rtrim(implode("\n", $props));

			$softNote = $options->includesSoftDeleteInUpdate()
				? ' Tous les booléens (y compris itDeleted et itErased) sont inclus.'
				: '';

			return $this->twig->render('update_dto.php.twig', [
				'namespace'   => $namespace,
				'uses_block'  => $usesBlock,
				'entity_name' => $entityName,
				'soft_note'   => $softNote,
				'props_block' => $propsBlock,
			]);
		}

		/* ── DTO Toggle (tous les booléens — mode --toggle-boolean) ─── */

		/**
		 * Génère le DTO pour basculer TOUS les booléens via un endpoint PATCH unique.
		 *
		 * Inclut itDeleted et itErased (si présents) ainsi que les booléens standards.
		 *
		 * @param string                                     $entityName    Nom court de l'entité
		 * @param string                                     $namespace     Namespace PHP du DTO
		 * @param array<string, array{doctrineType: string}> $booleanFields TOUS les booléens (standards + spéciaux)
		 *
		 * @return string Code source PHP complet du fichier {$entityName}ToggleDto.php
		 * @throws \Twig\Error\LoaderError
		 * @throws \Twig\Error\RuntimeError
		 * @throws \Twig\Error\SyntaxError
		 */
		public function buildToggleDto(string $entityName, string $namespace, array $booleanFields): string {
			/*
			 * Toutes les propriétés sont bool|null = null : null signifie "ne pas toucher
			 * à ce booléen", tandis que true/false bascule explicitement sa valeur — c'est
			 * le même principe de "PATCH partiel" que l'UpdateDto, mais appliqué exclusivement
			 * aux booléens (ce qui permet de basculer plusieurs flags en un seul appel).
			 */
			$props      = array_map(static fn(string $f): string => "    public bool|null \${$f} = null;", array_keys($booleanFields));
			$propsBlock = implode("\n\n", $props);

			return $this->twig->render('toggle_dto.php.twig', [
				'namespace'   => $namespace,
				'entity_name' => $entityName,
				'uri_name'    => $this->namespaceResolver->toApiPlatformUriBase($entityName),
				'props_block' => $propsBlock,
			]);
		}

		/* ── DTO individuel par booléen (--detach-boolean) ─── */

		/**
		 * Génère un DTO pour un unique champ booléen — mode --detach-boolean.
		 *
		 * Chaque booléen (y compris itDeleted, itErased) obtient son propre DTO.
		 *
		 * @param string $entityName Nom court de l'entité
		 * @param string $namespace  Namespace PHP du DTO
		 * @param string $fieldName  Nom du champ (ex. 'isActive', 'itDeleted')
		 * @param string $pascalName PascalCase du champ (ex. 'IsActive', 'ItDeleted')
		 *
		 * @return string Code source PHP complet du fichier {$entityName}{$pascalName}Dto.php
		 * @throws \Twig\Error\LoaderError
		 * @throws \Twig\Error\RuntimeError
		 * @throws \Twig\Error\SyntaxError
		 */
		public function buildSingleBooleanDto(string $entityName, string $namespace, string $fieldName, string $pascalName): string {
			/*
			 * Pas de logique conditionnelle ici : toute la structure du DTO (une seule
			 * propriété bool, son URI dédiée) est entièrement déléguée au template Twig
			 * 'single_boolean_dto.php.twig' — ce Builder ne fait que calculer les variantes
			 * de casse du nom de champ (camelCase, PascalCase, kebab-case) nécessaires
			 * à la génération du nom de classe et du segment d'URI.
			 */
			return $this->twig->render('single_boolean_dto.php.twig', [
				'namespace'   => $namespace,
				'entity_name' => $entityName,
				'field_name'  => $fieldName,
				'pascal_name' => $pascalName,
				'uri_segment' => $this->namespaceResolver->toggleUriSegment($fieldName),
				'uri_name'    => $this->namespaceResolver->toApiPlatformUriBase($entityName),
			]);
		}

		/* ── DTO Upload (VichUploader) ─── */

		/**
		 * Génère le DTO d'upload de fichier — endpoint PATCH /{id}/{segment} (multipart/form-data).
		 *
		 * @param string                                                                                   $entityName Nom court de l'entité
		 * @param string                                                                                   $namespace  Namespace PHP du DTO
		 * @param array{propSuffix: string, fieldName: string, assertFileRaw: string, uriSegment?: string} $uploadField
		 *
		 * @return string Code source PHP complet du fichier {$entityName}Upload{suffix}Dto.php
		 * @throws \Twig\Error\LoaderError
		 * @throws \Twig\Error\RuntimeError
		 * @throws \Twig\Error\SyntaxError
		 */
		public function buildUploadDto(string $entityName, string $namespace, array $uploadField): string {
			$propSuffix = $uploadField['propSuffix'];
			$fieldName  = $uploadField['fieldName'];

			$uses = [
				'use Symfony\Component\HttpFoundation\File\File;',
				'use Symfony\Component\Validator\Constraints as Assert;',
			];
			sort($uses);
			$usesBlock = implode("\n", $uses);

			/*
			 * assertFileRaw provient soit de Reflection, soit du parsing AST (selon que
			 * VichUploader est installé ou non — cf. FieldAnalyser::detectUploadFields()) :
			 * c'est la chaîne brute de l'attribut #[Assert\File(...)] déjà déclaré sur
			 * l'entité, qu'on réindente proprement pour l'insérer dans le DTO généré.
			 */
			$assertFileStr = $this->normalizeAttributeIndent($uploadField['assertFileRaw']);

			return $this->twig->render('upload_dto.php.twig', [
				'namespace'       => $namespace,
				'uses_block'      => $usesBlock,
				'entity_name'     => $entityName,
				'field_name'      => $fieldName,
				'prop_suffix'     => $propSuffix,
				'assert_file_raw' => $assertFileStr,
			]);
		}

		/* ── Helpers privés ─── */

		/**
		 * Normalise et formate un tableau de contraintes #[Assert\*] brutes pour insertion dans un DTO.
		 *
		 * Gère trois types de références externes :
		 *  - `self::CONST`                  → remplacé par `EntityName::CONST` (entité importée si besoin)
		 *  - `OtherClass::CONST`            → résolu via $useMap et ajouté aux imports supplémentaires
		 *  - `Namespace\ClassName` dans #[attributs] → résolu via $useMap (ex. #[AssertPhoneNumber\PhoneNumber])
		 *
		 * L'indentation est normalisée à 4 espaces via {@see normalizeAttributeIndent()}.
		 *
		 * @param list<string>         $extracted         Contraintes brutes extraites du source de l'entité
		 * @param string               $entityName        Nom court de l'entité (ex. 'Schoolyear')
		 * @param bool                 $needsEntityImport Flag cumulatif (true si self:: trouvé)
		 * @param array<string,string> $useMap            Map ['ShortName' => 'FQCN'] extraite de l'entité
		 *
		 * @return array{string, bool, list<string>} [bloc formaté, $needsEntityImport mis à jour, imports supplémentaires]
		 */
		private function buildExtractedConstraintBlock(array $extracted, string $entityName, bool $needsEntityImport, array $useMap = []): array {
			$normalized = [];
			$extraUses  = [];

			foreach ($extracted as $raw) {
				/*
				 * ── self:: → EntityName:: ─────────────────────────────────────────
				 * Les contraintes #[Assert\*] de l'entité référencent parfois une
				 * constante de classe via self:: (ex. self::STATUS_ACTIVE). Cette
				 * référence n'a aucun sens une fois copiée dans le DTO (autre classe) :
				 * on la réécrit donc en EntityName:: et on mémorise qu'il faudra importer
				 * l'entité (cf. $needsEntityImport, agrégé par l'appelant sur tous les champs).
				 */
				if (str_contains($raw, 'self::')) {
					$needsEntityImport = true;
					$raw               = str_replace('self::', $entityName . '::', $raw);
				}

				/*
				 * ── ClassName:: → résolution FQCN via useMap ──────────────────────
				 * Cherche tous les patterns Word:: qui ne sont pas Assert\ ni EntityName
				 * Une contrainte peut aussi référencer une constante d'une AUTRE classe
				 * (ex. OtherEnum::SOME_VALUE). On détecte ce pattern par regex, on exclut
				 * volontairement 'Assert' (le namespace alias des contraintes Symfony,
				 * pas une classe à importer) et le nom de l'entité elle-même (déjà géré
				 * ci-dessus), puis on résout le FQCN réel via $useMap — qui contient le
				 * mapping ['NomCourt' => 'Fqcn\Complet'] extrait des `use` de l'entité.
				 */
				if (preg_match_all('/\b([A-Z]\w+)::/', $raw, $matches)) {
					foreach ($matches[1] as $shortName) {
						if ($shortName === $entityName || $shortName === 'Assert') {
							continue;
						}

						if (isset($useMap[$shortName])) {
							$stmt = 'use ' . $useMap[$shortName] . ';';

							/*
							 * Si le FQCN ne se termine pas par "\NomCourt" (cas d'un alias,
							 * ex. 'use Foo\Bar as Baz;' dans l'entité d'origine), on doit
							 * reproduire l'alias dans le DTO généré pour que la référence
							 * "Baz::CONST" reste valide une fois copiée.
							 */
							if (!str_ends_with($useMap[$shortName], '\\' . $shortName)) {
								$stmt = 'use ' . $useMap[$shortName] . ' as ' . $shortName . ';';
							}

							if (!in_array($stmt, $extraUses, true)) {
								$extraUses[] = $stmt;
							}
						}
					}
				}

				/*
				 * ── Namespace\Class attribute references ─────────────────────────
				 * Détecte les références à des classes tierces dans les attributs copiés
				 * (ex. #[AssertPhoneNumber\PhoneNumber(...)]) et résout leur import
				 * via $useMap pour que le DTO généré contienne le bon `use`.
				 *
				 * Sans cela, un attribut comme #[AssertPhoneNumber\PhoneNumber] serait
				 * recopié tel quel dans le DTO, mais sans l'instruction `use ... as
				 * AssertPhoneNumber;` correspondante — ce qui produit un code qui ne
				 * compile pas.
				 */
				if (preg_match_all('/\b([A-Z]\w*)\\\\[A-Z]/', $raw, $nsMatches)) {
					foreach ($nsMatches[1] as $nsPrefix) {
						if ($nsPrefix === 'Assert' || $nsPrefix === $entityName || !isset($useMap[$nsPrefix])) {
							continue;
						}

						$stmt = 'use ' . $useMap[$nsPrefix] . ';';

						if (!str_ends_with($useMap[$nsPrefix], '\\' . $nsPrefix)) {
							$stmt = 'use ' . $useMap[$nsPrefix] . ' as ' . $nsPrefix . ';';
						}

						if (!in_array($stmt, $extraUses, true)) {
							$extraUses[] = $stmt;
						}
					}
				}

				$normalized[] = $this->normalizeAttributeIndent($raw);
			}

			return [implode("\n", $normalized), $needsEntityImport, $extraUses];
		}

		/**
		 * Adapte les contraintes de l'entité au PATCH partiel, où null signifie « champ non envoyé » :
		 * NotBlank tolère null (allowNull: true) et NotNull, qui rejetterait tout PATCH partiel, est retiré.
		 *
		 * @param list<string> $constraints
		 *
		 * @return list<string>
		 */
		private function relaxForPartialUpdate(array $constraints): array {
			$relaxed = [];

			foreach ($constraints as $constraint) {
				if (preg_match('/#\[Assert\\\\NotNull\b/', $constraint) === 1) {
					continue;
				}

				if (!str_contains($constraint, 'allowNull')) {
					$constraint = (string)preg_replace_callback(
						'/#\[Assert\\\\NotBlank(\]|\()/',
						static fn(array $m): string => $m[1] === ']' ? '#[Assert\\NotBlank(allowNull: true)]' : '#[Assert\\NotBlank(allowNull: true, ',
						$constraint,
					);
				}

				$relaxed[] = $constraint;
			}

			return $relaxed;
		}

		/*
		 * Import de la classe hydratée par un type Doctrine personnalisé (ex. `phone_number` → libphonenumber\PhoneNumber),
		 * vide pour un type standard.
		 */
		/**
		 * @return list<string>
		 */
		private function customTypeUses(string $doctrineType): array {
			$class = $this->fieldAnalyser->customTypeClass($doctrineType);

			return $class === null ? [] : ["use {$class};"];
		}

		/**
		 * Construit le bloc `use` final : dédupliqué, trié, avec DateTimeImmutable et Assert si nécessaires.
		 *
		 * @param list<string> $uses
		 * @param bool         $needsDt
		 * @param bool         $needsAssert
		 * @param bool         $needsUuid
		 *
		 * @return string Bloc use (sans retour à la ligne final), ou chaîne vide
		 */
		private function buildUsesBlock(array $uses, bool $needsDt, bool $needsAssert, bool $needsUuid = false): string {
			/* DateTimeImmutable n'est ajouté que si au moins un champ date/datetime a été rencontré pendant la boucle d'appel — éviter un import mort dans les DTOs sans champ temporel. Le type property est DateTimeImmutable (déterminé par FieldAnalyser::toPhpType()), donc l'import doit correspondre. */
			if ($needsDt) {
				$uses[] = 'use DateTimeImmutable;';
			}

			/* Uuid (colonnes de type « uuid ») : le type de propriété doit être importé. */
			if ($needsUuid) {
				$uses[] = 'use Symfony\\Component\\Uid\\Uuid;';
			}

			/* Idem pour l'alias Assert : seulement si au moins une contrainte a été extraite et reproduite sur une propriété du DTO. */
			if ($needsAssert) {
				$uses[] = 'use Symfony\\Component\\Validator\\Constraints as Assert;';
			}

			/* Déduplication + tri alphabétique final, après agrégation de tous les `use` (base + relations + contraintes + imports conditionnels ci-dessus). */
			$uses = array_values(array_unique($uses));
			sort($uses);

			return implode("\n", $uses);
		}

		/**
		 * Normalise l'indentation d'un bloc #[Assert\File(...)] à 4 espaces.
		 *
		 * @param string $raw Bloc brut extrait de l'entité source
		 *
		 * @return string Bloc normalisé ou '#[Assert\File]' minimal
		 */
		private function normalizeAttributeIndent(string $raw): string {
			/* Aucun attribut #[Assert\File] détecté sur l'entité (VichUploader sans validation explicite) : on retombe sur un attribut minimal sans paramètres plutôt que de produire une propriété totalement sans validation. */
			if (trim($raw) === '') {
				return '    #[Assert\\File]';
			}

			/*
			 * L'indentation d'origine (telle qu'écrite dans le fichier source de l'entité)
			 * est arbitraire et dépend du niveau d'imbrication où elle a été trouvée par
			 * Reflection/AST. On mesure l'indentation de la PREMIÈRE ligne ($base) pour
			 * la considérer comme la référence, puis on la retire de chaque ligne avant
			 * de réappliquer uniformément 4 espaces — convention des propriétés de DTO.
			 */
			$lines   = explode("\n", $raw);
			$base    = strlen($lines[0]) - strlen(ltrim($lines[0]));
			$baseStr = str_repeat(' ', $base);

			return implode("\n", array_map(
			/*
			 * Si la ligne commence bien par l'indentation de référence, on la retire
			 * proprement (substr) ; sinon (ligne moins indentée que prévu, cas limite)
			 * on se contente d'un ltrim() défensif pour ne jamais produire d'indentation négative.
			 */
				static fn(string $line): string => '    ' . (str_starts_with($line, $baseStr) ? substr($line, $base) : ltrim($line)), $lines,
			));
		}
	}
