<?php

	/*
	 * Copyright (c) 2026.
	 * Date: 21/09/2026
	 * Author: Xavier KONGOLO <xsompwe@gmail.com>
	 * Description: Résumé console de l'exécution de la commande generate:resource.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Console;

	use Symfony\Component\Console\Style\SymfonyStyle;
	use SocioLink\ApiResourceBundle\Source\GenerationOptions;

	/**
	 * Affiche le résumé de l'exécution dans la console.
	 *
	 * Extrait de la commande pour qu'elle reste de l'orchestration pure.
	 *
	 * @internal Réservé à l'usage interne du bundle.
	 */
	final class SummaryPrinter {
		/**
		 * Affiche le résumé de l'exécution.
		 *
		 * @param array<string, array<string, mixed>> $results Résultats par entité (voir ResourceGeneratorInterface::processEntity())
		 * @param int                                 $total   Nombre d'entités traitées
		 *
		 * @return int Nombre d'erreurs (0 si tout s'est bien passé)
		 */
		public function print(SymfonyStyle $io, array $results, int $total, GenerationOptions $options): int {
			$modified             = 0;
			$created              = 0;
			$skipped              = 0;
			$errors               = 0;
			$totalBooleans        = 0;
			$totalUploads         = 0;
			$entitiesWithBooleans = 0;
			$entitiesWithUploads  = 0;

			foreach ($results as $result) {
				/** @var array{booleanCount: int, uploadCount: int} $stats */
				$stats = $result['_stats'] ?? ['booleanCount' => 0, 'uploadCount' => 0];

				if ($stats['booleanCount'] > 0) {
					$totalBooleans += $stats['booleanCount'];
					$entitiesWithBooleans++;
				}

				if ($stats['uploadCount'] > 0) {
					$totalUploads += $stats['uploadCount'];
					$entitiesWithUploads++;
				}

				match ($result['entity'] ?? 'error') {
					'skipped'            => $skipped++,
					'not_found', 'error' => $errors++,
					default              => $modified++,
				};

				foreach ($result as $key => $value) {
					if ($key === 'entity' || $key === '_stats' || !is_string($value)) {
						continue;
					}

					match ($value) {
						'skipped'            => $skipped++,
						'error', 'not_found' => $errors++,
						default              => $created++,
					};
				}
			}

			$mode = match (true) {
				$options->onlyResource  => '--only-resource',
				$options->withProvider  => '--with-provider',
				$options->toggleBoolean => '--toggle-boolean',
				$options->detachBoolean => '--detach-boolean',
				$options->all           => '--all',
				default                 => 'défaut',
			};

			if ($errors > 0) {
				$io->warning(sprintf('Génération terminée avec %d erreur(s).', $errors));
			}
			elseif ($options->isReadOnly()) {
				$io->title('Aperçu de la génération (aucun fichier écrit)');
			}
			else {
				$io->success('Génération terminée avec succès !');
			}

			$io->writeln('');
			$io->writeln(sprintf('<comment>Mode :</comment> %s', $mode));

			if ($options->withTests) {
				$io->writeln('<comment>Tests :</comment> fonctionnels ApiTestCase générés');
			}

			if ($options->subResources) {
				$io->writeln('<comment>Sous-ressources :</comment> OneToMany incluses');
			}

			if ($options->graphqlFilters) {
				$io->writeln('<comment>GraphQL :</comment> paramètres de filtrage reportés sur QueryCollection');
			}

			if ($options->withMercure) {
				$io->writeln(sprintf('<comment>Mercure :</comment> %s', $options->publicMercure ? 'public (mercure: true)' : 'privé (mercure: [\'private\' => true])'));
			}

			$io->writeln('');
			$io->writeln('<comment>Résumé :</comment>');
			$io->writeln(sprintf('  • %d entité(s) traitée(s)', $total));
			$io->writeln($totalUploads > 0
				             ? sprintf('  • %d champ(s) File dans %d entité(s)', $totalUploads, $entitiesWithUploads)
				             : '  • Aucun champ File détecté');
			$io->writeln($totalBooleans > 0
				             ? sprintf('  • %d champ(s) booléen(s) dans %d entité(s)', $totalBooleans, $entitiesWithBooleans)
				             : '  • Aucun champ booléen détecté');
			$io->writeln(sprintf('  • %d entité(s) modifiée(s) — #[ApiResource] injecté', $modified));
			$io->writeln(sprintf('  • %d fichier(s) créé(s) — DTOs · Processors · Providers', $created));
			$io->writeln(sprintf('  • %d fichier(s) ignoré(s) — utiliser --force pour écraser', $skipped));

			if ($errors > 0) {
				$io->writeln(sprintf('  • <error>%d erreur(s) — vérifiez les droits et les logs</error>', $errors));
			}

			$io->newLine();

			return $errors;
		}
	}
