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
	 * Description: Menu interactif de sélection de l'entité à traiter, affiché par generate:resource lorsqu'aucune entité
	 *              n'est indiquée dans un terminal.
	 *              Les entités sont regroupées par espace de noms (ordre alphabétique), triées par nom court et numérotées
	 *              en continu ; la dernière option, « All », désigne toutes les entités. Le choix est unique ; la réponse
	 *              peut être un numéro ou le mot « All » (sans tenir compte de la casse), et une saisie invalide est
	 *              redemandée.
	 *              La construction du menu (buildMenu), l'analyse de la réponse (parseAnswer) et la résolution du choix
	 *              (entityForNumber) sont des fonctions pures, testables sans terminal ; seules render() et choose()
	 *              dialoguent avec l'utilisateur.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Console;

	use InvalidArgumentException;
	use Symfony\Component\Console\Style\SymfonyStyle;
	use Symfony\Component\Console\Formatter\OutputFormatter;

	/**
	 * Menu interactif de sélection d'une entité.
	 *
	 * Les entités sont regroupées par namespace (ordre alphabétique) et numérotées en continu.
	 * La DERNIÈRE option, « All », désigne toutes les entités existantes. Le choix est unique.
	 *
	 * La construction du menu et l'analyse de la réponse sont des fonctions pures, testables sans
	 * terminal ; seule choose() dialogue avec l'utilisateur.
	 *
	 * @phpstan-type MenuItem array{number: int, fqcn: string, short: string}
	 * @phpstan-type MenuStructure array{groups: array<string, list<MenuItem>>, allNumber: int, total: int}
	 *
	 * @internal Réservé à l'usage interne du bundle.
	 */
	final class EntitySelector {
		/** Libellé de la dernière option du menu. */
		public const string ALL_LABEL = 'All';

		/* ── Construction du menu ─── */

		/**
		 * Construit le menu à partir de la liste des FQCN d'entités.
		 *
		 * Regroupement par namespace trié alphabétiquement, entités triées par nom court dans chaque
		 * groupe, numérotation continue à partir de 1, puis « All » en dernier.
		 *
		 * @param list<string> $entityClasses
		 *
		 * @return MenuStructure
		 */
		public function buildMenu(array $entityClasses): array {
			$byNamespace = [];

			foreach (array_unique($entityClasses) as $fqcn) {
				$position  = strrpos($fqcn, '\\');
				$namespace = $position === false ? '' : substr($fqcn, 0, $position);
				$short     = $position === false ? $fqcn : substr($fqcn, $position + 1);

				$byNamespace[$namespace][] = compact('fqcn', 'short');
			}

			ksort($byNamespace, SORT_STRING);

			$groups = [];
			$number = 0;

			foreach ($byNamespace as $namespace => $entities) {
				usort($entities, static fn(array $a, array $b): int => strcmp($a['short'], $b['short']));

				foreach ($entities as $entity) {
					$groups[$namespace][] = ['number' => ++$number, 'fqcn' => $entity['fqcn'], 'short' => $entity['short']];
				}
			}

			return ['groups' => $groups, 'allNumber' => $number + 1, 'total' => $number];
		}

		/* ── Analyse de la réponse ─── */

		/**
		 * Convertit la réponse saisie en numéro de menu.
		 *
		 * Accepte un entier compris entre 1 et le numéro de « All », ou le mot « All » (sans tenir
		 * compte de la casse). Lève InvalidArgumentException sinon : Symfony redemande alors la saisie.
		 *
		 * @param MenuStructure $menu
		 */
		public function parseAnswer(string $answer, array $menu): int {
			$answer = trim($answer);

			if (strcasecmp($answer, self::ALL_LABEL) === 0) {
				return $menu['allNumber'];
			}

			if (!ctype_digit($answer) || (int)$answer < 1 || (int)$answer > $menu['allNumber']) {
				throw new InvalidArgumentException(sprintf('Saisissez un numéro compris entre 1 et %d.', $menu['allNumber']));
			}

			return (int)$answer;
		}

		/**
		 * Retourne le FQCN correspondant à un numéro de menu, ou null pour l'option « All ».
		 *
		 * @param MenuStructure $menu
		 */
		public function entityForNumber(int $number, array $menu): string|null {
			if ($number === $menu['allNumber']) {
				return null;
			}

			foreach ($menu['groups'] as $entities) {
				foreach ($entities as $entity) {
					if ($entity['number'] === $number) {
						return $entity['fqcn'];
					}
				}
			}

			throw new InvalidArgumentException(sprintf('Aucune entité ne porte le numéro %d.', $number));
		}

		/* ── Affichage et saisie ─── */

		/**
		 * Affiche le menu numéroté regroupé par namespace, « All » en dernier.
		 *
		 * @param MenuStructure $menu
		 */
		public function render(array $menu, SymfonyStyle $io): void {
			$width = strlen((string)$menu['allNumber']);

			$io->section('Sélection de l\'entité');

			foreach ($menu['groups'] as $namespace => $entities) {
				$io->writeln(sprintf('<comment>%s</comment>', OutputFormatter::escape($namespace === '' ? '(sans namespace)' : $namespace)));

				foreach ($entities as $entity) {
					$io->writeln(sprintf('  [%s] %s', str_pad((string)$entity['number'], $width, ' ', STR_PAD_LEFT), OutputFormatter::escape($entity['short'])));
				}
			}

			/* Dernière option : toutes les entités existantes. */
			str_pad((string)$menu['allNumber'], $width, ' ', STR_PAD_LEFT)
			|> (static fn($x) => sprintf('  [%s] <info>%s</info> — toutes les entités (%d)', $x, self::ALL_LABEL, $menu['total']))
			|> $io(...);
			$io->newLine();
		}

		/**
		 * Affiche le menu et demande un numéro à l'utilisateur (choix unique).
		 *
		 * À n'appeler que si un terminal est disponible (entrée interactive).
		 *
		 * Retour : FQCN de l'entité choisie, ou null si l'option « All » est choisie.
		 *
		 * @param list<string> $entityClasses
		 */
		public function choose(array $entityClasses, SymfonyStyle $io): ?string {
			$menu = $this->buildMenu($entityClasses);

			$this->render($menu, $io);

			$number = $io->ask(
				sprintf('Numéro de votre choix (1-%d)', $menu['allNumber']),
				null,
				fn(mixed $answer): int => $this->parseAnswer((string)$answer, $menu),
			);

			return $this->entityForNumber((int)$number, $menu);
		}
	}
