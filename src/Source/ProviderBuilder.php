<?php


    /*
     * Copyright (c) 2026.
     * Date: 27/08/2026 12:50
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description:
     */

    declare(strict_types=1);

    /*
     * Copyright (c) 2026.
     * Date: 22/08/2026 14:12
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description:
     */

    namespace BlackSheep\Symfony\ApiResourceBundle\Source;

    use Twig\Environment;
    use Symfony\Component\DependencyInjection\Attribute\Autowire;

    /**
     * Génère le code source PHP des State Providers pour l'opération GET {id} d'API Platform.
     *
     * Un Provider est généré uniquement lorsque l'option --with-provider est active.
     * L'opération GET Collection reste gérée nativement par API Platform (CollectionProvider Doctrine).
     *
     * Le Provider généré :
     *  - implémente {@see \ApiPlatform\State\ProviderInterface} ;
     *  - délègue le chargement de l'entité à {@see \App\Service\Application\AppService::findOrFail()} ;
     *  - ne contient pas de logique métier — étendre manuellement si nécessaire.
     *
     * @internal Réservé à l'usage interne de la commande generate:resource.
     */
    final class ProviderBuilder {
        /**
         * @param Environment $twig Moteur de gabarits Twig dédié du bundle
         */
        public function __construct(#[Autowire(service: 'black_sheep_api_resource.twig')] private readonly Environment $twig) {}

        /* ── Provider GET {id} ─── */

        /**
         * Génère le provider de lecture unitaire — opération GET /api/{resources}/{id}.
         *
         * Le provider charge l'entité par son UUID via AppService::findOrFail() et la retourne
         * directement à API Platform pour sérialisation. Les champs retournés sont déterminés
         * par les groupes de normalisation configurés sur l'entité.
         *
         * Les champs status, itDeleted et itErased sont inclus dans la réponse sérialisée
         * si leurs groupes de normalisation le permettent — aucun traitement particulier
         * n'est nécessaire dans le provider lui-même.
         *
         * @param string $entityClass FQCN complet de l'entité (ex. App\Entity\Article)
         * @param string $entityName  Nom court de l'entité (ex. 'Article')
         * @param string $stateNs     Namespace PHP du dossier State (ex. App\State\Article)
         *
         * @return string Code source PHP complet du fichier {$entityName}Provider.php
         * @throws \Twig\Error\LoaderError
         * @throws \Twig\Error\RuntimeError
         * @throws \Twig\Error\SyntaxError
         */
        public function buildGetProvider(
            string $entityClass,
            string $entityName,
            string $stateNs,
            array  $fields = [],
        ): string {
            /* Relations ToOne pour eager loading (évite le problème N+1) */
            $eagerRelations = [];

            foreach ($fields as $fieldName => $info) {
                if ($info['isRelation'] && !($info['isToMany'] ?? false)) {
                    $eagerRelations[] = [
                        'field' => $fieldName,
                        'alias' => lcfirst($fieldName),
                    ];
                }
            }

            $uses = [
                'use ApiPlatform\Metadata\Operation;',
                'use ApiPlatform\State\ProviderInterface;',
                'use Doctrine\ORM\EntityManagerInterface;',
                'use Symfony\Component\Uid\Uuid;',
                'use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;',
                'use ' . $entityClass . ';',
            ];

            $uses = array_values(array_unique($uses));
            sort($uses);
            $usesBlock = implode("\n", $uses);

            return $this->twig->render('get_provider.php.twig', [
                'namespace'       => $stateNs,
                'uses_block'      => $usesBlock,
                'entity_name'     => $entityName,
                'eager_relations' => $eagerRelations,
            ]);
        }
    }
