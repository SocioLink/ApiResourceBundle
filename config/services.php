<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Services du bundle BlackSheep\Symfony\ApiResourceBundle.
     */

    declare(strict_types=1);

    use Twig\Environment;
    use BlackSheep\Symfony\ApiResourceBundle\Source\DtoBuilder;
    use BlackSheep\Symfony\ApiResourceBundle\Source\TestBuilder;
    use BlackSheep\Symfony\ApiResourceBundle\Source\FieldAnalyser;
    use BlackSheep\Symfony\ApiResourceBundle\Source\GeneratorConfig;
    use BlackSheep\Symfony\ApiResourceBundle\Source\ProviderBuilder;
    use BlackSheep\Symfony\ApiResourceBundle\Source\EntityDiscovery;
    use BlackSheep\Symfony\ApiResourceBundle\Console\EntitySelector;
    use BlackSheep\Symfony\ApiResourceBundle\Console\SummaryPrinter;
    use BlackSheep\Symfony\ApiResourceBundle\Source\ProcessorBuilder;
    use BlackSheep\Symfony\ApiResourceBundle\Source\NamespaceResolver;
    use BlackSheep\Symfony\ApiResourceBundle\Twig\TwigEnvironmentFactory;
    use BlackSheep\Symfony\ApiResourceBundle\Source\EntityAttributeInjector;
    use BlackSheep\Symfony\ApiResourceBundle\Source\FilterDefinitionBuilder;
    use BlackSheep\Symfony\ApiResourceBundle\Console\GenerationOptionsFactory;
    use BlackSheep\Symfony\ApiResourceBundle\Source\EntityDiscoveryInterface;
    use BlackSheep\Symfony\ApiResourceBundle\Command\GenerateResourceCommand;
    use BlackSheep\Symfony\ApiResourceBundle\Service\GenerateResourceService;
    use BlackSheep\Symfony\ApiResourceBundle\Source\ResourceGeneratorInterface;
    use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

    use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

    /*
     * Services du bundle : liste EXPLICITE (pas de chargement par glob) pour ne jamais enregistrer par
     * mégarde une classe utilitaire. Tous les services sont privés ; seule la commande est autoconfigurée
     * (tag console.command déduit de #[AsCommand]).
     */
    return static function (ContainerConfigurator $container): void {
        $services = $container->services()->defaults()->autowire()->private();

        /* ── Services de génération ─── */
        foreach ([
                     NamespaceResolver::class, FieldAnalyser::class, FilterDefinitionBuilder::class, DtoBuilder::class,
                     ProcessorBuilder::class, ProviderBuilder::class, TestBuilder::class, EntityAttributeInjector::class,
                     EntityDiscovery::class, GenerateResourceService::class,
                     GenerationOptionsFactory::class, EntitySelector::class, SummaryPrinter::class,
                 ] as $class) {
            $services->set($class);
        }

        /* Les contrats sont résolus vers leur unique implémentation (la commande dépend des contrats). */
        $services->alias(ResourceGeneratorInterface::class, GenerateResourceService::class);
        $services->alias(EntityDiscoveryInterface::class, EntityDiscovery::class);

        /* ── Moteur de gabarits dédié ─── */
        $services->set('black_sheep_api_resource.twig', Environment::class)
            ->factory([TwigEnvironmentFactory::class, 'create'])
            ->args([dirname(__DIR__) . '/templates', service(GeneratorConfig::class)]);

        /* ── Commande ─── */
        $services->set(GenerateResourceCommand::class)->autoconfigure();
    };
