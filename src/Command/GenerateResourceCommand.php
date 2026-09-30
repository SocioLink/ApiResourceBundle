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
	 * Description: Commande CLI generate:resource (alias sociolink:api-resource:generate, g:r, gen:resource…) : point
	 *              d'entrée utilisateur de la génération des ressources API Platform.
	 *              Sélection des entités : un nom court ou un FQCN traite une entité ; « * » les traite toutes sans menu
	 *              (CI) ; sans argument, un menu numéroté s'affiche dans un terminal et la commande échoue explicitement
	 *              hors terminal, sans jamais traiter implicitement toutes les entités.
	 *              La commande ne fait que de l'orchestration : validation des options (GenerationOptionsFactory), menu
	 *              (EntitySelector), nettoyage --reinit confirmé et limité aux entités traitées, barre de progression,
	 *              génération par entité (ResourceGeneratorInterface), signalement des types Doctrine non reconnus et
	 *              résumé (SummaryPrinter).
	 *              Code de sortie : 0 si toutes les entités ont été traitées, 1 si une option est invalide, si une entité
	 *              est introuvable ou si au moins une génération a échoué (les autres entités sont tout de même traitées).
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Command;

	use Throwable;
	use Symfony\Component\Console\Command\Command;
	use Symfony\Component\Console\Input\InputOption;
	use Symfony\Component\Console\Style\SymfonyStyle;
	use Symfony\Component\Console\Attribute\AsCommand;
	use Symfony\Component\Console\Input\InputArgument;
	use Symfony\Component\Console\Input\InputInterface;
	use Symfony\Component\Console\Output\OutputInterface;
	use SocioLink\ApiResourceBundle\Source\FieldAnalyser;
	use SocioLink\ApiResourceBundle\Console\SummaryPrinter;
	use SocioLink\ApiResourceBundle\Source\GeneratorConfig;
	use SocioLink\ApiResourceBundle\Console\EntitySelector;
	use SocioLink\ApiResourceBundle\Console\InvalidOptionsException;
	use SocioLink\ApiResourceBundle\Source\EntityDiscoveryInterface;
	use SocioLink\ApiResourceBundle\Console\GenerationOptionsFactory;
	use SocioLink\ApiResourceBundle\Source\ResourceGeneratorInterface;

	/**
	 * Commande CLI generate:resource — génération automatique des ressources API Platform.
	 *
	 * Génère pour chaque entité Doctrine ciblée :
	 *  - les DTOs (Create, Update, Toggle ou individuels, Upload) ;
	 *  - les State Processors (Create, Update, Toggle ou individuels, Upload) ;
	 *  - optionnellement un State Provider (GET {id}) ;
	 *  - l'injection de l'attribut #[ApiResource] et de ses paramètres de filtrage (#[QueryParameter],
	 *    attachés à GetCollection) dans le fichier source de l'entité.
	 *
	 * Sélection des entités :
	 *  - argument `entity` (nom court ou FQCN) : cette entité seule ;
	 *  - argument `*` : toutes les entités, sans menu (utilisable en CI ou avec --no-interaction) ;
	 *  - aucun argument dans un terminal : menu numéroté regroupé par namespace, « All » en dernier ;
	 *  - aucun argument sans terminal : erreur explicite (jamais de traitement global implicite).
	 *
	 * La commande ne contient que de l'orchestration : validation des options
	 * ({@see GenerationOptionsFactory}), menu ({@see EntitySelector}), résumé ({@see SummaryPrinter}).
	 */
	#[AsCommand(
		name       : 'generate:resource',
		description: 'Génère DTOs, Processors, Providers et injecte #[ApiResource] pour les entités Doctrine.',
		aliases    : [
			'sociolink:api-resource:generate',
			'g:r', 'g:res', 'g:resource', 'gen:r', 'gen:res', 'gen:resource', 'gn:r', 'gn:res', 'gn:resource',
		],
	)]
	final class GenerateResourceCommand extends Command {
		/** Valeur de l'argument `entity` désignant toutes les entités, sans menu. */
		public const string ALL_ENTITIES_ARGUMENT = '*';

		public function __construct(
			private readonly ResourceGeneratorInterface $generator, private readonly EntityDiscoveryInterface $entityDiscovery, private readonly GenerationOptionsFactory $optionsFactory,
			private readonly EntitySelector             $entitySelector, private readonly SummaryPrinter $summaryPrinter, private readonly GeneratorConfig $config, private readonly FieldAnalyser $fieldAnalyser,
		) {
			parent::__construct();
		}

		/* ── Configuration ─── */

		protected function configure(): void {
			$this
				->addArgument('entity', InputArgument::OPTIONAL, 'Nom court (ex. Article) ou FQCN de l\'entité ; « * » = toutes les entités. Absent = menu interactif.')
				/* ── Options de destruction ─── */
				->addOption('force', 'f', InputOption::VALUE_NONE, 'Écrase les fichiers existants et réinjecte les attributs #[ApiResource].')
				->addOption('reinit', 'r', InputOption::VALUE_NONE, 'Utilisé avec --force : supprime les répertoires DTO/ et State/ des entités traitées et leurs sous-ressources orphelines.')
				/* ── Options mutuellement exclusives ─── */
				->addOption('only-resource', 'o', InputOption::VALUE_NONE, 'Injecte #[ApiResource] + filtres uniquement.')
				->addOption('with-provider', 'w', InputOption::VALUE_NONE, 'Génère un Provider pour GET {id}.')
				->addOption('toggle-boolean', 't', InputOption::VALUE_NONE, 'Crée un ToggleDto + ToggleProcessor pour TOUS les booléens.')
				->addOption('detach-boolean', 'd', InputOption::VALUE_NONE, 'Crée un DTO + Processor PATCH individuel par attribut booléen.')
				->addOption('all', 'a', InputOption::VALUE_NONE, 'Injecte un #[ApiResource] libre + tous les artefacts sans les lier.')
				/* ── Options indépendantes ─── */
				->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule la génération sans écrire aucun fichier.')
				->addOption('preview', null, InputOption::VALUE_NONE, 'Affiche le code généré sans écrire (implique --dry-run).')
				->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Conservée pour compatibilité : le menu s\'affiche dès qu\'aucune entité n\'est indiquée dans un terminal.')
				->addOption('with-tests', null, InputOption::VALUE_NONE, 'Génère des tests fonctionnels ApiTestCase pour chaque endpoint.')
				->addOption('sub-resources', null, InputOption::VALUE_NONE, 'Génère des sous-ressources pour les relations OneToMany.')
				->addOption('graphql-filters', null, InputOption::VALUE_NONE, 'Reporte les paramètres de filtrage sur GraphQL (QueryCollection).')
				->addOption('with-mercure', null, InputOption::VALUE_NONE, 'Injecte la directive mercure dans #[ApiResource] (privée par défaut).')
				->addOption('public', null, InputOption::VALUE_NONE, 'Avec --with-mercure : mercure: true (mises à jour publiques) au lieu de mercure privé.')
				->setHelp(<<<'HELP'
                    Sans argument, dans un terminal, un menu numéroté (entités regroupées par namespace, « All » en dernier)
                    permet de choisir l'entité à traiter. Sans terminal (CI, --no-interaction), indiquez une entité ou « * ».

                      php bin/console generate:resource                    # menu interactif
                      php bin/console generate:resource Article            # une entité
                      php bin/console generate:resource '*' --force        # toutes les entités, sans menu
                      php bin/console generate:resource Article --with-mercure           # mercure: ['private' => true]
                      php bin/console generate:resource Article --with-mercure --public  # mercure: true
                    HELP
				);
		}

		/* ── Exécution ─── */

		protected function execute(InputInterface $input, OutputInterface $output): int {
			$io = new SymfonyStyle($input, $output);

			try {
				try {
					$options = $this->optionsFactory->create($input);
				}
				catch (InvalidOptionsException $e) {
					$io->error($e->getMessage());

					return Command::FAILURE;
				}

				if ($options->isReadOnly()) {
					$label = $options->preview ? 'MODE APERÇU' : 'MODE DRY-RUN';
					$io->warning(sprintf('%s : aucun fichier ne sera écrit sur le disque.', $label));
					$io->newLine();
				}

				$entityArg = $input->getArgument('entity');
				$all       = null; /* liste de toutes les entités, découverte au plus une fois */

				/* ── Sans argument : menu dans un terminal, erreur sinon ─── */
				if ($entityArg === null) {
					if (!$input->isInteractive()) {
						$io->error(
							sprintf(
								'Aucune entité indiquée et aucun terminal disponible pour le menu. Précisez une entité ou « %s » pour toutes les entités.',
								self::ALL_ENTITIES_ARGUMENT,
							)
						);

						return Command::FAILURE;
					}

					$all = $this->entityDiscovery->discoverAllEntities($io);

					if ($all === []) {
						$io->warning(sprintf('Aucune entité Doctrine trouvée sous %s.', $this->config->entityNamespace));

						return Command::SUCCESS;
					}

					$entityArg = $this->entitySelector->choose($all, $io); /* FQCN, ou null = All */
				}
				elseif ($entityArg === self::ALL_ENTITIES_ARGUMENT) {
					$entityArg = null;
				}

				/* ── Résolution des entités à traiter ─── */
				if ($entityArg !== null) {
					$entityClasses = $this->entityDiscovery->resolveEntityArgument((string)$entityArg, $io);
				}
				else {
					$entityClasses = $all ?? $this->entityDiscovery->discoverAllEntities($io);
				}

				if ($entityClasses === null) {
					return Command::FAILURE;
				}

				if ($entityClasses === []) {
					$io->warning(sprintf('Aucune entité Doctrine trouvée sous %s.', $this->config->entityNamespace));

					return Command::SUCCESS;
				}

				/*
				 * ── --reinit : suppression des dossiers DTO/State des SEULES entités traitées ───
				 * Destructif (ces dossiers peuvent contenir du code écrit à la main) : confirmation demandée dans un terminal.
				 */
				if ($options->force && $options->reinit && !$options->isReadOnly()) {
					$confirmed = !$input->isInteractive() || $entityClasses
					                                         |> count(...)
					                                         |> (static fn($x) => sprintf('--reinit va supprimer les dossiers DTO et State de %d entité(s), y compris les fichiers écrits à la main. Continuer ?', $x))
					                                         |> (static fn($x) => $io->confirm($x, false));

					if (!$confirmed) {
						$io->warning('Opération annulée.');

						return Command::FAILURE;
					}

					$io->section('Nettoyage des répertoires générés...');
					$this->generator->cleanBeforeReinit($entityClasses, $io);
					$io->newLine();
				}

				/* ── Progression et traitement ─── */
				$total       = count($entityClasses);
				$progressBar = $io->createProgressBar($total);
				$progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
				$progressBar->start();

				$results = [];

				foreach ($entityClasses as $entityClass) {
					$results[$entityClass] = $this->generator->processEntity($entityClass, $options, $io);
					$progressBar->advance();
				}

				$progressBar->finish();
				$io->newLine(2);

				$unrecognizedTypes = $this->fieldAnalyser->getUnrecognizedTypes();

				if ($unrecognizedTypes !== []) {
					$io->warning(sprintf(
						             'Types Doctrine non reconnus (fallback sur string) : %s. Ces types seront traités comme des chaînes dans les DTOs. '
						             . 'Si Doctrine les hydrate en objets, déclarez leur classe dans sociolink_api_resource.custom_types (ex. %s: App\ValueObject\Money).',
						             implode(', ', $unrecognizedTypes), $unrecognizedTypes[0],
					             ));
				}

				$this->fieldAnalyser->resetUnrecognizedTypes();

				$errors = $this->summaryPrinter->print($io, $results, $total, $options);

				if ($errors > 0) {
					return Command::FAILURE;
				}

				return Command::SUCCESS;
			}
			catch (Throwable $e) {
				$io->newLine(2);
				$io->error('Erreur inattendue : ' . $e->getMessage());

				if ($io->isVerbose()) {
					$io->text($e->getTraceAsString());
				}

				return Command::FAILURE;
			}
		}
	}
