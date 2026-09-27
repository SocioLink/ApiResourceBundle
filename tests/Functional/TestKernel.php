<?php

	/*
	 * Copyright (c) 2026.
	 * Date: 27/09/2026
	 * Author: Xavier KONGOLO <xsompwe@gmail.com>
	 * Description: Noyau Symfony minimal (FrameworkBundle + ApiResourceBundle) pour les tests de bout en bout.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Tests\Functional;

	use Doctrine\ORM\EntityManager;
	use Doctrine\ORM\ORMSetup;
	use Doctrine\DBAL\DriverManager;
	use Doctrine\ORM\EntityManagerInterface;
	use Symfony\Component\HttpKernel\Kernel;
	use SocioLink\ApiResourceBundle\ApiResourceBundle;
	use Symfony\Component\Config\Loader\LoaderInterface;
	use Symfony\Component\DependencyInjection\ContainerBuilder;
	use Symfony\Bundle\FrameworkBundle\FrameworkBundle;

	/**
	 * Application de test : le bundle tel qu'un projet l'enregistre, avec un EntityManager Doctrine réel
	 * (mapping par attributs, SQLite en mémoire : aucune requête n'est exécutée par la génération).
	 */
	final class TestKernel extends Kernel {
		/**
		 * @param array<string, mixed> $bundleConfig Configuration `sociolink_api_resource`
		 */
		public function __construct(private readonly string $projectDirectory, private readonly array $bundleConfig) {
			parent::__construct('test', true);
		}

		/**
		 * Fabrique de l'EntityManager (service Doctrine\ORM\EntityManagerInterface du conteneur de test).
		 */
		public static function createEntityManager(string $entityDir): EntityManagerInterface {
			$config = ORMSetup::createAttributeMetadataConfig([$entityDir], true);
			$config->enableNativeLazyObjects(true);

			return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
		}

		public function registerBundles(): iterable {
			yield new FrameworkBundle();
			yield new ApiResourceBundle();
		}

		public function getProjectDir(): string {
			return $this->projectDirectory;
		}

		public function getCacheDir(): string {
			return $this->projectDirectory . '/var/cache';
		}

		public function getLogDir(): string {
			return $this->projectDirectory . '/var/log';
		}

		public function registerContainerConfiguration(LoaderInterface $loader): void {
			$loader->load(function (ContainerBuilder $container): void {
				$container->loadFromExtension('framework', [
					'secret'                => 'test',
					'test'                  => true,
					'http_method_override'  => false,
					'handle_all_throwables' => true,
					'router'                => ['resource' => '%kernel.project_dir%/config/routes.php'], /* jamais chargé : aucune requête HTTP */
					'php_errors'            => ['log' => true],
				]);

				$container->loadFromExtension('sociolink_api_resource', $this->bundleConfig);

				$container->register(EntityManagerInterface::class, EntityManagerInterface::class)
				          ->setFactory([self::class, 'createEntityManager'])
				          ->setArguments([$this->projectDirectory . '/src/Entity']);
			});
		}
	}
