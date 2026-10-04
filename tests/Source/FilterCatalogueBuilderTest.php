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
	 * Creation Date: 04/10/2026
	 *
	 * Description: Tests du catalogue de filtres généré (<Entité>Filters) : espace de noms et chemin selon le sous-espace de
	 *              l'entité, imports, contrat implémenté, paramètres status et realm de l'application, code PHP valide,
	 *              absence de catalogue sans champ filtrable.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Tests\Source;

	use PHPUnit\Framework\TestCase;
	use SocioLink\ApiResourceBundle\Twig\TwigEnvironmentFactory;
	use SocioLink\ApiResourceBundle\Source\GeneratorConfig;
	use SocioLink\ApiResourceBundle\Source\NamespaceResolver;
	use SocioLink\ApiResourceBundle\Source\FilterCatalogueBuilder;
	use SocioLink\ApiResourceBundle\Source\FilterDefinitionBuilder;

	final class FilterCatalogueBuilderTest extends TestCase {
		/** @return array<string, mixed> */
		private function field(string $type, bool $nullable = false, bool $relation = false, bool $toMany = false): array {
			return [
				'doctrineType' => $type, 'nullable' => $nullable, 'isRelation' => $relation,
				'isToMany'     => $toMany, 'isOneToMany' => $toMany, 'length' => null,
			];
		}

		/** @return array<string, array<string, mixed>> */
		private function fields(): array {
			return [
				'id'       => $this->field('uuid'),
				'headLine' => $this->field('string'),
				'status'   => $this->field('string'),
				'realm'    => $this->field('App\Entity\Setup\Realm', relation: true),
			];
		}

		private function builder(GeneratorConfig $config): FilterCatalogueBuilder {
			$resolver = new NamespaceResolver($config);

			return new FilterCatalogueBuilder(
				$resolver, new FilterDefinitionBuilder($config), $config, TwigEnvironmentFactory::create(dirname(__DIR__, 2) . '/templates', $config),
			);
		}

		private function assertValidPhp(string $code): void {
			/* TOKEN_PARSE lève une ParseError si le code généré est syntaxiquement invalide. */
			$this->assertNotEmpty(token_get_all($code, TOKEN_PARSE));
		}

		public function testCatalogueOfAnEntityInASubNamespaceFollowsTheApplicationConventions(): void {
			$config = new GeneratorConfig('/projet');
			$code   = $this->builder($config)->build('App\Entity\Section\Ministry', $this->fields());

			$this->assertNotNull($code);
			$this->assertValidPhp($code);
			$this->assertStringContainsString('namespace App\Filters\Section;', $code);
			$this->assertStringContainsString('final class MinistryFilters implements CollectionFiltersInterface', $code);
			$this->assertStringContainsString('use App\Filters\CollectionFiltersInterface;', $code);
			$this->assertStringContainsString('use App\System\Filter\StatusParameter;', $code);
			$this->assertStringContainsString('use App\System\Filter\RealmParameter;', $code);
			$this->assertStringContainsString("'headLine' => new QueryParameter(filter: new PartialSearchFilter(), property: 'headLine'),", $code);
			$this->assertStringContainsString("'status' => new StatusParameter(required: true),", $code);
			$this->assertStringContainsString("'realm' => new RealmParameter(required: true),", $code);
			$this->assertStringContainsString("'order[:property]' => new QueryParameter(", $code);
			$this->assertStringContainsString('public function parameters(): array', $code);
		}

		public function testImportsAreSortedAndTheSameNamespaceIsNotImported(): void {
			$code = $this->builder(new GeneratorConfig('/projet'))->build('App\Entity\Ministry', $this->fields());

			$this->assertNotNull($code);
			$this->assertValidPhp($code);

			/* Entité sans sous-espace : catalogue dans App\Filters, comme le contrat, qui n'a donc pas à être importé. */
			$this->assertStringContainsString('namespace App\Filters;', $code);
			$this->assertStringNotContainsString('use App\Filters\CollectionFiltersInterface;', $code);

			preg_match_all('/^use (.+);$/m', $code, $matches);
			$sorted = $matches[1];
			sort($sorted);
			$this->assertSame($sorted, $matches[1]);
		}

		public function testApplicationClassesAndNamespaceAreConfigurable(): void {
			$config = new GeneratorConfig(
				'/projet', filterNamespace: 'Api\Filter', filterInterface: 'Api\Contract\Catalogue', statusParameter: 'Api\Param\Status', realmParameter: 'Api\Param\Realm',
			);
			$code   = $this->builder($config)->build('App\Entity\Blog\Article', $this->fields());

			$this->assertNotNull($code);
			$this->assertValidPhp($code);
			$this->assertStringContainsString('namespace App\Api\Filter\Blog;', $code);
			$this->assertStringContainsString('final class ArticleFilters implements Catalogue', $code);
			$this->assertStringContainsString('use App\Api\Contract\Catalogue;', $code);
			$this->assertStringContainsString("'status' => new Status(required: true),", $code);
			$this->assertStringContainsString("'realm' => new Realm(required: true),", $code);
		}

		public function testEntityWithoutFilterableFieldHasNoCatalogue(): void {
			$this->assertNull($this->builder(new GeneratorConfig('/projet'))->build('App\Entity\Article', ['id' => $this->field('uuid')]));
		}

		public function testResolverDerivesClassNamespaceAndPathFromTheEntitySubNamespace(): void {
			$resolver = new NamespaceResolver(new GeneratorConfig('/projet'));

			$this->assertSame('App\Filters\Section\MinistryFilters', $resolver->getFilterClass('App\Entity\Section\Ministry'));
			$this->assertSame('App\Filters\Section', $resolver->getFilterNamespace('App\Entity\Section\Ministry'));
			$this->assertSame('/projet/src/Filters/Section/MinistryFilters.php', $resolver->filterFilePath('App\Entity\Section\Ministry'));
			$this->assertSame('/projet/src/Filters/ArticleFilters.php', $resolver->filterFilePath('App\Entity\Article'));
		}
	}
