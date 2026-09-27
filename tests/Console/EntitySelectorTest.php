<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Tests unitaires du menu interactif de sélection d'entité (EntitySelector).
     */

    declare(strict_types=1);

    namespace BlackSheep\Symfony\ApiResourceBundle\Tests\Console;

    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use BlackSheep\Symfony\ApiResourceBundle\Console\EntitySelector;

    /**
     * Vérifie le regroupement par namespace, la numérotation continue, l'option « All » en dernier,
     * et l'analyse de la réponse saisie (numéro ou mot « All »).
     */
    final class EntitySelectorTest extends TestCase {
        private function entities(): array {
            return [
                'App\\Entity\\Blog\\Comment', 'App\\Entity\\Blog\\Article', 'App\\Entity\\User', 'App\\Entity\\Blog\\Article', /* doublon volontaire */
            ];
        }

        /* ── Construction du menu ─── */

        public function testGroupsAreSortedByNamespaceAndEntitiesByShortName(): void {
            $menu = new EntitySelector()->buildMenu($this->entities());

            /* Tri STRING : « App\Entity » est un préfixe de « App\Entity\Blog » et le précède donc. */
            $this->assertSame(['App\\Entity', 'App\\Entity\\Blog'], array_keys($menu['groups']));
            $this->assertSame(['Article', 'Comment'], array_column($menu['groups']['App\\Entity\\Blog'], 'short'));
        }

        public function testNumberingIsContinuousAcrossGroups(): void {
            $menu    = new EntitySelector()->buildMenu($this->entities());
            $numbers = [];

            foreach ($menu['groups'] as $entities) {
                foreach ($entities as $entity) {
                    $numbers[] = $entity['number'];
                }
            }

            $this->assertSame([1, 2, 3], $numbers);
        }

        public function testAllOptionIsTheLastNumberAndDuplicatesAreCollapsed(): void {
            $menu = new EntitySelector()->buildMenu($this->entities());

            $this->assertSame(3, $menu['total']);
            $this->assertSame(4, $menu['allNumber']);
        }

        /* ── Analyse de la réponse ─── */

        public function testParseAnswerAcceptsANumberOrTheWordAllCaseInsensitive(): void {
            $selector = new EntitySelector();
            $menu     = $selector->buildMenu($this->entities());

            $this->assertSame(1, $selector->parseAnswer('1', $menu));
            $this->assertSame(4, $selector->parseAnswer('All', $menu));
            $this->assertSame(4, $selector->parseAnswer('all', $menu));
            $this->assertSame(4, $selector->parseAnswer(' ALL ', $menu));
        }

        public function testParseAnswerRejectsOutOfRangeOrNonNumericInput(): void {
            $selector = new EntitySelector();
            $menu     = $selector->buildMenu($this->entities());

            foreach (['0', '5', 'abc', '1.5', ''] as $answer) {
                try {
                    $selector->parseAnswer($answer, $menu);
                    $this->fail("« $answer » aurait dû être rejeté");
                }
                catch (InvalidArgumentException) {
                }
            }
        }

        /* ── Résolution du choix ─── */

        public function testEntityForNumberReturnsTheMatchingFqcn(): void {
            $selector = new EntitySelector();
            $menu     = $selector->buildMenu($this->entities());

            /* Groupe « App\Entity » (User) en premier, puis « App\Entity\Blog » (Article, Comment). */
            $this->assertSame('App\\Entity\\User', $selector->entityForNumber(1, $menu));
            $this->assertSame('App\\Entity\\Blog\\Comment', $selector->entityForNumber(3, $menu));
        }

        public function testEntityForNumberReturnsNullForAll(): void {
            $selector = new EntitySelector();
            $menu     = $selector->buildMenu($this->entities());

            $this->assertNull($selector->entityForNumber($menu['allNumber'], $menu));
        }

        public function testSingleEntityMenuHasAllAsNumberTwo(): void {
            $menu = new EntitySelector()->buildMenu(['App\\Entity\\Article']);

            $this->assertSame(1, $menu['total']);
            $this->assertSame(2, $menu['allNumber']);
        }
    }
