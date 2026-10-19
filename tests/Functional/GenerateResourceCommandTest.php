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
	 * Creation Date: 27/09/2026
	 *
	 * Description: Test de bout en bout du bundle : enregistrement dans un noyau Symfony, exécution de generate:resource
	 *              sur de vraies entités Doctrine d'un projet temporaire.
	 *              Scénarios : --with-provider --with-tests --sub-resources --with-mercure --graphql-filters,
	 *              --toggle-boolean, --detach-boolean, --only-resource, --force --reinit, --dry-run sur toutes les entités,
	 *              ainsi que les cas d'échec (argument absent hors terminal, entité inconnue, options incompatibles).
	 *              Chaque classe générée est chargée (syntaxe, imports, signatures des interfaces API Platform) et mise en
	 *              forme contrôlée ; chaque #[ApiResource] injecté est instancié avec les classes réelles d'API Platform.
	 *              SOCIOLINK_E2E_KEEP=1 conserve le projet généré pour inspection.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Tests\Functional;

	use ReflectionClass;
	use RecursiveIteratorIterator;
	use RecursiveDirectoryIterator;
	use libphonenumber\PhoneNumber;
	use libphonenumber\PhoneNumberUtil;
	use libphonenumber\PhoneNumberFormat;
	use PHPUnit\Framework\TestCase;
	use ApiPlatform\Metadata\ApiResource;
	use ApiPlatform\Metadata\QueryParameter;
	use Symfony\Component\Filesystem\Filesystem;
	use Symfony\Component\Console\Command\Command;
	use Symfony\Bundle\FrameworkBundle\Console\Application;
	use Symfony\Component\Console\Tester\CommandTester;
	use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

	/**
	 * Enregistre le bundle dans un noyau Symfony, exécute `generate:resource` dans chaque mode sur un
	 * projet temporaire, puis vérifie que TOUT le code produit est utilisable :
	 *  - chaque DTO, Processor, Provider et test généré se charge (syntaxe, imports, signatures d'interfaces) ;
	 *  - chaque #[ApiResource] injecté s'instancie avec les classes réelles d'API Platform
	 *    (arguments nommés de QueryParameter, constructeurs des filtres…).
	 */
	final class GenerateResourceCommandTest extends TestCase {
		private static string $projectDir;

		private static string $rootNamespace;

		private static TestKernel $kernel;

		/** @var array<string, array{status: int, display: string}> Résultat de chaque scénario, par nom */
		private static array $runs = [];

		/* ── Préparation : projet temporaire, noyau, exécution des scénarios ─── */

		public static function setUpBeforeClass(): void {
			self::$rootNamespace = 'E2e' . bin2hex(random_bytes(4));
			self::$projectDir    = str_replace('\\', '/', sys_get_temp_dir()) . '/sociolink_e2e_' . bin2hex(random_bytes(4));

			/* Entités, énumérations et classes de support de l'application (contrat des catalogues, StatusParameter, RealmParameter). */
			foreach (['Entity', 'Enum', 'Filters', 'System/Filter'] as $dir) {
				foreach (glob(__DIR__ . "/Fixtures/{$dir}/*.php.dist") ?: [] as $fixture) {
					new Filesystem()->dumpFile(
						self::$projectDir . "/src/{$dir}/" . basename($fixture, '.dist'),
						str_replace('__NS__', self::$rootNamespace, (string)file_get_contents($fixture)),
					);
				}
			}

			/* Autoloading PSR-4 du projet temporaire : {racine}\Tests\ → tests/, {racine}\ → src/. */
			spl_autoload_register(static function (string $class): void {
				foreach ([self::$rootNamespace . '\\Tests\\' => '/tests/', self::$rootNamespace . '\\' => '/src/'] as $prefix => $dir) {
					if (str_starts_with($class, $prefix)) {
						$file = self::$projectDir . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

						if (is_file($file)) {
							require $file;
						}

						return;
					}
				}
			});

			self::$kernel = new TestKernel(self::$projectDir, [
				'root_namespace' => self::$rootNamespace,
				'tests'          => ['namespace' => self::$rootNamespace . '\\Tests\\Functional'],
			]);
			self::$kernel->boot();

			self::$runs['article']  = self::runCommand(['entity' => 'Article', '--with-provider' => true, '--with-tests' => true, '--sub-resources' => true, '--with-mercure' => true, '--graphql-filters' => true]);
			self::$runs['comment']  = self::runCommand(['entity' => 'Comment', '--toggle-boolean' => true, '--with-tests' => true]);
			self::$runs['author']   = self::runCommand(['entity' => '\\' . self::$rootNamespace . '\\Entity\\Author', '--detach-boolean' => true]);
			self::$runs['category'] = self::runCommand(['entity' => 'Category', '--only-resource' => true]);
		}

		public static function tearDownAfterClass(): void {
			self::$kernel->shutdown();

			/* SOCIOLINK_E2E_KEEP=1 conserve le projet généré pour inspection. */
			if (getenv('SOCIOLINK_E2E_KEEP') === false) {
				new Filesystem()->remove(self::$projectDir);
			}
		}

		/**
		 * Exécute la commande dans le conteneur du noyau (entrée non interactive).
		 *
		 * @param array<string, mixed> $input
		 *
		 * @return array{status: int, display: string}
		 */
		private static function runCommand(array $input, string $command = 'generate:resource'): array {
			$tester = new CommandTester(new Application(self::$kernel)->find($command));
			$status = $tester->execute($input, ['interactive' => false]);

			return ['status' => $status, 'display' => $tester->getDisplay()];
		}

		private static function src(string $relative): string {
			return (string)file_get_contents(self::$projectDir . '/src/' . $relative);
		}

		/* ── Enregistrement du bundle ─── */

		public function testCommandIsRegisteredWithItsAliases(): void {
			$application = new Application(self::$kernel);

			$this->assertSame('generate:resource', $application->find('generate:resource')->getName());
			$this->assertSame('generate:resource', $application->find('sociolink:api-resource:generate')->getName());
		}

		public function testEveryScenarioSucceeds(): void {
			foreach (self::$runs as $name => $run) {
				$this->assertSame(Command::SUCCESS, $run['status'], "Scénario {$name} :\n{$run['display']}");
				$this->assertStringNotContainsString('[ERROR]', $run['display'], "Scénario {$name}");
			}
		}

		/* ── Tout le code généré est chargeable ─── */

		public function testEveryGeneratedClassLoads(): void {
			$loaded = 0;

			foreach (['src/DTO', 'src/State', 'src/Filters', 'tests/Functional'] as $dir) {
				$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$projectDir . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS));

				foreach ($iterator as $file) {
					$relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(self::$projectDir) + 1);
					$class    = self::$rootNamespace . '\\' . str_replace('/', '\\', (string)preg_replace(['#^src/#', '#^tests/#', '#\.php$#'], ['', 'Tests/', ''], $relative));

					/* Le chargement vérifie la syntaxe, les imports et la compatibilité des signatures (ProcessorInterface…). */
					$this->assertTrue(class_exists($class) || interface_exists($class), "Classe générée non chargeable : {$class} ({$relative})");

					/* Mise en forme : ni double ligne vide, ni ligne vide avant une accolade fermante. */
					$code = (string)file_get_contents($file->getPathname());
					$this->assertStringNotContainsString("\n\n\n", $code, "Double ligne vide dans {$relative}");
					$this->assertDoesNotMatchRegularExpression('/\n\n[ ]*\}/', $code, "Ligne vide avant « } » dans {$relative}");
					$loaded++;
				}
			}

			$this->assertGreaterThanOrEqual(20, $loaded);
		}

		public function testEveryInjectedApiResourceInstantiatesWithApiPlatform(): void {
			$instantiated = 0;

			foreach (['Article', 'Comment', 'Author', 'Category'] as $entity) {
				/* L'entité modifiée est rechargée sous un autre espace de noms (la classe d'origine est déjà chargée). */
				$namespace = self::$rootNamespace . '\\Check';
				$source    = str_replace('namespace ' . self::$rootNamespace . '\\Entity;', "namespace {$namespace};", self::src("Entity/{$entity}.php"));
				$path      = self::$projectDir . "/var/check_{$entity}.php";

				file_put_contents($path, $source);
				require $path;

				foreach (new ReflectionClass("{$namespace}\\{$entity}")->getAttributes(ApiResource::class) as $attribute) {
					$this->assertInstanceOf(ApiResource::class, $attribute->newInstance());
					$instantiated++;
				}
			}

			/* Article, Author, Category : une ressource ; Comment : ressource principale + sous-ressource de Article. */
			$this->assertSame(5, $instantiated);
		}

		/* ── Contenu attendu ─── */

		public function testProviderIsWiredOnGet(): void {
			$this->assertStringContainsString('new Get(provider: ArticleProvider::class)', self::src('Entity/Article.php'));
			$this->assertStringContainsString('getSingleIdentifierFieldName()', self::src('State/Article/ArticleProvider.php'));
			$this->assertStringNotContainsString("'e.id = :id'", self::src('State/Article/ArticleProvider.php'));
			$this->assertStringContainsString("mercure: ['private' => true]", self::src('Entity/Article.php'));
		}

		public function testDtoCopiesOnlyValidationAttributesAndImportsTheirAliases(): void {
			$dto = self::src('DTO/Article/ArticleCreateDto.php');

			$this->assertStringContainsString('#[Assert\NotBlank]', $dto);
			$this->assertStringContainsString('#[Assert\Length(max: Article::TITLE_MAX)]', $dto);
			$this->assertStringContainsString('use Symfony\Component\Validator\Constraints as AssertPhone;', $dto);
			$this->assertStringNotContainsString('ORM\Column', $dto);
			$this->assertStringContainsString('public float|null $rating = null;', $dto);
			$this->assertStringContainsString('public string $price;', $dto);
			$this->assertStringContainsString('public string|null $reference = null;', $dto); /* guid : chaîne côté Doctrine */
		}

		public function testDtosCarryAnApiPropertyScaffoldOnEveryProperty(): void {
			/* Chaque propriété de DTO reçoit #[ApiProperty(description: '')] : la description, en français, est à
			 * compléter d'après la feuille de route front. Le test d'architecture de l'API refuse une description vide. */
			$create = self::src('DTO/Article/ArticleCreateDto.php');
			$update = self::src('DTO/Article/ArticleUpdateDto.php');

			$this->assertStringContainsString('use ApiPlatform\Metadata\ApiProperty;', $create);
			$this->assertStringContainsString("#[ApiProperty(description: '')]", $create);
			$this->assertStringContainsString("#[ApiProperty(description: '')]\n    public string \$price;", $create);
			$this->assertStringContainsString('use ApiPlatform\Metadata\ApiProperty;', $update);
			$this->assertStringContainsString("#[ApiProperty(description: '')]", $update);
		}

		public function testPhoneNumberTypeIsTypedAsPhoneNumberEverywhere(): void {
			/* Un DTO typé string ferait échouer le setter de l'entité (TypeError) : le DTO doit porter l'objet hydraté. */
			$create = self::src('DTO/Article/ArticleCreateDto.php');
			$update = self::src('DTO/Article/ArticleUpdateDto.php');

			$this->assertStringContainsString('use libphonenumber\PhoneNumber;', $create);
			$this->assertStringContainsString('public PhoneNumber $mobile;', $create);
			$this->assertStringContainsString('use libphonenumber\PhoneNumber;', $update);
			$this->assertStringContainsString('public PhoneNumber|null $mobile = null;', $update);

			$this->assertStringContainsString("'+33612345678'", (string)file_get_contents(self::$projectDir . '/tests/Functional/Article/ArticleApiTest.php'));
			$this->assertStringNotContainsString('phone_number', self::$runs['article']['display'], 'phone_number ne doit plus être signalé comme non reconnu.');
		}

		public function testStringFieldsGetAPartialFilterButCustomTypesStayExactInTheCatalogue(): void {
			$filters = self::src('Filters/ArticleFilters.php');

			$this->assertStringContainsString('final class ArticleFilters implements CollectionFiltersInterface', $filters);
			$this->assertStringContainsString("'title' => new QueryParameter(filter: new PartialSearchFilter(), property: 'title'),", $filters);
			$this->assertStringContainsString("'mobile' => new QueryParameter(filter: new ExactFilter(), property: 'mobile'),", $filters);
			$this->assertStringContainsString('use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;', $filters);
		}

		public function testStatusIsTheRequiredApplicationParameterEvenForAnEnum(): void {
			$filters = self::src('Filters/ArticleFilters.php');

			$this->assertStringContainsString("'status' => new StatusParameter(required: true),", $filters);
			$this->assertStringContainsString('use ' . self::$rootNamespace . '\\System\\Filter\\StatusParameter;', $filters);
			$this->assertStringNotContainsString("'status' => new QueryParameter", $filters);
			/* Le contrat est dans le même espace de noms que le catalogue : il n'est pas importé. */
			$this->assertStringNotContainsString('CollectionFiltersInterface;', self::src('Filters/CommentFilters.php'));
			$this->assertStringContainsString('implements CollectionFiltersInterface', self::src('Filters/CommentFilters.php'));
		}

		public function testRealmRelationIsTheRequiredApplicationParameter(): void {
			$filters = self::src('Filters/CategoryFilters.php');

			$this->assertStringContainsString("'realm' => new RealmParameter(required: true),", $filters);
			$this->assertStringContainsString('use ' . self::$rootNamespace . '\\System\\Filter\\RealmParameter;', $filters);
			$this->assertStringNotContainsString("'realm' => new QueryParameter", $filters);
		}

		public function testEntitiesReferenceTheirCatalogueAndDeclareNoParameters(): void {
			foreach (['Article', 'Comment', 'Author', 'Category'] as $entity) {
				$source = self::src("Entity/{$entity}.php");

				$this->assertStringNotContainsString('parameters: [', $source, $entity);
				$this->assertStringContainsString("new GetCollection(extraProperties: ['collection_filters' => {$entity}Filters::class])", $source, $entity);
				$this->assertStringContainsString("new QueryCollection(paginationType: 'page', extraProperties: ['collection_filters' => {$entity}Filters::class])", $source, $entity);
				$this->assertStringContainsString('use ' . self::$rootNamespace . "\\Filters\\{$entity}Filters;", $source, $entity);
				$this->assertFileExists(self::$projectDir . "/src/Filters/{$entity}Filters.php", $entity);
			}
		}

		public function testEveryGeneratedCatalogueReturnsQueryParameters(): void {
			foreach (['Article', 'Comment', 'Author', 'Category'] as $entity) {
				$catalogue  = self::$rootNamespace . "\\Filters\\{$entity}Filters";
				$parameters = new $catalogue()->parameters();

				$this->assertNotEmpty($parameters, $entity);
				$this->assertArrayHasKey('order[:property]', $parameters, $entity);

				foreach ($parameters as $key => $parameter) {
					$this->assertIsString($key);
					$this->assertInstanceOf(QueryParameter::class, $parameter, "{$entity} : {$key}");
				}
			}

			$this->assertTrue(new (self::$rootNamespace . '\\Filters\\ArticleFilters')()->parameters()['status']->getRequired());
		}

		public function testCatalogueIsNotWrittenWhenTheEntityIsSkipped(): void {
			$catalogue = self::$projectDir . '/src/Filters/CategoryFilters.php';
			$backup    = (string)file_get_contents($catalogue);

			unlink($catalogue);
			$run = self::runCommand(['entity' => 'Category', '--only-resource' => true]);

			try {
				$this->assertSame(Command::SUCCESS, $run['status'], $run['display']);
				$this->assertFileDoesNotExist($catalogue, 'Ressource déjà présente sans --force : aucun catalogue orphelin ne doit être écrit.');
			}
			finally {
				file_put_contents($catalogue, $backup);
			}
		}

		public function testPhoneNumberPayloadIsDenormalizedIntoTheGeneratedDtos(): void {
			/* Serializer réel + normaliseur d'odolbeau/phone-number-bundle : la chaîne JSON devient un PhoneNumber. */
			$serializer = self::$kernel->getContainer()->get('test.serializer');
			$this->assertInstanceOf(DenormalizerInterface::class, $serializer);

			foreach (['ArticleCreateDto', 'ArticleUpdateDto'] as $dto) {
				$class = self::$rootNamespace . "\\DTO\\Article\\{$dto}";
				$data  = $serializer->denormalize(['mobile' => '+33612345678'], $class);

				$this->assertInstanceOf(PhoneNumber::class, $data->mobile, $dto);
				$this->assertSame('+33612345678', PhoneNumberUtil::getInstance()->format($data->mobile, PhoneNumberFormat::E164), $dto);
			}
		}

		public function testUpdateDtoAcceptsOmittedFields(): void {
			$dto = self::src('DTO/Author/AuthorUpdateDto.php');

			/* PATCH partiel : null = « non envoyé », NotBlank ne doit donc pas rejeter null. */
			$this->assertStringContainsString('#[Assert\NotBlank(allowNull: true)]', $dto);
			$this->assertStringNotContainsString("#[Assert\\NotBlank]\n", $dto);
		}

		public function testUploadEndpointKeepsTheImageConstraintAndTheRealSetter(): void {
			$this->assertStringContainsString("#[Assert\Image(maxSize: '2M')]", self::src('DTO/Article/ArticleUploadImageDto.php'));
			$this->assertStringContainsString('$data->setImageFile($file);', self::src('State/Article/ArticleUploadImageProcessor.php'));
		}

		public function testGeneratedTestUsesAllowedEnumValues(): void {
			$test = (string)file_get_contents(self::$projectDir . '/tests/Functional/Article/ArticleApiTest.php');

			/* status est un champ système (absent du POST), mais il est obligatoire sur la collection : valeur autorisée de l'énumération. */
			$this->assertStringContainsString("request('GET', '/api/articles', ['query' => ['status' => 'draft']]);", $test);
			$this->assertStringContainsString("['query' => array_merge(['status' => 'draft'], \$query)]", $test);
			$this->assertStringContainsString("'price' => '1.50',", $test);
		}

		public function testGeneratedCodeIsIndented(): void {
			$this->assertStringContainsString("\n    public function process(", self::src('State/Article/ArticleCreateProcessor.php'));
			$this->assertStringContainsString("\n        if (\$data->itDeleted !== null) {", self::src('State/Comment/CommentToggleProcessor.php'));
		}

		public function testDetachBooleanWiresOneEndpointPerBoolean(): void {
			$author = self::src('Entity/Author.php');

			$this->assertStringContainsString("uriTemplate: '/authors/{id}/toggle-it-deleted'", $author);
			$this->assertStringContainsString('processor: AuthorActiveProcessor::class', $author);
			$this->assertStringContainsString('/authors/{id}/toggle-it-deleted.', self::src('DTO/Author/AuthorItDeletedDto.php'));
		}

		public function testOnlyResourceGeneratesNoArtifact(): void {
			$this->assertDirectoryDoesNotExist(self::$projectDir . '/src/DTO/Category');
			$this->assertStringContainsString('new Post(),', self::src('Entity/Category.php'));
		}

		public function testUnrecognizedDoctrineTypeIsReportedAsAWarning(): void {
			$this->assertSame(Command::SUCCESS, self::$runs['comment']['status']);
			$this->assertStringContainsString('[WARNING] Types Doctrine non reconnus', self::$runs['comment']['display']);
			$this->assertStringContainsString('binary', self::$runs['comment']['display']);
		}

		/* ── Modes sans écriture et erreurs ─── */

		public function testDryRunWritesNothing(): void {
			$before = $this->snapshot();
			$run    = self::runCommand(['entity' => '*', '--dry-run' => true, '--force' => true, '--with-tests' => true]);

			$this->assertSame(Command::SUCCESS, $run['status'], $run['display']);
			$this->assertSame($before, $this->snapshot());
		}

		public function testReinitOnlyRemovesTheTargetedEntityDirectories(): void {
			$handWritten = self::$projectDir . '/src/State/Article/ArticleCustomProvider.php';
			file_put_contents($handWritten, "<?php\n");

			$run = self::runCommand(['entity' => 'Author', '--force' => true, '--reinit' => true, '--detach-boolean' => true]);

			$this->assertSame(Command::SUCCESS, $run['status'], $run['display']);
			$this->assertFileExists($handWritten, 'Les dossiers des autres entités ne doivent pas être touchés.');
			$this->assertFileExists(self::$projectDir . '/src/DTO/Author/AuthorActiveDto.php');

			unlink($handWritten);
		}

		public function testMissingEntityArgumentWithoutTerminalFails(): void {
			$run = self::runCommand([]);

			$this->assertSame(Command::FAILURE, $run['status']);
			$this->assertStringContainsString('Aucune entité indiquée', $run['display']);
		}

		public function testUnknownEntityFails(): void {
			$this->assertSame(Command::FAILURE, self::runCommand(['entity' => 'Nope'])['status']);
		}

		public function testIncompatibleOptionsFail(): void {
			$this->assertSame(Command::FAILURE, self::runCommand(['entity' => 'Article', '--toggle-boolean' => true, '--detach-boolean' => true])['status']);
		}

		/**
		 * Empreinte de tous les fichiers du projet (hors var/).
		 *
		 * @return array<string, string>
		 */
		private function snapshot(): array {
			$hashes = [];

			foreach (['src', 'tests'] as $dir) {
				foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$projectDir . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
					$hashes[$file->getPathname()] = (string)md5_file($file->getPathname());
				}
			}

			ksort($hashes);

			return $hashes;
		}
	}
