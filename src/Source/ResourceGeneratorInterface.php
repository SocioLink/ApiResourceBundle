<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Contrat de l'orchestrateur de génération des artefacts d'une entité.
     */

    declare(strict_types=1);

    namespace BlackSheep\Symfony\ApiResourceBundle\Source;

    use Symfony\Component\Console\Style\SymfonyStyle;

    /**
     * Contrat de l'orchestrateur de génération : traitement d'une entité et nettoyage --reinit.
     *
     * La commande dépend de ce contrat plutôt que de la classe concrète, ce qui permet de la tester
     * (menu interactif, validation des options) sans base de données ni écriture disque.
     */
    interface ResourceGeneratorInterface {
        /*
         * Supprime les répertoires générés avant une régénération complète (--force --reinit).
         *
         * Paramètre $entityClass : FQCN de l'entité ciblée, ou null pour tout supprimer.
         */
        public function cleanBeforeReinit(string|null $entityClass, SymfonyStyle $io): void;

        /*
         * Traite une entité et retourne le résultat de chaque opération.
         *
         * Retour : tableau de résultats par artefact ('entity', 'createDto', …) et '_stats'.
         */
        public function processEntity(string $entityClass, GenerationOptions $options, SymfonyStyle $io): array;
    }
