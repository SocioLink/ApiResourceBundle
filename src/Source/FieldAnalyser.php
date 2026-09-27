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
	 * Description: Analyse des champs d'une entité Doctrine ORM et de son code source, pour alimenter tous les générateurs.
	 *              getEntityFields() normalise les champs scalaires (type, nullabilité, longueur, valeurs des énumérations
	 *              backed) et les associations (cible, ToMany, OneToMany, mappedBy, nullabilité des colonnes de jointure) à
	 *              partir du seul mapping Doctrine : attributs, XML et propriétés héritées sont pris en charge. toPhpType()
	 *              convertit les types Doctrine en types PHP et mémorise les types non reconnus.
	 *              Par analyse AST (nikic/php-parser), extrait les contraintes #[Assert\*] propriété par propriété et les
	 *              imports de l'entité (imports groupés et classes du même dossier compris), et détecte les champs
	 *              #[UploadableField] de VichUploader, par réflexion si le bundle est installé, sinon depuis le code
	 *              source.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Source;

	use Throwable;
	use PhpParser\Node;
	use ReflectionEnum;
	use ReflectionClass;
	use PhpParser\Parser;
	use ReflectionAttribute;
	use PhpParser\NodeFinder;
	use PhpParser\ParserFactory;
	use Doctrine\DBAL\Types\Types;
	use PhpParser\PrettyPrinter\Standard;
	use Doctrine\ORM\Mapping\ClassMetadata;
	use Doctrine\ORM\Mapping\InverseSideMapping;
	use Doctrine\ORM\Mapping\ToOneOwningSideMapping;

	/**
	 * Analyse les champs d'une entité Doctrine ORM et fournit des données normalisées
	 * utilisées par DtoBuilder et ProcessorBuilder pour la génération de code.
	 *
	 * @phpstan-type FieldInfo array{
	 *     doctrineType: string,
	 *     nullable: bool,
	 *     isRelation: bool,
	 *     isToMany: bool,
	 *     isOneToMany: bool,
	 *     length: int|null,
	 *     isEnum: bool,
	 *     enumCases: list<string|int>,
	 *     mappedBy: string
	 * }
	 * @phpstan-type UploadFieldInfo array{
	 *     fieldName: string,
	 *     fileNameProperty: string|null,
	 *     mapping: string,
	 *     propSuffix: string,
	 *     uriSegment: string,
	 *     assertFileRaw: string
	 * }
	 *
	 * @internal Réservé à l'usage interne de la commande generate:resource.
	 */
	final class FieldAnalyser {
		private const string UPLOADABLE_FIELD_ATTRIBUTE = 'Vich\UploaderBundle\Mapping\Attribute\UploadableField';

		private const string ASSERT_FILE_ATTRIBUTE = 'Symfony\Component\Validator\Constraints\File';

		/**
		 * Types Doctrine non reconnus rencontrés pendant l'exécution en cours.
		 *
		 * @var array<string, true>
		 */
		private array $unrecognizedTypes = [];

		private Parser|null $parser = null;

		public function __construct(private readonly NamespaceResolver $namespaceResolver, private readonly GeneratorConfig $config) {}

		/**
		 * @return list<string> Types non reconnus (ex. ['App\Doctrine\MoneyType'])
		 */
		public function getUnrecognizedTypes(): array {
			return array_keys($this->unrecognizedTypes);
		}

		/**
		 * Réinitialise la liste des types non reconnus.
		 */
		public function resetUnrecognizedTypes(): void {
			$this->unrecognizedTypes = [];
		}

		/* ── Extraction des champs ─── */

		/**
		 * Extrait et normalise l'ensemble des champs d'une entité Doctrine.
		 *
		 * Toutes les informations proviennent du mapping Doctrine (attributs, XML ou PHP) : aucune
		 * réflexion sur les propriétés, ce qui fonctionne aussi pour les propriétés héritées.
		 *
		 * @param ClassMetadata<object> $metadata
		 *
		 * @return array<string, FieldInfo>
		 * @throws \Doctrine\ORM\Mapping\MappingException
		 */
		public function getEntityFields(ClassMetadata $metadata): array {
			$fields = [];

			/* ── Champs scalaires ────────────────────────────────────────────────── */
			foreach ($metadata->getFieldNames() as $name) {
				$mapping = $metadata->getFieldMapping($name);

				/* Enums backed (PHP 8.1+) : les valeurs de backing alimentent les exemples de tests. */
				$enumCases = [];
				$enumType  = $mapping->enumType;

				if ($enumType !== null && enum_exists($enumType) && new ReflectionEnum($enumType)->isBacked()) {
					/** @var list<\BackedEnum> $cases */
					$cases = $enumType::cases();

					foreach ($cases as $case) {
						$enumCases[] = $case->value;
					}
				}

				$fields[$name] = [
					'doctrineType' => $metadata->getTypeOfField($name) ?? 'string',
					'nullable'     => $metadata->isNullable($name),
					'isRelation'   => false,
					'isToMany'     => false,
					'isOneToMany'  => false,
					'length'       => $mapping->length,
					'isEnum'       => $enumCases !== [],
					'enumCases'    => $enumCases,
					'mappedBy'     => '',
				];
			}

			/* ── Associations ────────────────────────────────────────────────────── */
			foreach ($metadata->getAssociationNames() as $name) {
				$assoc = $metadata->getAssociationMapping($name);

				$fields[$name] = [
					'doctrineType' => $assoc->targetEntity,
					'nullable'     => $this->resolveAssociationNullability($assoc),
					'isRelation'   => true,
					'isToMany'     => $metadata->isCollectionValuedAssociation($name),
					'isOneToMany'  => $assoc->isOneToMany(),
					'length'       => null,
					'isEnum'       => false,
					'enumCases'    => [],
					'mappedBy'     => $assoc instanceof InverseSideMapping ? $assoc->mappedBy : '',
				];
			}

			return $fields;
		}

		/**
		 * Filtre les champs de type booléen (scalaires uniquement, hors relations).
		 *
		 * @template T of array{doctrineType: string, isRelation: bool}
		 *
		 * @param array<string, T> $fields
		 *
		 * @return array<string, T>
		 */
		public function getBooleanFields(array $fields): array {
			return array_filter($fields, static fn(array $info): bool => !$info['isRelation'] && strtolower($info['doctrineType']) === 'boolean');
		}

		/* ── Conversion de types ─── */

		/**
		 * Convertit un type Doctrine en son équivalent PHP natif.
		 *
		 * `decimal` reste une chaîne : c'est la représentation hydratée par Doctrine (pas de perte de précision).
		 *
		 * @param string $doctrineType Identifiant de type Doctrine
		 *
		 * Un type personnalisé déclaré dans GeneratorConfig::$customTypes (ex. `phone_number`) donne le nom court de
		 * sa classe, que l'appelant importe via customTypeClass().
		 *
		 * @return string Type PHP (ex. 'string', 'int', 'float', 'bool', 'DateTimeImmutable', 'Uuid', 'array', 'PhoneNumber')
		 */
		public function toPhpType(string $doctrineType): string {
			$customClass = $this->customTypeClass($doctrineType);

			if ($customClass !== null) {
				return $this->namespaceResolver->getShortClassName($customClass);
			}

			$type = match ($doctrineType) {
				Types::STRING, Types::TEXT, Types::ASCII_STRING, Types::DECIMAL,
				Types::GUID                                             => 'string', /* guid est hydraté en chaîne par Doctrine */
				Types::INTEGER, Types::SMALLINT, Types::BIGINT          => 'int',
				Types::FLOAT, 'smallfloat'                              => 'float', /* Types::SMALLFLOAT n'existe qu'à partir de DBAL 4.1 */
				Types::BOOLEAN                                          => 'bool',
				Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE,
				Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE,
				Types::DATE_MUTABLE, Types::DATE_IMMUTABLE,
				Types::TIME_MUTABLE, Types::TIME_IMMUTABLE              => 'DateTimeImmutable',
				'uuid', 'uuid_binary'                                   => 'Uuid', /* types symfony/doctrine-bridge */
				Types::JSON, Types::SIMPLE_ARRAY, 'json_array', 'array' => 'array',
				default                                                 => null,
			};

			if ($type !== null) {
				return $type;
			}

			$this->unrecognizedTypes[$doctrineType] = true;

			return 'string';
		}

		/**
		 * FQCN de la classe hydratée par un type Doctrine personnalisé déclaré, ou null (type standard ou inconnu).
		 */
		public function customTypeClass(string $doctrineType): string|null {
			return $this->config->customTypes[$doctrineType] ?? null;
		}

		/* ── Détection des champs uploadables (VichUploader) ─── */

		/**
		 * Détecte les propriétés annotées avec #[UploadableField] de VichUploader.
		 *
		 * Par réflexion si VichUploader est installé, sinon par analyse du code source.
		 *
		 * @param string $entityClass FQCN complet de l'entité
		 *
		 * @return list<UploadFieldInfo>
		 */
		public function detectUploadFields(string $entityClass): array {
			if (class_exists(self::UPLOADABLE_FIELD_ATTRIBUTE)) {
				return $this->detectUploadFieldsViaReflection($entityClass);
			}

			$stmts = $this->parseEntity($entityClass);

			return $stmts === null ? [] : $this->scanUploadFields($stmts);
		}

		/* ── Extraction des contraintes depuis le code source de l'entité ─── */

		/**
		 * Extrait les instructions `use` déclarées dans le fichier source de l'entité, complétées par
		 * les classes du même dossier (même espace de noms, utilisables sans `use`).
		 *
		 * @param string $entityClass FQCN complet de l'entité
		 *
		 * @return array<string, string> ['ShortName' => 'Full\Qualified\ClassName']
		 */
		public function extractUseStatements(string $entityClass): array {
			$stmts = $this->parseEntity($entityClass);

			if ($stmts === null) {
				return [];
			}

			$uses = [];

			$finder = new NodeFinder();

			foreach ($finder->findInstanceOf($stmts, Node\Stmt\Use_::class) as $node) {
				foreach ($node->uses as $use) {
					$uses[$use->getAlias()->name] = $use->name->toString();
				}
			}

			/* Imports groupés : use Foo\{Bar, Baz as Qux}; */
			foreach ($finder->findInstanceOf($stmts, Node\Stmt\GroupUse::class) as $node) {
				foreach ($node->uses as $use) {
					$uses[$use->getAlias()->name] = $node->prefix->toString() . '\\' . $use->name->toString();
				}
			}

			$pos       = strrpos($entityClass, '\\');
			$namespace = $pos === false ? '' : substr($entityClass, 0, $pos);

			foreach (glob(dirname($this->namespaceResolver->entityFilePath($entityClass)) . '/*.php') ?: [] as $file) {
				$className        = basename($file, '.php');
				$uses[$className] ??= $namespace !== '' ? $namespace . '\\' . $className : $className;
			}

			return $uses;
		}

		/**
		 * Extrait, propriété par propriété, les attributs de validation (#[Assert\*] et contraintes tierces
		 * dont l'alias contient « Assert ») depuis le code source via AST.
		 *
		 * Chaque attribut est rendu SEUL : dans un groupe `#[ORM\Column, Assert\NotBlank]`, seul
		 * `#[Assert\NotBlank]` est repris.
		 *
		 * @param string $entityClass FQCN complet de l'entité
		 *
		 * @return array<string, list<string>>
		 */
		public function extractAssertAttributes(string $entityClass): array {
			$stmts = $this->parseEntity($entityClass);

			if ($stmts === null) {
				return [];
			}

			$result  = [];
			$printer = new Standard();

			foreach (new NodeFinder()->findInstanceOf($stmts, Node\Stmt\Property::class) as $property) {
				$propName = $property->props[0]->name->name;

				foreach ($property->attrGroups as $attrGroup) {
					foreach ($attrGroup->attrs as $attr) {
						if ($this->isValidationAttribute($attr->name->toString())) {
							$result[$propName][] = '    ' . $printer->prettyPrint([new Node\AttributeGroup([$attr])]);
						}
					}
				}
			}

			return $result;
		}

		/* ── Dérivation des noms d'upload ─── */

		/**
		 * Dérive le suffixe CamelCase pour les artefacts d'upload.
		 * Exemple : 'imageFile' → 'Image' ; 'avatar' → 'Avatar'
		 */
		public function uploadPropSuffix(string $fieldName): string {
			return ucfirst((string)preg_replace('/File$/i', '', $fieldName));
		}

		/**
		 * Dérive le segment d'URI kebab-case pour un suffixe d'upload.
		 * Exemple : 'Image' → 'image' ; 'CvFile' → 'cv-file'
		 */
		public function uploadUriSegment(string $propSuffix): string {
			return strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $propSuffix));
		}

		/* ── Helpers privés ─── */

		/**
		 * Résout la nullabilité d'une association : seul le côté propriétaire d'une relation ToOne
		 * porte des colonnes de jointure ; tout le reste est considéré comme optionnel.
		 */
		private function resolveAssociationNullability(object $assoc): bool {
			if (!$assoc instanceof ToOneOwningSideMapping || $assoc->joinColumns === []) {
				return true;
			}

			return $assoc->joinColumns[0]->nullable ?? true;
		}

		/**
		 * Indique si un nom d'attribut désigne une contrainte de validation à recopier dans les DTOs.
		 */
		private function isValidationAttribute(string $name): bool {
			return str_contains($name, 'Assert') || str_starts_with(ltrim($name, '\\'), 'Symfony\Component\Validator\Constraints\\');
		}

		/**
		 * Analyse le fichier source de l'entité, ou null s'il est introuvable ou invalide.
		 *
		 * @return list<Node\Stmt>|null
		 */
		private function parseEntity(string $entityClass): ?array {
			$filePath = $this->namespaceResolver->entityFilePath($entityClass);

			if (!is_file($filePath)) {
				return null;
			}

			try {
				$this->parser ??= new ParserFactory()->createForNewestSupportedVersion();

				/** @var list<Node\Stmt>|null $stmts */
				$stmts = $this->parser->parse((string)file_get_contents($filePath));

				return $stmts;
			}
			catch (Throwable) {
				return null;
			}
		}

		/**
		 * Détecte les champs uploadables via la Reflection API PHP (VichUploader installé).
		 *
		 * @return list<UploadFieldInfo>
		 */
		private function detectUploadFieldsViaReflection(string $entityClass): array {
			if (!class_exists($entityClass)) {
				return [];
			}

			$results = [];

			foreach (new ReflectionClass($entityClass)->getProperties() as $property) {
				$uploadAttrs = $property->getAttributes(self::UPLOADABLE_FIELD_ATTRIBUTE);

				if ($uploadAttrs === []) {
					continue;
				}

				/** @var object{mapping?: string, fileNameProperty?: string|null} $inst */
				$inst       = $uploadAttrs[0]->newInstance();
				$fieldName  = $property->getName();
				$propSuffix = $this->uploadPropSuffix($fieldName);

				$results[] = [
					'fieldName'        => $fieldName,
					'fileNameProperty' => $inst->fileNameProperty ?? null,
					'mapping'          => $inst->mapping ?? '',
					'propSuffix'       => $propSuffix,
					'uriSegment'       => $this->uploadUriSegment($propSuffix),
					/* IS_INSTANCEOF : #[Assert\Image] (sous-classe de File) est aussi repris. */
					'assertFileRaw'    => $this->buildAssertFileRaw($property->getAttributes(self::ASSERT_FILE_ATTRIBUTE, ReflectionAttribute::IS_INSTANCEOF)),
				];
			}

			return $results;
		}

		/**
		 * Reconstruit l'attribut #[Assert\File(...)] (ou #[Assert\Image(...)]) depuis la réflexion.
		 *
		 * @param list<ReflectionAttribute<object>> $assertFileAttrs
		 *
		 * @return string Attribut formaté ou '#[Assert\File]' minimal
		 */
		private function buildAssertFileRaw(array $assertFileAttrs): string {
			if ($assertFileAttrs === []) {
				return '    #[Assert\\File]';
			}

			$attribute = $assertFileAttrs[0];
			$shortName = substr($attribute->getName(), (int)strrpos($attribute->getName(), '\\') + 1);
			$args      = $attribute->getArguments();

			if ($args === []) {
				return "    #[Assert\\{$shortName}]";
			}

			$argStrings = [];

			foreach ($args as $key => $value) {
				$argStrings[] = (is_string($key) ? "{$key}: " : '') . $this->exportValue($value);
			}

			return "    #[Assert\\{$shortName}(" . implode(', ', $argStrings) . ')]';
		}

		/**
		 * Exporte une valeur d'argument d'attribut en littéral PHP (chaînes échappées, tableaux récursifs).
		 */
		private function exportValue(mixed $value): string {
			if (is_array($value)) {
				$items = [];

				foreach ($value as $key => $item) {
					$items[] = (array_is_list($value) ? '' : $this->exportValue($key) . ' => ') . $this->exportValue($item);
				}

				return '[' . implode(', ', $items) . ']';
			}

			if ($value === null || is_bool($value)) {
				return strtolower(var_export($value, true));
			}

			if (is_scalar($value)) {
				return var_export($value, true);
			}

			return 'null'; /* objet (ex. enum) : non reproductible fidèlement, ignoré */
		}

		/**
		 * Parcourt l'AST de l'entité pour détecter les champs uploadables (VichUploader non installé).
		 *
		 * @param list<Node\Stmt> $stmts
		 *
		 * @return list<UploadFieldInfo>
		 */
		private function scanUploadFields(array $stmts): array {
			$results = [];
			$printer = new Standard();

			foreach (new NodeFinder()->findInstanceOf($stmts, Node\Stmt\Property::class) as $property) {
				$attrs = [];

				foreach ($property->attrGroups as $attrGroup) {
					foreach ($attrGroup->attrs as $attr) {
						$attrs[] = $attr;
					}
				}

				$upload = null;

				foreach ($attrs as $attr) {
					if (str_ends_with($attr->name->toString(), 'UploadableField')) {
						$upload = $attr;
					}
				}

				if ($upload === null) {
					continue;
				}

				$mapping      = '';
				$fileNameProp = null;

				foreach ($upload->args as $index => $arg) {
					if (!$arg->value instanceof Node\Scalar\String_) {
						continue;
					}

					$argName = $arg->name !== null ? $arg->name->name : ($index === 0 ? 'mapping' : null);

					if ($argName === 'mapping') {
						$mapping = $arg->value->value;
					}
					elseif ($argName === 'fileNameProperty') {
						$fileNameProp = $arg->value->value;
					}
				}

				$assertFileRaw = '    #[Assert\\File]';

				foreach ($attrs as $attr) {
					if (preg_match('/Assert\\\\(File|Image)$/', $attr->name->toString()) === 1) {
						$assertFileRaw = '    ' . $printer->prettyPrint([new Node\AttributeGroup([$attr])]);
					}
				}

				$fieldName  = $property->props[0]->name->name;
				$propSuffix = $this->uploadPropSuffix($fieldName);

				$results[] = [
					'fieldName'        => $fieldName,
					'fileNameProperty' => $fileNameProp,
					'mapping'          => $mapping,
					'propSuffix'       => $propSuffix,
					'uriSegment'       => $this->uploadUriSegment($propSuffix),
					'assertFileRaw'    => $assertFileRaw,
				];
			}

			return $results;
		}
	}
