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
	 * Description: Classification des champs Doctrine en paramètres de filtrage API Platform (QueryParameter), déclarés dans
	 *              un catalogue <Entité>Filters (voir FilterCatalogueBuilder) relié à GetCollection et QueryCollection par
	 *              `collection_filters`, en remplacement des #[ApiFilter] dépréciés depuis API Platform 4.4.
	 *              Correspondance : champ status → StatusParameter(required: true) ; champ realm (relation ToOne ou
	 *              colonne uuid d'une API sans Core) → RealmParameter(required: true) ; chaîne (string, ascii_string) →
	 *              PartialSearchFilter ;
	 *              énumération ou type personnalisé → ExactFilter ; uuid/guid → UuidFilter ;
	 *              booléen → ExactFilter à schéma booléen ; numérique → ChainFilter(ExactFilter + ComparisonFilter) ;
	 *              date/heure → DateFilter ; relation ToOne → IriFilter ; relation ToMany ou champ nullable → ExistsFilter
	 *              groupé ; tri → SortFilter groupé.
	 *              Fonctions pures : définitions (buildDefinitions), rendu PHP (renderParameters), imports requis
	 *              (requiredFqcns) et exemples de requêtes valides et invalides pour les tests générés
	 *              (buildQueryExamples), paramètres obligatoires des requêtes de collection (buildRequiredQuery). Les champs
	 *              exclus et le tri sur les relations ToOne se règlent par la
	 *              configuration (filters.*).
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Source;

	/**
	 * Transforme les champs d'une entité Doctrine en DÉFINITIONS de paramètres API Platform
	 * (QueryParameter) puis les rend en code PHP, destiné au tableau retourné par `parameters()` d'un catalogue.
	 *
	 * Remplace la génération historique des attributs #[ApiFilter] (dépréciés depuis API Platform 4.4,
	 * supprimés en 6.0). La classification est une fonction pure (aucun accès disque, aucune
	 * dépendance Doctrine) : elle est donc testable seule, indépendamment de l'injecteur.
	 *
	 * Correspondance avec le contrat d'URL historique (voir migration-audit.md, annexe 12) :
	 *
	 *  - champ « status » (hors relation)       : StatusParameter(required)       ?status=STATUS_ACTIVATE
	 *  - champ « realm » (relation ToOne ou uuid) : RealmParameter(required)      ?realm=<uuid>
	 *  - string, ascii_string (hors énumération) : PartialSearchFilter          ?name=chai (LIKE %chai%, sans casse)
	 *  - énumération, type personnalisé         : ExactFilter                     ?status=draft
	 *  - uuid / guid (hors id)                  : UuidFilter                      ?ref=<uuid>
	 *  - boolean                                : ExactFilter (schéma booléen)    ?active=true
	 *  - integer / float / decimal              : ChainFilter(Exact + Comparison) ?price=25 · ?price[gte]=10
	 *  - date / heure                           : DateFilter (conservé)           ?createdAt[after]=2026-01-01
	 *  - relation ToOne                         : IriFilter                       ?author=/api/authors/{uuid}
	 *  - relation ToMany / champ nullable       : ExistsFilter                    ?exists[deletedAt]=true
	 *  - tri                                    : SortFilter                      ?order[name]=asc
	 *
	 * Structure d'une définition :
	 *  - 'kind'       : status | realm | partial | exact | uuid | boolean | numeric | date | iri | exists | sort
	 *  - 'key'        : clé du paramètre = nom d'URL (peut contenir le placeholder :property)
	 *  - 'property'   : propriété ciblée (kinds à propriété unique), null sinon
	 *  - 'properties' : propriétés ciblées (exists / sort), liste vide sinon
	 *  - 'sample'     : valeur d'exemple pour les tests générés (première valeur d'une énumération ; STATUS_ACTIVATE pour
	 *                   un status sans énumération), facultative
	 *
	 * Les champs exclus et le tri sur les relations ToOne se règlent par la configuration
	 * (`filters.excluded_fields`, `filters.sort_on_to_one_relations`).
	 *
	 * @phpstan-type FilterDefinition array{kind: string, key: string, property: string|null, properties: list<string>, sample?: string}
	 * @phpstan-type QueryExample array{label: string, query: array<string, mixed>}
	 *
	 * @internal Réservé à l'usage interne de la commande generate:resource.
	 */
	final readonly class FilterDefinitionBuilder {
		/* Sans configuration (tests unitaires) : aucun champ exclu, pas de tri sur les relations ToOne. */
		public function __construct(private GeneratorConfig|null $config = null) {}

		/**
		 * Types Doctrine exclus de tout filtre (hors ExistsFilter si le champ est nullable).
		 *
		 * @var list<string>
		 */
		private const array SKIP_TYPES = [
			'text', 'json', 'array', 'simple_array', 'json_array', 'object', 'blob', 'binary', 'dateinterval',
		];

		/**
		 * Types Doctrine de date et d'heure (ceux qui reçoivent un DateFilter).
		 *
		 * @var list<string>
		 */
		private const array DATE_TYPES = [
			'datetime', 'datetime_immutable', 'datetime_mutable', 'datetimetz', 'datetimetz_immutable',
			'date', 'date_immutable', 'date_mutable', 'time', 'time_immutable', 'time_mutable',
		];

		/**
		 * Types Doctrine numériques.
		 *
		 * @var list<string>
		 */
		private const array NUMERIC_TYPES = ['integer', 'smallint', 'bigint', 'float', 'decimal'];

		/**
		 * Types Doctrine d'identifiants universels (colonnes UUID hors clé primaire).
		 *
		 * @var list<string>
		 */
		private const array UUID_TYPES = ['uuid', 'guid', 'uuid_binary'];

		/**
		 * Types Doctrine chaîne recherchés par sous-chaîne (PartialSearchFilter), hors énumérations.
		 * `decimal` est numérique ; `text` est exclu de tout filtre (SKIP_TYPES).
		 *
		 * @var list<string>
		 */
		private const array PARTIAL_TYPES = ['string', 'ascii_string'];

		/** Valeur d'un status sans énumération dans les tests générés (convention des applications EasyTax). */
		private const string DEFAULT_STATUS_SAMPLE = 'STATUS_ACTIVATE';

		/** Valeur d'exemple d'un UUID (colonne uuid, paramètre realm) dans les tests générés. */
		private const string SAMPLE_UUID = '00000000-0000-7000-8000-000000000000';

		/**
		 * Classe de filtre (nom court) associée à chaque kind à propriété unique.
		 *
		 * @var array<string, string>
		 */
		private const array KIND_TO_FILTER = [
			'partial' => 'PartialSearchFilter',
			'exact'   => 'ExactFilter',
			'uuid'    => 'UuidFilter',
			'date'    => 'DateFilter',
			'iri'     => 'IriFilter',
		];

		/* ── Classification ─── */

		/*
		 * Classifie les champs de l'entité et retourne les définitions de paramètres.
		 *
		 * Ordre de sortie : d'abord un paramètre par champ (dans l'ordre Doctrine : scalaires puis
		 * associations), puis le paramètre groupé 'exists[:property]', puis 'order[:property]'.
		 *
		 * Paramètre $fields : champs tels que retournés par FieldAnalyser::getEntityFields()
		 * (clés utilisées : doctrineType, nullable, isRelation, isToMany).
		 *
		 * Retour : liste de définitions (liste vide si aucun champ n'est filtrable).
		 */
		/**
		 * @param array<array-key, array<string, mixed>> $fields
		 *
		 * @return list<FilterDefinition>
		 */
		public function buildDefinitions(array $fields): array {
			$definitions    = [];
			$existsProps    = [];
			$sortProps      = [];
			$excludedFields = $this->config->filterExcludedFields ?? [];
			$sortOnToOne    = $this->config->sortOnToOneRelations ?? false;

			foreach ($fields as $fieldName => $info) {
				$fieldName = (string)$fieldName; /* les clés numériques éventuelles sont ramenées en chaîne */

				/* La clé primaire n'est jamais filtrable ni triable ; les champs exclus non plus. */
				if ($fieldName === 'id' || in_array($fieldName, $excludedFields, true)) {
					continue;
				}

				if ($info['isRelation']) {
					/* Une collection ne se filtre que par « contient-elle au moins un élément ? ». */
					if ($info['isToMany']) {
						$existsProps[] = $fieldName;
						continue;
					}

					/* relation ToOne : filtrage par IRI ; « realm » : paramètre obligatoire de l'application */
					$definitions[] = $this->single($fieldName === 'realm' ? 'realm' : 'iri', $fieldName);

					if ($info['nullable']) {
						$existsProps[] = $fieldName;
					}

					if ($sortOnToOne) {
						$sortProps[] = $fieldName;
					}

					continue;
				}

				$type = strtolower((string)$info['doctrineType']);

				/* ExistsFilter dépend de la nullabilité et non du type : test placé avant les exclusions. */
				if ($info['nullable']) {
					$existsProps[] = $fieldName;
				}

				if (in_array($type, self::SKIP_TYPES, true)) {
					continue;
				}

				$isEnum = is_array($info['enumCases'] ?? null) && $info['enumCases'] !== [];

				$kind = match (true) {
					$fieldName === 'status'                                => 'status', /* paramètres obligatoires de l'application */
					$fieldName === 'realm'                                 => 'realm',  /* colonne uuid : API qui référence le Realm d'une autre API */
					in_array($type, self::DATE_TYPES, true)                => 'date',
					$type === 'boolean'                                    => 'boolean',
					in_array($type, self::NUMERIC_TYPES, true)             => 'numeric',
					in_array($type, self::UUID_TYPES, true)                => 'uuid',
					in_array($type, self::PARTIAL_TYPES, true) && !$isEnum => 'partial', /* énumération : valeur fermée, égalité stricte */
					default                                                => 'exact',
				};

				$definition = $this->single($kind, $fieldName);

				/* Énumération : l'exemple de test doit être une valeur autorisée (sinon la requête est rejetée). */
				if (($kind === 'exact' || $kind === 'status') && $isEnum) {
					$definition['sample'] = (string)$info['enumCases'][0];
				}
				elseif ($kind === 'status') {
					$definition['sample'] = self::DEFAULT_STATUS_SAMPLE;
				}

				$definitions[] = $definition;
				$sortProps[]   = $fieldName;
			}

			if ($existsProps !== []) {
				$definitions[] = ['kind' => 'exists', 'key' => 'exists[:property]', 'property' => null, 'properties' => $existsProps];
			}

			if ($sortProps !== []) {
				$definitions[] = ['kind' => 'sort', 'key' => 'order[:property]', 'property' => null, 'properties' => $sortProps];
			}

			return $definitions;
		}

		/* ── Rendu PHP ─── */

		/*
		 * Rend les définitions en lignes de code PHP, prêtes à être placées dans le tableau retourné par
		 * `parameters()` d'un catalogue. Chaque entrée se termine par une virgule.
		 *
		 * Paramètre $definitions : définitions issues de buildDefinitions().
		 * Paramètre $indent      : indentation ajoutée devant chaque ligne.
		 *
		 * Retour : liste de lignes (sans fin de ligne).
		 */
		/**
		 * @param list<FilterDefinition> $definitions
		 *
		 * @return list<string>
		 */
		public function renderParameters(array $definitions, string $indent = ''): array {
			$lines = [];

			foreach ($definitions as $definition) {
				$key  = $this->quote($definition['key']);
				$prop = $definition['property'] !== null ? $this->quote($definition['property']) : '';

				$block = match ($definition['kind']) {
					'status'                                  => ["{$key} => new {$this->shortName($this->statusClass())}(required: true),"],
					'realm'                                   => ["{$key} => new {$this->shortName($this->realmClass())}(required: true),"],
					'partial', 'exact', 'uuid', 'date', 'iri' => [
						"{$key} => new QueryParameter(description: '', filter: new " . self::KIND_TO_FILTER[$definition['kind']] . "(), property: {$prop}),",
					],
					'boolean'                                 => [
						"{$key} => new QueryParameter(",
						"    description: '',", "    schema: ['type' => 'boolean'],", '    filter: new ExactFilter(),',
						"    property: {$prop},", '    castToArray: false,', '    castToNativeType: true,',
						'),',
					],
					'numeric'                                 => [
						"{$key} => new QueryParameter(",
						"    description: '',", '    filter: new ChainFilter([new ExactFilter(), new ComparisonFilter(new ExactFilter())]),',
						"    property: {$prop},",
						'),',
					],
					'exists', 'sort'                          => [
						"{$key} => new QueryParameter(",
						"    description: '',", '    filter: new ' . ($definition['kind'] === 'exists' ? 'ExistsFilter' : 'SortFilter') . '(),',
						'    properties: [' . implode(', ', array_map($this->quote(...), $definition['properties'])) . '],',
						'),',
					],
					default                                   => [],
				};

				foreach ($block as $line) {
					$lines[] = $indent . $line;
				}
			}

			return $lines;
		}

		/*
		 * Retourne les FQCN à importer pour que le code rendu par renderParameters() soit valide.
		 *
		 * Liste vide si aucune définition : aucun import de filtre ni de QueryParameter n'est alors
		 * nécessaire (déduplication et tri inclus). QueryParameter n'est importé que si une définition le
		 * construit : les paramètres status et realm sont des classes de l'application.
		 */
		/**
		 * @param list<FilterDefinition> $definitions
		 *
		 * @return list<string>
		 */
		public function requiredFqcns(array $definitions): array {
			if ($definitions === []) {
				return [];
			}

			$ns    = 'ApiPlatform\\Doctrine\\Orm\\Filter\\';
			$fqcns = [];

			foreach ($definitions as $definition) {
				if (!in_array($definition['kind'], ['status', 'realm'], true)) {
					$fqcns[] = 'ApiPlatform\\Metadata\\QueryParameter';
				}

				$fqcns = [
					...$fqcns, ...match ($definition['kind']) {
						'status'  => [$this->statusClass()],
						'realm'   => [$this->realmClass()],
						'partial' => [$ns . 'PartialSearchFilter'],
						'exact'   => [$ns . 'ExactFilter'],
						'uuid'    => [$ns . 'UuidFilter'],
						'date'    => [$ns . 'DateFilter'],
						'iri'     => [$ns . 'IriFilter'],
						'boolean' => [$ns . 'ExactFilter'],
						'numeric' => [$ns . 'ChainFilter', $ns . 'ComparisonFilter', $ns . 'ExactFilter'],
						'exists'  => [$ns . 'ExistsFilter'],
						'sort'    => [$ns . 'SortFilter'],
						default   => [],
					},
				];
			}

			$fqcns = array_values(array_unique($fqcns));
			sort($fqcns);

			return $fqcns;
		}

		/* ── Exemples de requêtes (tests fonctionnels générés) ─── */

		/*
		 * Produit des exemples de chaînes de requête, valides et invalides, pour les tests fonctionnels.
		 *
		 * Les requêtes sont retournées sous forme de tableaux PHP (option 'query' du client HTTP de test),
		 * ce qui évite d'encoder les crochets à la main : ['exists' => ['deletedAt' => 'true']] devient
		 * exists[deletedAt]=true.
		 *
		 * Les relations ToOne ne sont pas testées : elles exigent une IRI existante.
		 *
		 * Retour : ['valid' => list<array{label: string, query: array}>, 'invalid' => list<array{label: string, query: array}>]
		 */
		/**
		 * @param list<FilterDefinition> $definitions
		 *
		 * @return array{valid: list<QueryExample>, invalid: list<QueryExample>}
		 */
		public function buildQueryExamples(array $definitions): array {
			$valid   = [];
			$invalid = [];

			foreach ($definitions as $definition) {
				$prop = (string)$definition['property'];

				switch ($definition['kind']) {
					case 'partial':
						$valid[] = ['label' => "partial {$prop}", 'query' => [$prop => 'test']];
						break;
					case 'exact':
						$valid[] = ['label' => "exact {$prop}", 'query' => [$prop => $definition['sample'] ?? 'test']];
						break;
					case 'uuid':
						$valid[] = ['label' => "uuid {$prop}", 'query' => [$prop => self::SAMPLE_UUID]];
						break;
					case 'boolean':
						$valid[] = ['label' => "boolean {$prop}", 'query' => [$prop => 'true']];
						break;
					case 'numeric':
						$valid[] = ['label' => "numeric {$prop} égalité", 'query' => [$prop => '1']];
						$valid[] = ['label' => "numeric {$prop} gte", 'query' => [$prop => ['gte' => '1']]];
						$valid[] = ['label' => "numeric {$prop} lte", 'query' => [$prop => ['lte' => '1000000']]];
						break;
					case 'date':
						$valid[] = ['label' => "date {$prop} after", 'query' => [$prop => ['after' => '2020-01-01']]];
						$valid[] = ['label' => "date {$prop} before", 'query' => [$prop => ['before' => '2100-01-01']]];
						break;
					case 'exists':
						foreach ($definition['properties'] as $name) {
							$valid[] = ['label' => "exists {$name}", 'query' => ['exists' => [$name => 'true']]];
						}
						break;
					case 'sort':
						foreach ($definition['properties'] as $name) {
							$valid[] = ['label' => "order {$name} asc", 'query' => ['order' => [$name => 'asc']]];
						}

						/* SortFilter valide la direction (422) là où OrderFilter l'ignorait silencieusement. */
						$invalid[] = ['label' => "order {$definition['properties'][0]} direction invalide", 'query' => ['order' => [$definition['properties'][0] => 'sideways']]];
						break;
					default:
						break; /* iri : pas d'exemple, une IRI existante est nécessaire ; status et realm : voir buildRequiredQuery() */
				}
			}

			return compact('valid', 'invalid');
		}

		/*
		 * Paramètres OBLIGATOIRES des requêtes de collection (status, realm) : sans eux, toute requête de
		 * collection est rejetée en 422. Les tests générés les ajoutent à chaque requête.
		 *
		 * Retour : [nom du paramètre => valeur d'exemple], vide si la collection n'en exige aucun.
		 */
		/**
		 * @param list<FilterDefinition> $definitions
		 *
		 * @return array<string, string>
		 */
		public function buildRequiredQuery(array $definitions): array {
			$required = [];

			foreach ($definitions as $definition) {
				$prop = (string)$definition['property'];

				match ($definition['kind']) {
					'status' => $required[$prop] = $definition['sample'] ?? self::DEFAULT_STATUS_SAMPLE,
					'realm'  => $required[$prop] = self::SAMPLE_UUID,
					default  => null,
				};
			}

			return $required;
		}

		/* ── Utilitaires ─── */

		/* FQCN du paramètre « status » de l'application (celui de la configuration, 'App\System\Filter\StatusParameter' sans configuration). */
		private function statusClass(): string {
			return $this->config->statusParameterClass ?? 'App\\System\\Filter\\StatusParameter';
		}

		/* FQCN du paramètre « realm » de l'application (celui de la configuration, 'App\System\Filter\RealmParameter' sans configuration). */
		private function realmClass(): string {
			return $this->config->realmParameterClass ?? 'App\\System\\Filter\\RealmParameter';
		}

		/* Nom court d'un FQCN : 'App\System\Filter\StatusParameter' → 'StatusParameter'. */
		private function shortName(string $fqcn): string {
			return substr($fqcn, (int)strrpos($fqcn, '\\') + 1);
		}

		/*
		 * Fabrique une définition à propriété unique dont la clé est le nom de la propriété.
		 */
		/**
		 * @return FilterDefinition
		 */
		private function single(string $kind, string $fieldName): array {
			return ['kind' => $kind, 'key' => $fieldName, 'property' => $fieldName, 'properties' => []];
		}

		/*
		 * Retourne la chaîne PHP littérale (entre apostrophes) correspondant à $value.
		 */
		private function quote(string $value): string {
			return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
		}
	}
