<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Commande Symfony CLI generate:resource — génération automatique des ressources API Platform.
     */

    declare(strict_types=1);

    namespace BlackSheep\Symfony\ApiResourceBundle\Command;

    use Exception;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Style\SymfonyStyle;
    use Symfony\Component\Console\Attribute\AsCommand;
    use Symfony\Component\Console\Input\InputArgument;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Output\OutputInterface;
    use BlackSheep\Symfony\ApiResourceBundle\Source\FieldAnalyser;
    use BlackSheep\Symfony\ApiResourceBundle\Console\SummaryPrinter;
    use BlackSheep\Symfony\ApiResourceBundle\Source\GeneratorConfig;
    use BlackSheep\Symfony\ApiResourceBundle\Console\EntitySelector;
    use BlackSheep\Symfony\ApiResourceBundle\Console\GenerationOptionsFactory;
    use BlackSheep\Symfony\ApiResourceBundle\Console\InvalidOptionsException;
    use BlackSheep\Symfony\ApiResourceBundle\Source\EntityDiscoveryInterface;
    use BlackSheep\Symfony\ApiResourceBundle\Source\ResourceGeneratorInterface;

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
            'black-sheep:api-resource:generate',
            'g:r', 'g:res', 'g:resource', 'gen:r', 'gen:res', 'gen:resource', 'gn:r', 'gn:res', 'gn:resource',
        ],
    )]
    final class GenerateResourceCommand extends Command {
        /** Valeur de l'argument `entity` désignant toutes les entités, sans menu. */
        public const string ALL_ENTITIES_ARGUMENT = '*';

        public function __construct(
            private readonly ResourceGeneratorInterface $generator,
            private readonly EntityDiscoveryInterface   $entityDiscovery,
            private readonly GenerationOptionsFactory   $optionsFactory,
            private readonly EntitySelector             $entitySelector,
            private readonly SummaryPrinter             $summaryPrinter,
            private readonly GeneratorConfig            $config,
        ) {
            parent::__construct();
        }

        /* ── Configuration ─── */

        protected function configure(): void {
            $this
                ->addArgument('entity', InputArgument::OPTIONAL, 'Nom court (ex. Article) ou FQCN de l\'entité ; « * » = toutes les entités. Absent = menu interactif.')
                /* ── Options de destruction ─── */
                ->addOption('force', 'f', InputOption::VALUE_NONE, 'Écrase les fichiers existants et réinjecte les attributs #[ApiResource].')
                ->addOption('reinit', 'r', InputOption::VALUE_NONE, 'Utilisé avec --force : supprime les répertoires DTO/ et State/.')
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
                    HELP);
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
                        $io->error(sprintf(
                            'Aucune entité indiquée et aucun terminal disponible pour le menu. Précisez une entité ou « %s » pour toutes les entités.',
                            self::ALL_ENTITIES_ARGUMENT,
                        ));

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

                /* ── --reinit : nettoyage ciblé sur le FQCN résolu (ou global pour toutes les entités) ─── */
                if ($options->force && $options->reinit && !$options->isReadOnly()) {
                    $io->section('Nettoyage des répertoires générés...');
                    $this->generator->cleanBeforeReinit($entityArg !== null ? $entityClasses[0] : null, $io);
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

                $unrecognizedTypes = FieldAnalyser::getUnrecognizedTypes();

                if ($unrecognizedTypes !== []) {
                    $io->warning(sprintf(
                        'Types Doctrine non reconnus (fallback sur string) : %s. Ces types seront traités comme des chaînes dans les DTOs.',
                        implode(', ', $unrecognizedTypes),
                    ));
                }

                FieldAnalyser::resetUnrecognizedTypes();

                $this->summaryPrinter->print($io, $results, $total, $options);

                return Command::SUCCESS;
            }
            catch (Exception $e) {
                $io->newLine(2);
                $io->error('Erreur inattendue : ' . $e->getMessage());

                if ($io->isVerbose()) {
                    $io->text($e->getTraceAsString());
                }

                return Command::FAILURE;
            }
        }
    }
