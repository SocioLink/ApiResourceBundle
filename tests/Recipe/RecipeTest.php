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
	 * Description: Tests de la recette Symfony Flex (recipe/) et de sa version compilée (flex/).
	 *              Vérifient que le fichier de configuration copié par la recette est accepté tel quel par l'arbre de
	 *              configuration du bundle, que ses valeurs en commentaire, une fois décommentées, sont exactement les
	 *              valeurs par défaut (aucune clé oubliée ni valeur périmée), et que flex/ correspond à la recompilation
	 *              des sources par recipe/build.php.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Tests\Recipe;

	use Symfony\Component\Yaml\Yaml;
	use PHPUnit\Framework\TestCase;
	use SocioLink\ApiResourceBundle\ApiResourceBundle;
	use Symfony\Component\Config\Definition\Processor;
	use Symfony\Component\DependencyInjection\ContainerBuilder;
	use Symfony\Component\Config\Definition\ConfigurationInterface;
	use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

	final class RecipeTest extends TestCase {
		private const string RECIPE_DIR  = __DIR__ . '/../../recipe/sociolink/api-resource-bundle/1.0';
		private const string CONFIG_FILE = self::RECIPE_DIR . '/config/packages/sociolink_api_resource.yaml';

		/**
		 * @param list<mixed> $configs
		 *
		 * @return array<string, mixed>
		 */
		private function process(array $configs): array {
			$container = new ContainerBuilder();
			$extension = new ApiResourceBundle()->getContainerExtension();
			$this->assertInstanceOf(ConfigurationExtensionInterface::class, $extension);

			$configuration = $extension->getConfiguration([], $container);
			$this->assertInstanceOf(ConfigurationInterface::class, $configuration);

			return new Processor()->processConfiguration($configuration, $configs);
		}

		public function testRecipeConfigurationIsLoadedOnlyInDevAndTestWithTheDefaults(): void {
			$yaml = Yaml::parseFile(self::CONFIG_FILE);

			$this->assertSame(['when@dev', 'when@test'], array_keys($yaml));
			$this->assertSame($yaml['when@dev'], $yaml['when@test']);
			$this->assertSame($this->process([]), $this->process([$yaml['when@dev']['sociolink_api_resource']]));
		}

		public function testCommentedValuesAreExactlyTheDefaults(): void {
			/* Décommente les lignes de la section (« # clé: valeur » → « clé: valeur »), commentaires en fin de ligne compris. */
			$uncommented = (string)preg_replace('/^(\s{8,})# /m', '$1', (string)file_get_contents(self::CONFIG_FILE));
			$config      = Yaml::parse($uncommented)['when@dev']['sociolink_api_resource'];

			$this->assertIsArray($config);
			$this->assertSame(array_keys($this->process([])), array_keys($config), 'Chaque clé de configuration doit figurer dans la recette.');
			$this->assertSame($this->process([]), $this->process([$config]));
		}

		public function testCompiledRecipeIsUpToDate(): void {
			$output = sys_get_temp_dir() . '/sociolink_recipe_' . bin2hex(random_bytes(6));
			exec(sprintf('%s %s --output=%s', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/../../recipe/build.php'), escapeshellarg($output)), $lines, $status);

			$this->assertSame(0, $status, implode("\n", $lines));

			$built = (string)file_get_contents($output . '/sociolink.api-resource-bundle.1.0.json');
			array_map(unlink(...), glob($output . '/*') ?: []);
			rmdir($output);

			$this->assertSame($built, (string)file_get_contents(__DIR__ . '/../../flex/sociolink.api-resource-bundle.1.0.json'), 'flex/ est périmé : relancez « php recipe/build.php ».');
		}
	}
