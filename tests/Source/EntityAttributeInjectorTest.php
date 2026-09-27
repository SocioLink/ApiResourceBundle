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
	 * Description: Tests d'intégration de l'injecteur d'attributs (EntityAttributeInjector) sur de vrais fichiers d'entité
	 *              écrits dans un dossier temporaire.
	 *              Couvrent le rendu des paramètres #[QueryParameter], le placement et l'indentation du bloc, les options
	 *              GraphQL et Mercure, les modes sans écriture, la migration des entités portant d'anciens #[ApiFilter] (y
	 *              compris des parenthèses dans les chaînes), l'idempotence, les fins de ligne CRLF, les sous-ressources et
	 *              la conservation des éléments écrits à la main.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Tests\Source;

	use PHPUnit\Framework\TestCase;
	use Symfony\Component\Console\Input\ArrayInput;
	use Symfony\Component\Console\Style\SymfonyStyle;
	use Symfony\Component\Console\Output\BufferedOutput;
	use SocioLink\ApiResourceBundle\Source\GeneratorConfig;
	use SocioLink\ApiResourceBundle\Source\NamespaceResolver;
	use SocioLink\ApiResourceBundle\Source\GenerationOptions;
	use SocioLink\ApiResourceBundle\Source\FilterDefinitionBuilder;
	use SocioLink\ApiResourceBundle\Source\EntityAttributeInjector;

	/**
	 * Vérifie le comportement observable de l'injecteur sur de vrais fichiers :
	 * rendu des paramètres, migration des entités héritées, lecture seule, fins de ligne,
	 * sous-ressources et conservation des éléments écrits à la main.
	 */
	final class EntityAttributeInjectorTest extends TestCase {
		/** Dossier projet temporaire (contient src/Entity). */
		private string $projectDir;

		private EntityAttributeInjector $injector;

		private SymfonyStyle $io;

		private BufferedOutput $output;

		/* ── Préparation ─── */

		protected function setUp(): void {
			$this->projectDir = str_replace('\\', '/', sys_get_temp_dir()) . '/generate_resource_' . bin2hex(random_bytes(6));
			mkdir($this->projectDir . '/src/Entity', 0777, true);

			$config         = new GeneratorConfig($this->projectDir);
			$this->injector = new EntityAttributeInjector(new NamespaceResolver($config), new FilterDefinitionBuilder(), $config);
			$this->output   = new BufferedOutput();
			$this->io       = new SymfonyStyle(new ArrayInput([]), $this->output);
		}

		protected function tearDown(): void {
			foreach (glob($this->projectDir . '/src/Entity/*') ?: [] as $file) {
				unlink($file);
			}

			rmdir($this->projectDir . '/src/Entity');
			rmdir($this->projectDir . '/src');
			rmdir($this->projectDir);
		}

		/* ── Fabriques ─── */

		private function field(string $type, bool $nullable = false, bool $relation = false, bool $toMany = false): array {
			return [
				'doctrineType' => $type, 'nullable' => $nullable, 'isRelation' => $relation,
				'isToMany'     => $toMany, 'isOneToMany' => $toMany, 'length' => null,
			];
		}

		private function articleFields(): array {
			return [
				'id'        => $this->field('uuid'),
				'name'      => $this->field('string'),
				'active'    => $this->field('boolean'),
				'price'     => $this->field('decimal'),
				'createdAt' => $this->field('datetime_immutable'),
				'deletedAt' => $this->field('datetime_immutable', nullable: true),
				'author'    => $this->field('App\Entity\Author', relation: true),
				'comments'  => $this->field('App\Entity\Comment', relation: true, toMany: true),
			];
		}

		/* Écrit un fichier d'entité et retourne son chemin. */
		private function writeEntity(string $name, string $source): string {
			$path = $this->projectDir . "/src/Entity/{$name}.php";
			file_put_contents($path, $source);

			return $path;
		}

		/* Entité vierge, au style du projet (corps indenté d'un niveau supplémentaire). */
		private function plainEntity(string $name = 'Article'): string {
			return "<?php\n\n    namespace App\\Entity;\n\n    use Doctrine\\ORM\\Mapping as ORM;\n\n"
			       . "    #[ORM\\Entity]\n    final class {$name} {\n        private string \$name = '';\n    }\n";
		}

		/* Entité générée avec l'ancien format (#[ApiFilter] de classe), avec des éléments écrits à la main. */
		private function legacyEntity(): string {
			return <<<'PHP'
<?php

    namespace App\Entity;

    use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
    use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
    use ApiPlatform\Metadata\ApiFilter;
    use ApiPlatform\Metadata\ApiProperty;
    use ApiPlatform\Metadata\ApiResource;
    use ApiPlatform\Metadata\Get;
    use ApiPlatform\Metadata\GetCollection;
    use Doctrine\ORM\Mapping as ORM;

    #[ORM\Entity]
    #[ApiResource(
        operations: [new Get(), new GetCollection()],
        description: 'Article (avec une parenthèse fermante : ) dans la chaîne',
    )]
    #[ApiFilter(SearchFilter::class, properties: ['name' => 'exact'])]
    #[ApiFilter(OrderFilter::class, properties: ['name'])]
    final class Article {
        #[ApiProperty(description: 'Titre')]
        #[ApiFilter(SearchFilter::class, strategy: 'partial')]
        private string $name = '';
    }

