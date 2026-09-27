<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Classification des champs Doctrine en paramètres API Platform (QueryParameter).
     */

    declare(strict_types=1);

    namespace SocioLink\ApiResourceBundle\Source;

    /**
     * Transforme les champs d'une entité Doctrine en DÉFINITIONS de paramètres API Platform
     * (#[QueryParameter]) puis les rend en code PHP.
     *
     * Remplace la génération historique des attributs #[ApiFilter] (dépréciés depuis API Platform 4.4,
     * supprimés en 6.0). La classification est une fonction pure (aucun accès disque, aucune
     * dépendance Doctrine) : elle est donc testable seule, indépendamment de l'injecteur.
     *
     * Correspondance avec le contrat d'URL historique (voir migration-audit.md, annexe 12) :
     *
     *  - string, énumération, type personnalisé : ExactFilter                     ?name=Chair
     *  - uuid / guid (hors id)                  : UuidFilter                      ?ref=<uuid>
     *  - boolean                                : ExactFilter (schéma booléen)    ?active=true
     *  - integer / float / decimal              : ChainFilter(Exact + Comparison) ?price=25 · ?price[gte]=10
     *  - date / heure                           : DateFilter (conservé)           ?createdAt[after]=2026-01-01
     *  - relation ToOne                         : IriFilter                       ?author=/api/authors/{uuid}
     *  - relation ToMany / champ nullable       : ExistsFilter                    ?exists[deletedAt]=true
     *  - tri                                    : SortFilter                      ?order[name]=asc
     *
     * Structure d'une définition :
     *  - 'kind'       : exact | uuid | boolean | numeric | date | iri | exists | sort
     *  - 'key'        : clé du paramètre = nom d'URL (peut contenir le placeholder :property)
     *  - 'property'   : propriété ciblée (kinds à propriété unique), null sinon
     *  - 'properties' : propriétés ciblées (exists / sort), liste vide sinon
     *  - 'sample'     : valeur d'exemple pour les tests générés (première valeur d'une énumération), facultative
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
        public function __construct(private ?GeneratorConfig $config = null) {}

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
         * Classe de filtre (nom court) associée à chaque kind à propriété unique.
         *
         * @var array<string, string>
         */
        private const array KIND_TO_FILTER = [
            'exact' => 'ExactFilter',
            'uuid'  => 'UuidFilter',
            'date'  => 'DateFilter',
            'iri'   => 'IriFilter',
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

                    $definitions[] = $this->single('iri', $fieldName); /* relation ToOne : filtrage par IRI */

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

                $kind = match (true) {
                    in_array($type, self::DATE_TYPES, true)    => 'date',
                    $type === 'boolean'                        => 'boolean',
                    in_array($type, self::NUMERIC_TYPES, true) => 'numeric',
                    in_array($type, self::UUID_TYPES, true)    => 'uuid',
                    default                                    => 'exact',
                };

                $definition = $this->single($kind, $fieldName);

                /* Énumération : l'exemple de test doit être une valeur autorisée (sinon la requête est rejetée). */
                if ($kind === 'exact' && is_array($info['enumCases'] ?? null) && $info['enumCases'] !== []) {
                    $definition['sample'] = (string)$info['enumCases'][0];
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
         * Rend les définitions en lignes de code PHP, prêtes à être placées dans un tableau
         * `parameters: [ ... ]`. Chaque entrée se termine par une virgule.
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
                    'exact', 'uuid', 'date', 'iri' => [
                        "{$key} => new QueryParameter(filter: new " . self::KIND_TO_FILTER[$definition['kind']] . "(), property: {$prop}),",
                    ],
                    'boolean'                      => [
                        "{$key} => new QueryParameter(",
                        '    filter: new ExactFilter(),',
                        "    property: {$prop},",
                        "    schema: ['type' => 'boolean'],",
                        '    castToNativeType: true,',
                        '    castToArray: false,',
                        '),',
                    ],
                    'numeric'                      => [
                        "{$key} => new QueryParameter(",
                        '    filter: new ChainFilter([new ExactFilter(), new ComparisonFilter(new ExactFilter())]),',
                        "    property: {$prop},",
                        '),',
                    ],
                    'exists', 'sort'               => [
                        "{$key} => new QueryParameter(",
                        '    filter: new ' . ($definition['kind'] === 'exists' ? 'ExistsFilter' : 'SortFilter') . '(),',
                        '    properties: [' . implode(', ', array_map($this->quote(...), $definition['properties'])) . '],',
                        '),',
                    ],
                    default                        => [],
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
         * nécessaire (déduplication et tri inclus).
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
            $fqcns = ['ApiPlatform\\Metadata\\QueryParameter'];

            foreach ($definitions as $definition) {
                $fqcns = [...$fqcns, ...match ($definition['kind']) {
                    'exact'   => [$ns . 'ExactFilter'],
                    'uuid'    => [$ns . 'UuidFilter'],
                    'date'    => [$ns . 'DateFilter'],
                    'iri'     => [$ns . 'IriFilter'],
                    'boolean' => [$ns . 'ExactFilter'],
                    'numeric' => [$ns . 'ChainFilter', $ns . 'ComparisonFilter', $ns . 'ExactFilter'],
                    'exists'  => [$ns . 'ExistsFilter'],
                    'sort'    => [$ns . 'SortFilter'],
                    default   => [],
                }];
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
                    case 'exact':
                        $valid[] = ['label' => "exact {$prop}", 'query' => [$prop => $definition['sample'] ?? 'test']];
                        break;
                    case 'uuid':
                        $valid[] = ['label' => "uuid {$prop}", 'query' => [$prop => '00000000-0000-7000-8000-000000000000']];
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
                        break; /* iri : pas d'exemple, une IRI existante est nécessaire */
                }
            }

            return ['valid' => $valid, 'invalid' => $invalid];
        }

        /* ── Utilitaires ─── */

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
