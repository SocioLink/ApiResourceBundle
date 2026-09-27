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
     * Description: Tests unitaires de la classification des filtres (FilterDefinitionBuilder).
     *              Vérifient le filtre attribué à chaque catégorie de champ, l'exclusion des types non filtrables, le
     *              regroupement ExistsFilter et SortFilter, la validité syntaxique et le caractère explicite (property /
     *              properties) du code rendu, les imports requis et les exemples de requêtes utilisés par les tests
     *              générés.
     */

    declare(strict_types=1);

    namespace SocioLink\ApiResourceBundle\Tests\Source;

    use PHPUnit\Framework\TestCase;
    use SocioLink\ApiResourceBundle\Source\FilterDefinitionBuilder;

    /**
     * Vérifie le contrat de classification : quel filtre moderne pour quel type de champ,
     * et que le code rendu est du PHP syntaxiquement valide.
     */
    final class FilterDefinitionBuilderTest extends TestCase {
        /* ── Fabrique de champs ─── */

        /*
         * Fabrique le tableau de champs attendu par FilterDefinitionBuilder (format FieldAnalyser::getEntityFields()).
         */
        private function field(string $type, bool $nullable = false, bool $relation = false, bool $toMany = false): array {
            return [
                'doctrineType' => $type, 'nullable' => $nullable, 'isRelation' => $relation,
                'isToMany'     => $toMany, 'isOneToMany' => $toMany, 'length' => null,
            ];
        }

        /*
         * Jeu de champs couvrant chaque catégorie de la table de mapping.
         */
        private function fields(): array {
            return [
                'id'        => $this->field('uuid'),
                'name'      => $this->field('string'),
                'active'    => $this->field('boolean'),
                'price'     => $this->field('decimal'),
                'createdAt' => $this->field('datetime_immutable'),
                'deletedAt' => $this->field('datetime_immutable', nullable: true),
                'notes'     => $this->field('text', nullable: true),
                'payload'   => $this->field('json'),
                'file'      => $this->field('blob'),
                'reference' => $this->field('uuid'),
                'author'    => $this->field('App\Entity\Author', relation: true),
                'comments'  => $this->field('App\Entity\Comment', relation: true, toMany: true),
            ];
        }

        /* ── Classification ─── */

        public function testEachFieldKindReceivesItsModernFilter(): void {
            $definitions = new FilterDefinitionBuilder()->buildDefinitions($this->fields());
            $kinds       = array_column($definitions, 'kind', 'key');

            $this->assertSame('exact', $kinds['name']);
            $this->assertSame('boolean', $kinds['active']);
            $this->assertSame('numeric', $kinds['price']);
            $this->assertSame('date', $kinds['createdAt']);
            $this->assertSame('date', $kinds['deletedAt']);
            $this->assertSame('uuid', $kinds['reference']);
            $this->assertSame('iri', $kinds['author']);
            $this->assertSame('exists', $kinds['exists[:property]']);
            $this->assertSame('sort', $kinds['order[:property]']);
        }

        public function testExcludedTypesReceiveNoValueFilter(): void {
            $keys = array_column(new FilterDefinitionBuilder()->buildDefinitions($this->fields()), 'key');

            /* text, json et blob : aucun filtre de valeur (blob/binary étaient auparavant filtrés en « exact » par erreur). */
            $this->assertNotContains('notes', $keys);
            $this->assertNotContains('payload', $keys);
            $this->assertNotContains('file', $keys);
            $this->assertNotContains('id', $keys);
        }

        public function testExistsGroupsNullableFieldsAndCollectionsIncludingSkippedTypes(): void {
            $definitions = new FilterDefinitionBuilder()->buildDefinitions($this->fields());
            $exists      = array_values(array_filter($definitions, static fn(array $d): bool => $d['kind'] === 'exists'))[0];

            /* Un champ text nullable garde son ExistsFilter même s'il est exclu des autres filtres. */
            $this->assertSame(['deletedAt', 'notes', 'comments'], $exists['properties']);
        }

        public function testSortExcludesToOneRelationsAndExcludedTypes(): void {
            $definitions = new FilterDefinitionBuilder()->buildDefinitions($this->fields());
            $sort        = array_values(array_filter($definitions, static fn(array $d): bool => $d['kind'] === 'sort'))[0];

            $this->assertSame(['name', 'active', 'price', 'createdAt', 'deletedAt', 'reference'], $sort['properties']);
        }

        public function testEntityWithoutFilterableFieldProducesNoDefinition(): void {
            $this->assertSame([], new FilterDefinitionBuilder()->buildDefinitions(['id' => $this->field('uuid')]));
        }

        /* ── Rendu ─── */

        public function testRenderedParametersAreValidPhpAndFullyQualified(): void {
            $builder = new FilterDefinitionBuilder();
            $lines   = $builder->renderParameters($builder->buildDefinitions($this->fields()), '    ');
            $code    = "<?php\n\nreturn [\n" . implode("\n", $lines) . "\n];\n";

            /* TOKEN_PARSE lève une ParseError si le code généré est syntaxiquement invalide. */
            $this->assertNotEmpty(token_get_all($code, TOKEN_PARSE));

            $this->assertStringContainsString("'name' => new QueryParameter(filter: new ExactFilter(), property: 'name'),", $code);
            $this->assertStringContainsString("filter: new ChainFilter([new ExactFilter(), new ComparisonFilter(new ExactFilter())]),", $code);
            $this->assertStringContainsString("schema: ['type' => 'boolean'],", $code);
            $this->assertStringContainsString("'exists[:property]' => new QueryParameter(", $code);
            $this->assertStringContainsString("properties: ['deletedAt', 'notes', 'comments'],", $code);
            $this->assertStringNotContainsString('ApiFilter', $code);
        }

        public function testEveryRenderedFilterHasAnExplicitProperty(): void {
            $builder = new FilterDefinitionBuilder();
            $code    = implode("\n", $builder->renderParameters($builder->buildDefinitions($this->fields())));

            /* Rupture API Platform 4.3 : ExactFilter, IriFilter, UuidFilter… exigent `property:` (ou un placeholder + properties). */
            $this->assertSame(substr_count($code, 'new QueryParameter('), substr_count($code, 'property: ') + substr_count($code, 'properties: '));
        }

        public function testRequiredFqcnsCoverEveryUsedFilter(): void {
            $builder = new FilterDefinitionBuilder();
            $fqcns   = $builder->requiredFqcns($builder->buildDefinitions($this->fields()));

            foreach (['QueryParameter', 'ExactFilter', 'ChainFilter', 'ComparisonFilter', 'DateFilter', 'UuidFilter', 'IriFilter', 'ExistsFilter', 'SortFilter'] as $short) {
                $this->assertTrue(
                    count(array_filter($fqcns, static fn(string $f): bool => str_ends_with($f, '\\' . $short))) === 1,
                    "Import manquant ou en double : {$short}",
                );
            }

            $this->assertSame([], $builder->requiredFqcns([]));
        }

        /* ── Exemples de requêtes ─── */

        public function testQueryExamplesUseArrayQueryAndDeclareInvalidSortDirection(): void {
            $builder  = new FilterDefinitionBuilder();
            $examples = $builder->buildQueryExamples($builder->buildDefinitions($this->fields()));
            $queries  = array_column($examples['valid'], 'query', 'label');

            $this->assertSame(['exists' => ['deletedAt' => 'true']], $queries['exists deletedAt']);
            $this->assertSame(['price' => ['gte' => '1']], $queries['numeric price gte']);
            $this->assertSame(['createdAt' => ['after' => '2020-01-01']], $queries['date createdAt after']);
            $this->assertSame(['order' => ['name' => 'sideways']], $examples['invalid'][0]['query']);
        }
    }
