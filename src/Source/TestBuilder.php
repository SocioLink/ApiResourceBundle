<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Générateur de tests fonctionnels ApiTestCase pour les entités API Platform.
     */

    declare(strict_types=1);

    namespace BlackSheep\Symfony\ApiResourceBundle\Source;

    use Twig\Environment;
    use Symfony\Component\DependencyInjection\Attribute\Autowire;

    /**
     * Génère les fichiers de test fonctionnel ApiTestCase pour les endpoints créés.
     *
     * Chaque entité traitée obtient un fichier de test contenant :
     *  - un test GET Collection (listage) ;
     *  - un test POST (création avec données valides) et un test POST avec données invalides ;
     *  - un test PATCH (mise à jour partielle) ;
     *  - un test GET Item (lecture unitaire) ;
     *  - des tests des paramètres de filtrage générés (requêtes valides acceptées, direction de tri invalide
     *    rejetée en 422), construits depuis les mêmes définitions que celles injectées dans l'entité.
     *
     * Le code généré cible API Platform 5.0 : les utilitaires de test sont dans le paquet
     * `api-platform/test` (namespace ApiPlatform\Test).
     *
     * @internal Réservé à l'usage interne de la commande generate:resource.
     */
    final class TestBuilder {
        /* $twig est le moteur de gabarits dédié du bundle (service black_sheep_api_resource.twig) ; $config fournit les champs système exclus du POST de test. */
        public function __construct(
            private readonly NamespaceResolver       $namespaceResolver,
            private readonly FilterDefinitionBuilder $filterBuilder,
            private readonly GeneratorConfig         $config,
            #[Autowire(service: 'black_sheep_api_resource.twig')] private readonly Environment $twig,
        ) {}

        /*
         * Génère le fichier de test fonctionnel pour une entité.
         *
         * Paramètre $entityClass : FQCN complet de l'entité.
         * Paramètre $entityName  : nom court de l'entité.
         * Paramètre $testNs      : namespace du fichier de test.
         * Paramètre $fields      : champs de l'entité (FieldAnalyser::getEntityFields()).
         * Paramètre $dtoNs       : namespace des DTOs.
         *
         * Retour : code PHP complet du fichier de test.
         */
        public function buildFunctionalTest(
            string $entityClass,
            string $entityName,
            string $testNs,
            array  $fields,
            string $dtoNs,
        ): string {
            /* routePrefix (ex. '/blog') + segment pluralisé : l'URL réelle de la collection. */
            $uriBase = ltrim($this->namespaceResolver->getRoutePrefix($entityClass) . '/' . $this->namespaceResolver->toApiPlatformUriBase($entityName), '/');

            /* Champs de création obligatoires : non-nullables, hors clé, hors champs système, hors relations. */
            $requiredFields = [];

            foreach ($fields as $fieldName => $info) {
                if ($fieldName === 'id' || in_array($fieldName, $this->config->systemFields, true)) {
                    continue;
                }

                if ($info['isRelation'] || $info['nullable']) {
                    continue;
                }

                $requiredFields[] = ['name' => (string)$fieldName, 'value' => $this->sampleValue((string)$fieldName, $info)];
            }

            $examples = $this->filterBuilder->buildQueryExamples($this->filterBuilder->buildDefinitions($fields));

            $toRow = fn(array $example): array => ['label' => $example['label'], 'query' => $this->exportArray($example['query'])];

            $uses = ['use ApiPlatform\\Test\\ApiTestCase;'];

            if ($examples['valid'] !== [] || $examples['invalid'] !== []) {
                $uses[] = 'use PHPUnit\\Framework\\Attributes\\DataProvider;';
            }

            sort($uses);

            return $this->twig->render('functional_test.php.twig', [
                'namespace'       => $testNs,
                'uses_block'      => implode("\n", $uses),
                'entity_name'     => $entityName,
                'dto_ns'          => $dtoNs,
                'uri_base'        => $uriBase,
                'required_fields' => $requiredFields,
                'valid_filters'   => array_map($toRow, $examples['valid']),
                'invalid_filters' => array_map($toRow, $examples['invalid']),
            ]);
        }

        /*
         * Retourne un littéral PHP valide pour le champ, selon son type Doctrine.
         *
         * Une chaîne partout (ancien comportement) faisait échouer le POST en 422 pour les entiers,
         * booléens, dates et UUID.
         */
        private function sampleValue(string $fieldName, array $info): string {
            $type = strtolower((string)$info['doctrineType']);

            return match (true) {
                in_array($type, ['integer', 'smallint', 'bigint'], true)                                                                 => '1',
                in_array($type, ['float', 'decimal'], true)                                                                              => '1.5',
                $type === 'boolean'                                                                                                      => 'true',
                in_array($type, ['datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable', 'date', 'date_immutable'], true) => "'2026-01-01T00:00:00+00:00'",
                in_array($type, ['uuid', 'guid', 'uuid_binary'], true)                                                                   => "'00000000-0000-7000-8000-000000000000'",
                in_array($type, ['json', 'array', 'simple_array', 'json_array'], true)                                                   => '[]',
                default                                                                                                                  => "'test_{$fieldName}'",
            };
        }

        /*
         * Exporte un tableau imbriqué (clés et valeurs texte) en littéral PHP court.
         */
        private function exportArray(array $data): string {
            $parts = [];

            foreach ($data as $key => $value) {
                $parts[] = "'" . addslashes((string)$key) . "' => " . (is_array($value) ? $this->exportArray($value) : "'" . addslashes((string)$value) . "'");
            }

            return '[' . implode(', ', $parts) . ']';
        }
    }
