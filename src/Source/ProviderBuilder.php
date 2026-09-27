<?php

/*
 * Copyright (c) 2026.
 * Date: 27/08/2026 12:50
 * Author: Xavier KONGOLO <xsompwe@gmail.com>
 * Description: Générateur de State Providers pour API Platform.
 */

declare(strict_types=1);

namespace SocioLink\ApiResourceBundle\Source;

use Twig\Environment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Génère le code source PHP des State Providers pour l'opération GET {id} d'API Platform.
 *
 * @internal Réservé à l'usage interne de la commande generate:resource.
 */
final class ProviderBuilder {
    /**
     * @param Environment $twig Moteur de gabarits Twig dédié du bundle
     */
    public function __construct(#[Autowire(service: 'sociolink_api_resource.twig')] private readonly Environment $twig) {}

    /* ── Provider GET {id} ─── */

    /**
     * Génère le provider de lecture unitaire — opération GET /api/{resources}/{id}.
     *
     * @param string               $entityClass FQCN complet de l'entité (ex. App\Entity\Article)
     * @param string               $entityName  Nom court de l'entité (ex. 'Article')
     * @param string               $stateNs     Namespace PHP du dossier State (ex. App\State\Article)
     * @param array<string, mixed> $fields
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
            if (is_array($info) && ($info['isRelation'] ?? false) && !($info['isToMany'] ?? false)) {
                $eagerRelations[] = [
                    'field' => $fieldName,
                    'alias' => lcfirst((string)$fieldName),
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
