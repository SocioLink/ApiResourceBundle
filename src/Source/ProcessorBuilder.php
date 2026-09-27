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
     * Génère le code source PHP des State Processors pour API Platform.
     */
    final class ProcessorBuilder {
        /**
         * @param FieldAnalyser   $fieldAnalyser
         * @param GeneratorConfig $config Champs booléens spéciaux et rôle d'administration
         * @param Environment     $twig   Moteur de gabarits Twig dédié du bundle
         */
        public function __construct(
            private readonly FieldAnalyser   $fieldAnalyser,
            private readonly GeneratorConfig $config,
            #[Autowire(service: 'black_sheep_api_resource.twig')] private readonly Environment $twig,
        ) {}

        public function buildCreateProcessor(string $entityClass, string $entityName, string $stateNs, string $dtoNs, array $fields): string {
            /*
             * Imports de base communs à tout Processor de création : interfaces API Platform,
             * l'entité cible et son CreateDto.
             */
            $uses = [
                'use ApiPlatform\Metadata\Operation;',
                'use ApiPlatform\State\ProcessorInterface;',
                'use ApiPlatform\Validator\Exception\ValidationException as ApiValidationException;',
                'use Doctrine\ORM\EntityManagerInterface;',
                'use ReflectionObject;',
                'use Symfony\Component\Uid\Uuid;',
                'use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;',
                'use ' . $entityClass . ';',
                'use ' . $dtoNs . '\\' . $entityName . 'CreateDto;',
                'use Symfony\Component\DependencyInjection\Attribute\Autowire;',
                'use Symfony\Component\Validator\Validator\ValidatorInterface;',
            ];

            $uses = array_values(array_unique($uses));
            sort($uses);
            $usesBlock = implode("\n", $uses);

            return $this->twig->render('create_processor.php.twig', [
                'namespace'   => $stateNs,
                'uses_block'  => $usesBlock,
                'entity_name' => $entityName,
                'needs_em'    => true,
            ]);
        }

        public function buildUpdateProcessor(string $entityClass, string $entityName, string $stateNs, string $dtoNs, array $fields, GenerationOptions $options): string {
            /* Même principe que buildCreateProcessor() (EntityManagerInterface inclus pour le flush), mais avec l'UpdateDto (PATCH) en lieu et place du CreateDto. */
            $uses = [
                'use ApiPlatform\Metadata\Operation;',
                'use ApiPlatform\State\ProcessorInterface;',
                'use ApiPlatform\Validator\Exception\ValidationException as ApiValidationException;',
                'use Doctrine\ORM\EntityManagerInterface;',
                'use ReflectionObject;',
                'use Symfony\Component\Uid\Uuid;',
                'use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;',
                'use ' . $entityClass . ';',
                'use ' . $dtoNs . '\\' . $entityName . 'UpdateDto;',
                'use Symfony\Component\DependencyInjection\Attribute\Autowire;',
                'use Symfony\Component\Validator\Validator\ValidatorInterface;',
            ];

            $uses = array_values(array_unique($uses));
            sort($uses);
            $usesBlock = implode("\n", $uses);

            /*
             * $options est accepté en paramètre mais n'est en réalité plus utilisé directement
             * ici (la logique d'inclusion/exclusion des booléens a été déplacée côté DtoBuilder) ;
             * il est conservé pour homogénéité de signature avec DtoBuilder::buildUpdateDto()
             * et pour une éventuelle évolution future du template update_processor.php.twig.
             */
            return $this->twig->render('update_processor.php.twig', [
                'namespace'   => $stateNs,
                'uses_block'  => $usesBlock,
                'entity_name' => $entityName,
                'needs_em'    => true,
            ]);
        }

        public function buildToggleProcessor(string $entityClass, string $entityName, string $stateNs, string $dtoNs, array $booleanFields): string {
            /*
             * itDeleted/itErased portent une sémantique métier sensible (soft-delete /
             * soft-erase) : leur bascule doit être protégée par un contrôle de sécurité
             * dédié dans le Processor généré (cf. $needsSecurity ci-dessous), contrairement
             * aux booléens "standards" qui peuvent être togglés librement par tout utilisateur autorisé à PATCH.
             */
            $hasItDeleted  = isset($booleanFields['itDeleted']);
            $hasItErased   = isset($booleanFields['itErased']);
            $needsSecurity = $hasItDeleted || $hasItErased;

            /*
             * Liste des champs à exclure de la réflexion générique sur les booléens dans le
             * template (itDeleted/itErased sont traités par des blocs dédiés avec sécurité,
             * pas par la boucle générique "pour chaque booléen, basculer si non-null").
             */
            $excludeList = implode(', ', array_map(static fn(string $f): string => "'{$f}'", $this->config->booleanSpecialFields));

            $uses = [
                'use ReflectionObject;',
                'use Symfony\Component\Uid\Uuid;',
                'use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;',
                'use ApiPlatform\Metadata\Operation;',
                'use ApiPlatform\State\ProcessorInterface;',
                'use ApiPlatform\Validator\Exception\ValidationException as ApiValidationException;',
                'use Doctrine\ORM\EntityManagerInterface;',
                'use ' . $entityClass . ';',
                'use ' . $dtoNs . '\\' . $entityName . 'ToggleDto;',
                'use Symfony\Component\DependencyInjection\Attribute\Autowire;',
                'use Symfony\Component\Validator\Validator\ValidatorInterface;',
            ];

            /*
             * Imports conditionnels : Security et les exceptions HTTP associées ne sont
             * ajoutés que si le Toggle manipule réellement itDeleted/itErased — un Processor
             * de Toggle sur des booléens purement "métier" n'a pas besoin de cette dépendance,
             * ce qui évite des `use` inutiles et des avertissements d'analyse statique.
             */
            if ($needsSecurity) {
                $uses[] = 'use Symfony\Bundle\SecurityBundle\Security as AppSecurity;';
                $uses[] = 'use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException as AppAccessDeniedException;';
                $uses[] = 'use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException as AppUnprocessableException;';
            }

            /* array_unique() avant sort() : certains use auraient pu théoriquement être dupliqués si appelés via plusieurs chemins de génération. */
            $uses = array_values(array_unique($uses));
            sort($uses);
            $usesBlock = implode("\n", $uses);

            return $this->twig->render('toggle_processor.php.twig', [
                'namespace'      => $stateNs,
                'uses_block'     => $usesBlock,
                'entity_name'    => $entityName,
                'has_it_deleted' => $hasItDeleted,
                'has_it_erased'  => $hasItErased,
                'exclude_list'   => $excludeList,
                'needs_security' => $needsSecurity,
                'needs_em'       => true,
                'admin_role'     => $this->config->adminRole,
            ]);
        }

        public function buildSingleBooleanProcessor(string $entityClass, string $entityName, string $stateNs, string $dtoNs, string $fieldName, string $pascalName): string {
            $dtoClass = "{$entityName}{$pascalName}Dto";
            /*
             * in_array(..., true) : comparaison stricte sur la liste des champs booléens
             * "spéciaux" (itDeleted, itErased) définie une fois pour toutes dans
             * GeneratorConfig::$booleanSpecialFields — seuls ces deux champs déclenchent l'ajout des imports
             * de sécurité, qu'ils soient traités ici en mode --detach-boolean ou via
             * le ToggleProcessor en mode --toggle-boolean.
             */
            $needsSecurity = in_array($fieldName, $this->config->booleanSpecialFields, true);

            $uses = [
                'use ReflectionObject;',
                'use Symfony\Component\Uid\Uuid;',
                'use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;',
                'use ApiPlatform\Metadata\Operation;',
                'use ApiPlatform\State\ProcessorInterface;',
                'use ApiPlatform\Validator\Exception\ValidationException as ApiValidationException;',
                'use Doctrine\ORM\EntityManagerInterface;',
                'use ' . $entityClass . ';',
                'use ' . $dtoNs . '\\' . $dtoClass . ';',
                'use Symfony\Component\DependencyInjection\Attribute\Autowire;',
                'use Symfony\Component\Validator\Validator\ValidatorInterface;',
            ];

            if ($needsSecurity) {
                $uses[] = 'use Symfony\Bundle\SecurityBundle\Security as AppSecurity;';
                $uses[] = 'use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException as AppAccessDeniedException;';
                $uses[] = 'use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException as AppUnprocessableException;';
            }

            $uses = array_values(array_unique($uses));
            sort($uses);
            $usesBlock = implode("\n", $uses);

            return $this->twig->render('single_boolean_processor.php.twig', [
                'namespace'      => $stateNs,
                'uses_block'     => $usesBlock,
                'entity_name'    => $entityName,
                'dto_class'      => $dtoClass,
                'field_name'     => $fieldName,
                'pascal_name'    => $pascalName,
                'needs_security' => $needsSecurity,
                'needs_em'       => true,
                'admin_role'     => $this->config->adminRole,
            ]);
        }

        public function buildUploadProcessor(string $entityClass, string $entityName, string $stateNs, string $dtoNs, array $uploadField, bool $hasUpdatedAt): string {
            /*
             * $uploadField provient de FieldAnalyser::detectUploadFields() : un tableau
             * associatif déjà entièrement préparé (suffixe de propriété, nom de champ,
             * segment d'URI kebab-case). Ce Builder se contente de le déstructurer.
             */
            $propSuffix = $uploadField['propSuffix'];
            $fieldName  = $uploadField['fieldName'];
            $dtoClass   = $entityName . 'Upload' . $propSuffix . 'Dto';
            $uriSegment = $uploadField['uriSegment'];

            $uses = [
                'use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;',
                'use ApiPlatform\Metadata\Operation;',
                'use ApiPlatform\State\ProcessorInterface;',
                'use ApiPlatform\Validator\Exception\ValidationException as ApiValidationException;',
                'use Doctrine\ORM\EntityManagerInterface;',
                'use ' . $entityClass . ';',
                'use ' . $dtoNs . '\\' . $dtoClass . ';',
                'use Symfony\Component\DependencyInjection\Attribute\Autowire;',
                'use Symfony\Component\Validator\Validator\ValidatorInterface;',
            ];

            if ($hasUpdatedAt) {
                $uses[] = 'use DateTimeImmutable;';
                $uses[] = 'use Symfony\Component\HttpFoundation\Request;';
            }
            else {
                $uses[] = 'use Symfony\Component\HttpFoundation\Request;';
            }

            $uses = array_values(array_unique($uses));
            sort($uses);
            $usesBlock = implode("\n", $uses);

            /*
             * $hasUpdatedAt est transmis au template pour qu'il sache s'il doit régénérer
             * automatiquement updatedAt après l'upload (cohérence avec les autres mutations
             * de l'entité qui mettent toutes à jour ce timestamp).
             */
            return $this->twig->render('upload_processor.php.twig', [
                'namespace'      => $stateNs,
                'uses_block'     => $usesBlock,
                'entity_name'    => $entityName,
                'field_name'     => $fieldName,
                'dto_class'      => $dtoClass,
                'uri_segment'    => $uriSegment,
                'has_updated_at' => $hasUpdatedAt,
                'prop_suffix'    => $propSuffix,
            ]);
        }

    }