PHP;
		}

		private function inject(string $name, array $fields, GenerationOptions $options): string {
			return $this->injector->injectAttributesIntoEntity(
				entityClass: "App\\Entity\\{$name}", entityName: $name, fields: $fields,
				dtoNs      : "App\\DTO\\{$name}", stateNs: "App\\State\\{$name}", options: $options, io: $this->io,
				allBooleanFields: [], uploadFields: [],
			);
		}

		private function injectSub(GenerationOptions $options, array $childFields): string {
			return $this->injector->injectSubResourceAttributes(
				parentClass: 'App\Entity\Article', parentName: 'Article', targetClass: 'App\Entity\Comment',
				targetName : 'Comment', fieldName: 'comments', mappedBy: 'article',
				options    : $options, io: $this->io, targetFields: $childFields,
			);
		}

		/* Vérifie que le fichier est du PHP syntaxiquement valide (lève une ParseError sinon). */
		private function assertValidPhp(string $path): void {
			$this->assertNotEmpty(token_get_all((string)file_get_contents($path), TOKEN_PARSE));
		}

		/* ── Rendu des paramètres ─── */

		public function testFreshEntityReceivesQueryParametersOnGetCollection(): void {
			$path   = $this->writeEntity('Article', $this->plainEntity());
			$result = $this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags());
			$source = (string)file_get_contents($path);

			$this->assertSame($path, $result);
			$this->assertValidPhp($path);
			$this->assertStringContainsString('new GetCollection(', $source);
			$this->assertStringContainsString('parameters: [', $source);
			$this->assertStringContainsString("'exists[:property]' => new QueryParameter(", $source);
			$this->assertStringContainsString("'order[:property]' => new QueryParameter(", $source);
			$this->assertStringContainsString('use ApiPlatform\Metadata\QueryParameter;', $source);
			$this->assertStringContainsString('use ApiPlatform\Doctrine\Orm\Filter\SortFilter;', $source);
			$this->assertStringNotContainsString('ApiFilter', $source);
			$this->assertSame(1, substr_count($source, '#[ApiResource('));
		}

		public function testEntityWithoutFilterableFieldKeepsCompactOperationsLine(): void {
			$path = $this->writeEntity('Article', $this->plainEntity());
			$this->inject('Article', ['id' => $this->field('uuid')], GenerationOptions::fromFlags());
			$source = (string)file_get_contents($path);

			$this->assertStringContainsString('new Get(), new GetCollection(),', $source);
			$this->assertStringNotContainsString('QueryParameter', $source);
			$this->assertValidPhp($path);
		}

		public function testAttributeBlockIsInsertedOnItsOwnLinesWithTheClassIndentation(): void {
			$path = $this->writeEntity('Article', $this->plainEntity());
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags());
			$source = (string)file_get_contents($path);

			$this->assertStringContainsString("    #[ORM\\Entity]\n    #[ApiResource(\n", $source);
			$this->assertMatchesRegularExpression('/\)\]\n    final class Article \{/', $source);
		}

		public function testGraphqlFiltersOptionCopiesParametersOntoQueryCollection(): void {
			$withPath = $this->writeEntity('Article', $this->plainEntity());
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags(graphqlFilters: true));
			$with = (string)file_get_contents($withPath);

			$this->assertMatchesRegularExpression("/new QueryCollection\(\n\s+paginationType: 'page',\n\s+parameters: \[/", $with);

			$withoutPath = $this->writeEntity('Article', $this->plainEntity());
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags(force: true));
			$without = (string)file_get_contents($withoutPath);

			$this->assertStringContainsString("new QueryCollection(paginationType: 'page')", $without);
		}

		/* ── Mercure (--with-mercure / --public) ─── */

		public function testWithoutMercureFlagNoDirectiveIsInjected(): void {
			$path = $this->writeEntity('Article', $this->plainEntity());
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags());
			$source = (string)file_get_contents($path);

			$this->assertStringNotContainsString('mercure', $source);
			$this->assertValidPhp($path);
		}

		public function testWithMercureAloneInjectsThePrivateDirective(): void {
			$path = $this->writeEntity('Article', $this->plainEntity());
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags(withMercure: true));
			$source = (string)file_get_contents($path);

			$this->assertStringContainsString("mercure: ['private' => true],", $source);
			$this->assertStringNotContainsString('mercure: true', $source);
			$this->assertValidPhp($path);
		}

		public function testWithMercureAndPublicInjectsThePublicDirective(): void {
			$path = $this->writeEntity('Article', $this->plainEntity());
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags(withMercure: true, publicMercure: true));
			$source = (string)file_get_contents($path);

			$this->assertStringContainsString('mercure: true,', $source);
			$this->assertStringNotContainsString("mercure: ['private'", $source);
			$this->assertValidPhp($path);
		}

		/* ── Lecture seule ─── */

		public function testDryRunNeverWritesTheEntityFile(): void {
			$path   = $this->writeEntity('Article', $this->plainEntity());
			$before = md5_file($path);

			$result = $this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags(dryRun: true));

			$this->assertSame($path, $result);
			$this->assertSame($before, md5_file($path));
		}

		public function testPreviewPrintsTheBlockButNeverWrites(): void {
			$path   = $this->writeEntity('Article', $this->plainEntity());
			$before = md5_file($path);

			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags(preview: true));

			$this->assertSame($before, md5_file($path));
			$this->assertStringContainsString('#[ApiResource(', $this->output->fetch());
		}

		/* ── Entités héritées (#[ApiFilter]) ─── */

		public function testLegacyEntityIsSkippedWithoutForceAndAMigrationHintIsShown(): void {
			$path   = $this->writeEntity('Article', $this->legacyEntity());
			$before = md5_file($path);

			$this->assertSame('skipped', $this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags()));
			$this->assertSame($before, md5_file($path));
			$this->assertStringContainsString('api:upgrade-filter', $this->output->fetch());
		}

		public function testForceMigratesLegacyEntityAndKeepsHandWrittenElements(): void {
			$path = $this->writeEntity('Article', $this->legacyEntity());
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags(force: true));
			$source = (string)file_get_contents($path);

			$this->assertValidPhp($path);

			/* Plus aucun #[ApiFilter] de classe, ni d'import de filtre orphelin. */
			$this->assertStringNotContainsString("#[ApiFilter(OrderFilter", $source);
			$this->assertStringNotContainsString('use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;', $source);
			$this->assertSame(1, substr_count($source, '#[ApiResource('));
			$this->assertStringContainsString('new QueryParameter(', $source);

			/* Éléments écrits à la main : #[ApiProperty], #[ApiFilter] de propriété et les imports qu'ils utilisent. */
			$this->assertStringContainsString("#[ApiProperty(description: 'Titre')]", $source);
			$this->assertStringContainsString("#[ApiFilter(SearchFilter::class, strategy: 'partial')]", $source);
			$this->assertStringContainsString('use ApiPlatform\Metadata\ApiProperty;', $source);
			$this->assertStringContainsString('use ApiPlatform\Metadata\ApiFilter;', $source);
			$this->assertStringContainsString('use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;', $source);
		}

		public function testParenthesisInsideAStringDoesNotBreakTheRemoval(): void {
			$path = $this->writeEntity('Article', $this->legacyEntity());
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags(force: true));
			$source = (string)file_get_contents($path);

			/* La description du #[ApiResource] hérité contenait une « ) » : rien ne doit en subsister. */
			$this->assertStringNotContainsString('parenthèse fermante', $source);
			$this->assertValidPhp($path);
		}

		public function testRegenerationIsIdempotent(): void {
			$path = $this->writeEntity('Article', $this->plainEntity());
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags());
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags(force: true));
			$first = (string)file_get_contents($path);
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags(force: true));

			$this->assertSame($first, (string)file_get_contents($path));
			$this->assertSame(1, substr_count($first, '#[ApiResource('));
		}

		/* ── Fins de ligne ─── */

		public function testCrlfEntityStaysCrlfAndOrphanImportsAreRemoved(): void {
			$path = $this->writeEntity('Article', str_replace("\n", "\r\n", $this->legacyEntity()));
			$this->inject('Article', $this->articleFields(), GenerationOptions::fromFlags(force: true));
			$source = (string)file_get_contents($path);

			$this->assertValidPhp($path);
			$this->assertSame(0, preg_match('/(?<!\r)\n/', $source), 'Fin de ligne LF isolée dans un fichier CRLF.');
			$this->assertStringNotContainsString('use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;', $source);
		}

		/* ── Sous-ressources ─── */

		public function testSubResourceInjectedFirstDoesNotBlockTheChildMainResource(): void {
			$path = $this->writeEntity('Comment', $this->plainEntity('Comment'));

			$this->assertSame($path, $this->injectSub(GenerationOptions::fromFlags(), [
				'id' => $this->field('uuid'), 'body' => $this->field('string'), 'article' => $this->field('App\Entity\Article', relation: true),
			]));

			/* Sans le correctif, l'enfant contenant déjà un #[ApiResource] était ignoré (skipped). */
			$this->assertSame($path, $this->inject('Comment', ['id' => $this->field('uuid'), 'body' => $this->field('string')], GenerationOptions::fromFlags()));

			$source = (string)file_get_contents($path);
			$this->assertSame(2, substr_count($source, '#[ApiResource('));
			$this->assertStringContainsString("uriTemplate: '/articles/{articlesId}/comments'", $source);
			$this->assertStringContainsString('operations: [', $source);
			$this->assertValidPhp($path);
		}

		public function testSubResourceInjectedAfterTheChildMainResourceIsPlacedAfterIt(): void {
			$path = $this->writeEntity('Comment', $this->plainEntity('Comment'));
			$this->inject('Comment', ['id' => $this->field('uuid'), 'body' => $this->field('string')], GenerationOptions::fromFlags());
			$this->injectSub(GenerationOptions::fromFlags(), ['id' => $this->field('uuid'), 'body' => $this->field('string')]);

			$source = (string)file_get_contents($path);
			$this->assertSame(2, substr_count($source, '#[ApiResource('));
			$this->assertMatchesRegularExpression('/\)\]\n    #\[ApiResource\(\n        uriTemplate: /', $source);
			$this->assertValidPhp($path);
		}

		public function testForceOnTheChildKeepsTheSubResource(): void {
			$path = $this->writeEntity('Comment', $this->plainEntity('Comment'));
			$this->injectSub(GenerationOptions::fromFlags(), ['id' => $this->field('uuid'), 'body' => $this->field('string')]);
			$this->inject('Comment', ['id' => $this->field('uuid'), 'body' => $this->field('string')], GenerationOptions::fromFlags());
			$this->inject('Comment', ['id' => $this->field('uuid'), 'body' => $this->field('string')], GenerationOptions::fromFlags(force: true));

			$source = (string)file_get_contents($path);
			$this->assertSame(2, substr_count($source, '#[ApiResource('), 'La sous-ressource ne doit pas être supprimée par --force sur l\'enfant.');
			$this->assertStringContainsString("uriTemplate: '/articles/{articlesId}/comments'", $source);
			$this->assertStringContainsString('use ApiPlatform\Metadata\Link;', $source);
		}

		public function testSubResourceReusesChildParametersAndIsReplacedOnlyWithForce(): void {
			$path = $this->writeEntity('Comment', $this->plainEntity('Comment'));
			$this->injectSub(GenerationOptions::fromFlags(), ['id' => $this->field('uuid'), 'body' => $this->field('string')]);
			$source = (string)file_get_contents($path);

			$this->assertStringContainsString("'body' => new QueryParameter(filter: new ExactFilter(), property: 'body'),", $source);
			$this->assertSame('skipped', $this->injectSub(GenerationOptions::fromFlags(), ['id' => $this->field('uuid')]));

			$this->injectSub(GenerationOptions::fromFlags(force: true), ['id' => $this->field('uuid'), 'title' => $this->field('string')]);
			$replaced = (string)file_get_contents($path);

			$this->assertSame(1, substr_count($replaced, '#[ApiResource('));
			$this->assertStringContainsString("property: 'title'", $replaced);
			$this->assertStringNotContainsString("property: 'body'", $replaced);
		}

		public function testSubResourceInDryRunDoesNotWrite(): void {
			$path   = $this->writeEntity('Comment', $this->plainEntity('Comment'));
			$before = md5_file($path);

			$this->injectSub(GenerationOptions::fromFlags(dryRun: true), ['id' => $this->field('uuid'), 'body' => $this->field('string')]);

			$this->assertSame($before, md5_file($path));
		}
	}
