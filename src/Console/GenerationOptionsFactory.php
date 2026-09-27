<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Construction et validation de GenerationOptions depuis l'entrée de la commande.
     */

    declare(strict_types=1);

    namespace SocioLink\ApiResourceBundle\Console;

    use SocioLink\ApiResourceBundle\Source\GenerationOptions;
    use Symfony\Component\Console\Input\InputInterface;

    /**
     * Construit l'objet {@see GenerationOptions} depuis les options de la commande et en valide la cohérence.
     *
     * Règles vérifiées :
     *  1. les options mutuellement exclusives ne peuvent pas être combinées ;
     *  2. --reinit exige --force ;
     *  3. --public exige --with-mercure ;
     *  4. --preview implique --dry-run (géré par GenerationOptions::fromFlags()).
     *
     * @internal Réservé à l'usage interne du bundle.
     */
    final readonly class GenerationOptionsFactory {
        /** Options mutuellement exclusives : une seule peut être active. */
        public const array EXCLUSIVE_OPTIONS = ['only-resource', 'with-provider', 'toggle-boolean', 'detach-boolean', 'all'];

        /*
         * Construit les options de génération.
         *
         * Lève InvalidOptionsException, avec un message affichable, si la combinaison est invalide.
         */
        public function create(InputInterface $input): GenerationOptions {
            $active = array_values(array_filter(
                self::EXCLUSIVE_OPTIONS,
                static fn(string $option): bool => (bool)$input->getOption($option),
            ));

            if (count($active) > 1) {
                throw new InvalidOptionsException(sprintf(
                    'Les options --%s sont mutuellement exclusives. Utilisez-en une seule à la fois.',
                    implode(', --', $active),
                ));
            }

            if ($input->getOption('reinit') && !$input->getOption('force')) {
                throw new InvalidOptionsException('L\'option --reinit doit être accompagnée de --force.');
            }

            if ($input->getOption('public') && !$input->getOption('with-mercure')) {
                throw new InvalidOptionsException('L\'option --public doit être accompagnée de --with-mercure.');
            }

            return GenerationOptions::fromFlags(
                force         : (bool)$input->getOption('force'),
                reinit        : (bool)$input->getOption('reinit'),
                onlyResource  : (bool)$input->getOption('only-resource'),
                withProvider  : (bool)$input->getOption('with-provider'),
                toggleBoolean : (bool)$input->getOption('toggle-boolean'),
                detachBoolean : (bool)$input->getOption('detach-boolean'),
                all           : (bool)$input->getOption('all'),
                dryRun        : (bool)$input->getOption('dry-run'),
                preview       : (bool)$input->getOption('preview'),
                interactive   : (bool)$input->getOption('interactive'),
                withTests     : (bool)$input->getOption('with-tests'),
                subResources  : (bool)$input->getOption('sub-resources'),
                graphqlFilters: (bool)$input->getOption('graphql-filters'),
                withMercure   : (bool)$input->getOption('with-mercure'),
                publicMercure : (bool)$input->getOption('public'),
            );
        }
    }
