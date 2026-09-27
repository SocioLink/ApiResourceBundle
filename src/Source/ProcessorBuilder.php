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
	 * Creation Date: 27/08/2026 12:50
	 *
	 * Description: Générateur du code source des State Processors API Platform associés aux DTOs générés.
	 *              Create : instancie l'entité depuis le CreateDto, la valide puis délègue la persistance au processor
	 *              Doctrine. Update : charge l'entité (UUID pris en charge) et n'applique que les valeurs non nulles.
	 *              Toggle et bascule par booléen : mêmes principes, avec la logique soft-delete/soft-erase (restauration
	 *              réservée à l'auteur de la suppression, levée d'un effacement réservée au rôle d'administration). Upload
	 *              : validation du fichier multipart par le DTO puis affectation via le setter de l'entité.
	 *              Les imports sont centralisés, dédupliqués et triés ; le rendu est délégué aux gabarits Twig du bundle.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Source;

	use Twig\Environment;
	use ApiPlatform\Metadata\Operation;
	use Symfony\Component\DependencyInjection\Attribute\Autowire;

	/**
	 * Génère le code source PHP des State Processors pour API Platform.
	 *
	 * @internal Réservé à l'usage interne de la commande generate:resource.
	 */
	final readonly class ProcessorBuilder {
		/** Imports communs à tous les processors qui chargent l'entité par son identifiant. */
		private const array ENTITY_LOADING_USES = [
			Operation::class,
			'ApiPlatform\State\ProcessorInterface',
			'ApiPlatform\Validator\Exception\ValidationException as ApiValidationException',
			'Doctrine\ORM\EntityManagerInterface',
			'ReflectionObject',
			'Symfony\Component\DependencyInjection\Attribute\Autowire',
			'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
			'Symfony\Component\Uid\Uuid',
			'Symfony\Component\Validator\Validator\ValidatorInterface',
		];

		/** Imports supplémentaires de la logique soft-delete / soft-erase. */
		private const array SECURITY_USES = [
			'Symfony\Bundle\SecurityBundle\Security as AppSecurity',
			'Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException as AppAccessDeniedException',
			'Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException as AppUnprocessableException',
		];

		/**
		 * @param NamespaceResolver $namespaceResolver Segments d'URI (documentation des endpoints générés)
		 * @param GeneratorConfig   $config            Champs booléens spéciaux et rôle d'administration
		 * @param Environment       $twig              Moteur de gabarits Twig dédié du bundle
		 */
		public function __construct(
			private NamespaceResolver                                               $namespaceResolver,
			private GeneratorConfig                                                 $config,
			#[Autowire(service: 'sociolink_api_resource.twig')] private Environment $twig,
		) {}

		/**
		 * Processor POST : instancie l'entité depuis le CreateDto puis la persiste.
		 */
		public function buildCreateProcessor(string $entityClass, string $entityName, string $stateNs, string $dtoNs): string {
			return $this->twig->render('create_processor.php.twig', [
				'namespace'   => $stateNs,
				'uses_block'  => $this->usesBlock(
					[
						'ApiPlatform\Metadata\Operation',
						'ApiPlatform\State\ProcessorInterface',
						'ApiPlatform\Validator\Exception\ValidationException as ApiValidationException',
						'ReflectionObject',
						'Symfony\Component\DependencyInjection\Attribute\Autowire',
						'Symfony\Component\Validator\Validator\ValidatorInterface',
						$entityClass,
						"{$dtoNs}\\{$entityName}CreateDto",
					]
				),
				'entity_name' => $entityName,
			]);
		}

		/**
		 * Processor PATCH : applique les valeurs non nulles de l'UpdateDto à l'entité existante.
		 */
		public function buildUpdateProcessor(string $entityClass, string $entityName, string $stateNs, string $dtoNs): string {
			return $this->twig->render('update_processor.php.twig', [
				'namespace'   => $stateNs,
				'uses_block'  => $this->usesBlock([...self::ENTITY_LOADING_USES, $entityClass, "{$dtoNs}\\{$entityName}UpdateDto"]),
				'entity_name' => $entityName,
			]);
		}

		/**
		 * Processor PATCH /{id}/toggle : bascule tous les booléens (logique soft-delete/soft-erase comprise).
		 *
		 * @param array<string, mixed> $booleanFields
		 */
		public function buildToggleProcessor(string $entityClass, string $entityName, string $stateNs, string $dtoNs, array $booleanFields): string {
			$hasItDeleted  = isset($booleanFields['itDeleted']);
			$hasItErased   = isset($booleanFields['itErased']);
			$needsSecurity = $hasItDeleted || $hasItErased;

			$uses = [...self::ENTITY_LOADING_USES, $entityClass, "{$dtoNs}\\{$entityName}ToggleDto"];

			if ($needsSecurity) {
				$uses = [...$uses, ...self::SECURITY_USES];
			}

			return $this->twig->render('toggle_processor.php.twig', [
				'namespace'      => $stateNs,
				'uses_block'     => $this->usesBlock($uses),
				'entity_name'    => $entityName,
				'uri_name'       => $this->namespaceResolver->toApiPlatformUriBase($entityName),
				'has_it_deleted' => $hasItDeleted,
				'has_it_erased'  => $hasItErased,
				'exclude_list'   => implode(', ', array_map(static fn(string $f): string => var_export($f, true), $this->config->booleanSpecialFields)),
				'needs_security' => $needsSecurity,
				'admin_role'     => $this->config->adminRole,
			]);
		}

		/**
		 * Processor PATCH /{id}/toggle-{champ} : un processor par booléen (--detach-boolean).
		 */
		public function buildSingleBooleanProcessor(string $entityClass, string $entityName, string $stateNs, string $dtoNs, string $fieldName, string $pascalName): string {
			$dtoClass      = "{$entityName}{$pascalName}Dto";
			$needsSecurity = in_array($fieldName, $this->config->booleanSpecialFields, true);

			$uses = [...self::ENTITY_LOADING_USES, $entityClass, "{$dtoNs}\\{$dtoClass}"];

			if ($needsSecurity) {
				$uses = [...$uses, ...self::SECURITY_USES];
			}

			return $this->twig->render('single_boolean_processor.php.twig', [
				'namespace'      => $stateNs,
				'uses_block'     => $this->usesBlock($uses),
				'entity_name'    => $entityName,
				'dto_class'      => $dtoClass,
				'field_name'     => $fieldName,
				'pascal_name'    => $pascalName,
				'needs_security' => $needsSecurity,
				'admin_role'     => $this->config->adminRole,
			]);
		}

		/**
		 * Processor d'upload multipart (VichUploader) : valide le fichier via le DTO puis l'affecte à l'entité.
		 *
		 * @param array{propSuffix: string, fieldName: string, uriSegment: string, assertFileRaw: string} $uploadField
		 */
		public function buildUploadProcessor(string $entityClass, string $entityName, string $stateNs, string $dtoNs, array $uploadField, bool $hasUpdatedAt): string {
			$dtoClass = $entityName . 'Upload' . $uploadField['propSuffix'] . 'Dto';

			$uses = [
				'ApiPlatform\Metadata\Operation',
				'ApiPlatform\State\ProcessorInterface',
				'ApiPlatform\Validator\Exception\ValidationException as ApiValidationException',
				'Symfony\Component\DependencyInjection\Attribute\Autowire',
				'Symfony\Component\HttpFoundation\File\UploadedFile',
				'Symfony\Component\HttpFoundation\Request',
				'Symfony\Component\HttpKernel\Exception\BadRequestHttpException',
				'Symfony\Component\Validator\Validator\ValidatorInterface',
				$entityClass,
				"{$dtoNs}\\{$dtoClass}",
			];

			if ($hasUpdatedAt) {
				$uses[] = 'DateTimeImmutable';
			}

			return $this->twig->render('upload_processor.php.twig', [
				'namespace'      => $stateNs,
				'uses_block'     => $this->usesBlock($uses),
				'entity_name'    => $entityName,
				'uri_name'       => $this->namespaceResolver->toApiPlatformUriBase($entityName),
				'field_name'     => $uploadField['fieldName'],
				'field_setter'   => 'set' . ucfirst($uploadField['fieldName']),
				'dto_class'      => $dtoClass,
				'uri_segment'    => $uploadField['uriSegment'],
				'has_updated_at' => $hasUpdatedAt,
				'prop_suffix'    => $uploadField['propSuffix'],
			]);
		}

		/**
		 * Construit le bloc `use` : dédupliqué et trié.
		 *
		 * @param list<string> $imports FQCN (éventuellement suivis de « as Alias »)
		 */
		private function usesBlock(array $imports): string {
			$imports = array_values(array_unique($imports));
			sort($imports);

			return implode("\n", array_map(static fn(string $import): string => "use {$import};", $imports));
		}
	}
