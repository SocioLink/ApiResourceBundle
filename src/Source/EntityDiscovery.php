<?php

	/*
	 * Copyright (c) 2026.
	 * Date: 21/09/2026
	 * Author: Xavier KONGOLO <xsompwe@gmail.com>
	 * Description: Découverte et validation des entités Doctrine du projet.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Source;

	use Exception;
	use FilesystemIterator;
	use RecursiveIteratorIterator;
	use RecursiveDirectoryIterator;
	use Doctrine\ORM\EntityManagerInterface;
	use Symfony\Component\Console\Style\SymfonyStyle;

	/**
	 * Découvre et valide les entités Doctrine du projet.
	 *
	 * L'espace de noms des entités et le dossier des sources proviennent de {@see GeneratorConfig}.
	 * Les superclasses mappées et les classes embarquables sont ignorées : elles ne sont pas des
	 * ressources API et n'ont pas à recevoir d'attribut #[ApiResource].
	 *
	 * @internal Réservé à l'usage interne du bundle.
	 */
	final readonly class EntityDiscovery implements EntityDiscoveryInterface {
		public function __construct(private EntityManagerInterface $entityManager, private GeneratorConfig $config) {}

		/* ── Résolution d'un argument CLI ─── */

		/**
		 * Résout un argument CLI en FQCN d'entité valide.
		 *
		 * Un nom court ('Article', 'Blog\Article') est préfixé par l'espace de noms des entités ;
		 * un FQCN commençant par la racine du projet est utilisé tel quel. La classe doit exister
		 * et posséder un mapping Doctrine.
		 *
		 * @return list<string>|null Liste d'un FQCN, ou null si l'argument est invalide (l'erreur est affichée)
		 */
		public function resolveEntityArgument(string $arg, SymfonyStyle $io): ?array {
			$arg = ltrim($arg, '\\'); /* « \App\Entity\Article » est un FQCN valide */

			if (!str_starts_with($arg, $this->config->rootNamespace . '\\')) {
				$arg = $this->config->entityNamespace . '\\' . $arg;
			}

			if (!class_exists($arg)) {
				$io->error(sprintf('La classe "%s" n\'existe pas.', $arg));

				return null;
			}

			try {
				$this->entityManager->getClassMetadata($arg);
			}
			catch (Exception) {
				$io->error(sprintf('"%s" n\'est pas une entité Doctrine valide (aucun mapping trouvé).', $arg));

				return null;
			}

			return [$arg];
		}

		/* ── Découverte de toutes les entités ─── */

		/**
		 * Découvre toutes les entités du dossier des entités, triées alphabétiquement.
		 *
		 * Un fichier présent mais non autoloadable (erreur de namespace) est ignoré silencieusement
		 * plutôt que de faire échouer la commande.
		 *
		 * @return list<string> Liste de FQCN, vide si le dossier n'existe pas
		 */
		public function discoverAllEntities(SymfonyStyle $io): array {
			$sourceRoot = $this->config->projectDir . '/' . trim($this->config->sourceDir, '/');
			$entityDir  = $sourceRoot . '/' . str_replace('\\', '/', substr($this->config->entityNamespace, strlen($this->config->rootNamespace) + 1));

			if (!is_dir($entityDir)) {
				$io->warning(sprintf('Le répertoire "%s" n\'existe pas.', $entityDir));

				return [];
			}

			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($entityDir, FilesystemIterator::SKIP_DOTS));
			$entities = [];

			foreach ($iterator as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}

				/* Chemin normalisé en « / » (Windows) puis converti en FQCN : {racine}\{chemin relatif sans .php}. */
				$relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($sourceRoot) + 1);
				$fqcn     = $this->config->rootNamespace . '\\' . str_replace('/', '\\', substr($relative, 0, -4));

				if (!class_exists($fqcn)) {
					continue;
				}

				try {
					$metadata = $this->entityManager->getClassMetadata($fqcn);
				}
				catch (Exception) {
					continue; /* pas une entité (classe utilitaire, énumération…) */
				}

				if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
					continue;
				}

				$entities[] = $fqcn;
			}

			sort($entities);

			return $entities;
		}
	}
