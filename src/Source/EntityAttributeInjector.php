<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Injection des attributs #[ApiResource] et des paramètres #[QueryParameter] dans les entités.
     */

    declare(strict_types=1);

    namespace SocioLink\ApiResourceBundle\Source;

    use Symfony\Component\Console\Style\SymfonyStyle;

    /**
     * Injecte les attributs #[ApiResource] (avec leurs paramètres de filtrage #[QueryParameter])
     * dans les fichiers source des entités Doctrine.
     *
     * Les filtres ne sont plus générés sous forme de #[ApiFilter] (déprécié depuis API Platform 4.4,
     * supprimé en 6.0) mais sous forme de paramètres attachés à l'opération GetCollection :
     * la classification des champs est déléguée à {@see FilterDefinitionBuilder}.
     *
     * Le contenu du bloc #[ApiResource] varie selon les options :
     *
     *  Mode par défaut (sans option booléenne) : opérations avec input/processor référencés
     *  --only-resource  : opérations vides (Get, GetCollection, Post, Patch) sans input/processor
     *  --all            : identique à --only-resource (artefacts générés mais non liés)
     *  --toggle-boolean : opérations + Toggle pour TOUS les booléens (y compris itDeleted/itErased)
     *  --detach-boolean : Patch individuel par booléen à la place du Patch /toggle unique
     *  --graphql-filters: reporte les paramètres de filtrage sur QueryCollection (GraphQL)
     *  --with-mercure   : injecte la directive mercure (privée : ['private' => true])
     *  --with-mercure --public : injecte mercure: true (mises à jour publiées publiquement)
     *
     * Garanties de cette version :
     *  - --dry-run et --preview n'écrivent JAMAIS dans le fichier de l'entité ;
     *  - les fins de ligne (LF ou CRLF) du fichier sont respectées ;
     *  - --force ne supprime que ce que le générateur a lui-même injecté : les #[ApiFilter] posés sur
     *    des propriétés, les `use ApiPlatform\*` encore référencés et les sous-ressources sont conservés ;
     *  - une sous-ressource injectée par le parent ne bloque plus la génération de l'entité enfant,
     *    et --force sur l'enfant ne la supprime plus.
     *
     * Valeurs retournées :
     *  - chemin absolu  : injection réussie (ou simulée en lecture seule)
     *  - 'skipped'      : ressource principale déjà présente et force = false
     *  - 'not_found'    : fichier ou classe non localisée
     *  - 'error'        : échec d'écriture
     *
     * @internal Réservé à l'usage interne de la commande generate:resource.
     */
    final readonly class EntityAttributeInjector {
        /**
         * FQCN dont le générateur est l'auteur : seuls ces imports (et les DTO/Processors/Providers
         * générés) sont supprimés par --force, et uniquement s'ils ne sont plus référencés.
         *
         * @var list<string>
         */
        private const array OWNED_FQCNS = [
            'ApiPlatform\\Metadata\\ApiFilter',
            'ApiPlatform\\Metadata\\ApiResource',
            'ApiPlatform\\Metadata\\Get',
            'ApiPlatform\\Metadata\\GetCollection',
            'ApiPlatform\\Metadata\\Link',
            'ApiPlatform\\Metadata\\Patch',
            'ApiPlatform\\Metadata\\Post',
            'ApiPlatform\\Metadata\\QueryParameter',
            'ApiPlatform\\Metadata\\GraphQl\\Query',
            'ApiPlatform\\Metadata\\GraphQl\\QueryCollection',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\BooleanFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\ChainFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\ComparisonFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\DateFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\ExactFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\ExistsFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\IriFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\NumericFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\OrderFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\PartialSearchFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\RangeFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\SearchFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\SortFilter',
            'ApiPlatform\\Doctrine\\Orm\\Filter\\UuidFilter',
        ];

        /*
         * $namespaceResolver fournit le chemin du fichier entité et les segments d'URI ; $filterBuilder classifie
         * les champs en paramètres de filtrage ; $config identifie les espaces de noms des artefacts générés.
         */
        public function __construct(
            private NamespaceResolver       $namespaceResolver,
            private FilterDefinitionBuilder $filterBuilder,
            private GeneratorConfig         $config,
        ) {}

        /* ── Point d'entrée : ressource principale ─── */

        /*
         * Injecte #[ApiResource] (et ses paramètres de filtrage) dans le fichier source de l'entité.
         *
         * Sans --force : une ressource principale déjà présente, ou un #[ApiFilter] de classe hérité
         * de l'ancien format, provoque un 'skipped' (avec un message de migration pour ce dernier).
         * Avec --force : le bloc précédent est retiré puis réinjecté (voir removeInjectedAttributes()).
         * En lecture seule (--dry-run / --preview) : le fichier n'est jamais écrit ; --preview affiche
         * le bloc d'attributs et les imports qui seraient ajoutés.
         *
         * Retour : 'skipped' | 'not_found' | 'error' | chemin absolu du fichier
         */
        /**
         * @param array<string, array<string, mixed>>                 $fields
         * @param array<string, mixed>                                $allBooleanFields
         * @param list<array{propSuffix: string, uriSegment: string}> $uploadFields
         */
        public function injectAttributesIntoEntity(
            string       $entityClass, string $entityName, array $fields, string $dtoNs, string $stateNs, GenerationOptions $options,
            SymfonyStyle $io, array $allBooleanFields, array $uploadFields): string {
            $filePath = $this->namespaceResolver->entityFilePath($entityClass);

            if (!file_exists($filePath)) {
                $io->warning(sprintf('Fichier entité introuvable : %s', $filePath));

                return 'not_found';
            }

            $source = (string)file_get_contents($filePath);
            $eol    = $this->detectEol($source); /* LF ou CRLF : tout ce qui est inséré s'y conforme */

            $hasMainResource  = $this->hasMainApiResource($source, $entityName);
            $hasLegacyFilters = $this->findClassAttributes($source, $entityName, 'ApiFilter') !== [];

            /* Sans --force, on ne réécrit jamais un fichier déjà traité : les personnalisations manuelles sont préservées. */
            if (($hasMainResource || $hasLegacyFilters) && !$options->force) {
                if ($hasLegacyFilters) {
                    $io->note(sprintf(
                        '%s : #[ApiFilter] hérité détecté (déprécié, supprimé en API Platform 6.0). '
                        . 'Migrez avec « bin/console api:upgrade-filter » ou régénérez avec --force.',
                        $entityName,
                    ));
                }

                return 'skipped';
            }

            /* Avec --force, l'ancien bloc est retiré D'ABORD : un seul #[ApiResource] principal est autorisé par classe. */
            if ($options->force && ($hasMainResource || $hasLegacyFilters)) {
                $source = $this->removeInjectedAttributes($source, $entityName);
            }

            [$attrBlock, $requiredFqcns] = $this->buildEntityAttributeBlock(
                $entityClass, $entityName, $fields, $dtoNs, $stateNs, $options, $allBooleanFields, $uploadFields,
            );

            $declStart = $this->findDeclarationStart($source, $entityName);

            if ($declStart === null) {
                $io->warning(sprintf('Impossible de localiser "class %s" dans %s.', $entityName, $filePath));

                return 'not_found';
            }

            [$lineStart, $indent] = $this->resolveLineStart($source, $declStart);

            /* Le bloc est inséré sur ses propres lignes, juste avant la ligne de déclaration de la classe. */
            $source = substr($source, 0, $lineStart) . $this->indentBlock($attrBlock, $indent, $eol) . $eol . substr($source, $lineStart);

            [$source, $addedUses] = $this->addImports($source, $requiredFqcns, $eol);

            return $this->commit($filePath, $entityClass, $source, $attrBlock, $addedUses, $options, $io);
        }

        /* ── Sous-ressources (--sub-resources) ─── */

        /*
         * Injecte un #[ApiResource] de sous-ressource dans l'entité ENFANT (cible d'une relation OneToMany).
         *
         * La sous-ressource expose GET /{parents}/{parentId}/{enfants} et reprend les paramètres de
         * filtrage de l'enfant (avant la migration, elle héritait des #[ApiFilter] de classe de l'enfant).
         *
         * Sans --force : une sous-ressource de ce parent déjà présente donne 'skipped'.
         * Avec --force : elle est remplacée (ses paramètres suivent l'évolution des champs de l'enfant).
         * En lecture seule : aucune écriture (voir injectAttributesIntoEntity()).
         *
         * Paramètre $targetFields : champs de l'entité enfant (FieldAnalyser::getEntityFields()).
         *
         * Retour : 'skipped' | 'not_found' | 'error' | chemin absolu du fichier
         */
        /**
         * @param array<string, array<string, mixed>> $targetFields
         */
        public function injectSubResourceAttributes(
            string            $parentClass,
            string            $parentName,
            string            $targetClass,
            string            $targetName,
            string            $fieldName,
            string            $mappedBy,
            GenerationOptions $options,
            SymfonyStyle      $io,
            array             $targetFields = [],
        ): string {
            $filePath = $this->namespaceResolver->entityFilePath($targetClass);

            if (!file_exists($filePath)) {
                $io->warning(sprintf('Fichier entité enfant introuvable : %s', $filePath));

                return 'not_found';
            }

            $source = (string)file_get_contents($filePath);
            $eol    = $this->detectEol($source);

            $parentUriBase = $this->namespaceResolver->toApiPlatformUriBase($parentName);
            $fieldUri      = $this->namespaceResolver->toApiPlatformUriBase($targetName);
            $parentVarName = $parentUriBase . 'Id';
            $uriTemplate   = "/{$parentUriBase}/{{$parentVarName}}/{$fieldUri}";

            /* Signature d'une sous-ressource de CE parent : tout uriTemplate commençant par '/{parent}/'. */
            $signature = "uriTemplate: '/{$parentUriBase}/";

            foreach ($this->findClassAttributes($source, $targetName, 'ApiResource') as $attribute) {
                if ($this->isSubResourceAttribute($attribute['text']) && str_contains($attribute['text'], $signature)) {
                    if (!$options->force) {
                        return 'skipped';
                    }

                    $source = substr($source, 0, $attribute['start']) . substr($source, $attribute['end']); /* remplacement : on retire l'ancienne */
                    break;
                }
            }

            $definitions = $this->filterBuilder->buildDefinitions($targetFields);

            if ($definitions === []) {
                $operations = "    operations: [new GetCollection()],\n";
            }
            else {
                $operations = "    operations: [\n"
                              . "        new GetCollection(\n"
                              . "            parameters: [\n"
                              . implode("\n", $this->filterBuilder->renderParameters($definitions, '                ')) . "\n"
                              . "            ],\n"
                              . "        ),\n"
                              . "    ],\n";
            }

            $attrBlock = "#[ApiResource(\n"
                         . "    uriTemplate: '{$uriTemplate}',\n"
                         . $operations
                         . "    uriVariables: ['{$parentVarName}' => new Link(toProperty: '{$mappedBy}', fromClass: {$parentName}::class)],\n"
                         . ')]';

            $requiredFqcns = array_values(array_unique([
                'ApiPlatform\\Metadata\\ApiResource',
                'ApiPlatform\\Metadata\\GetCollection',
                'ApiPlatform\\Metadata\\Link',
                $parentClass,
                ...$this->filterBuilder->requiredFqcns($definitions),
            ]));
            sort($requiredFqcns);

            /* Insertion juste après le dernier #[ApiResource] de classe, à défaut avant la déclaration de classe. */
            $attributes = $this->findClassAttributes($source, $targetName, 'ApiResource');
            $last       = $attributes !== [] ? end($attributes) : null;

            if ($last !== null && $last['ownLine']) {
                $insertAt = $last['end'];
                $indent   = $last['indent'];
            }
            else {
                $declStart = $this->findDeclarationStart($source, $targetName);

                if ($declStart === null) {
                    return 'not_found';
                }

                [$insertAt, $indent] = $this->resolveLineStart($source, $declStart);
            }

            $source = substr($source, 0, $insertAt) . $this->indentBlock($attrBlock, $indent, $eol) . $eol . substr($source, $insertAt);

            [$source, $addedUses] = $this->addImports($source, $requiredFqcns, $eol);

            return $this->commit($filePath, $targetClass, $source, $attrBlock, $addedUses, $options, $io);
        }

        /* ── Construction du bloc #[ApiResource] ─── */

        /*
         * Construit le bloc PHP #[ApiResource(...)] à injecter et la liste des FQCN à importer.
         *
         * Le bloc est retourné SANS indentation de base et avec des fins de ligne "\n" :
         * l'indentation et les fins de ligne sont appliquées à l'insertion.
         *
         * Retour : [bloc PHP, liste triée des FQCN à importer]
         */
        /**
         * @param array<string, array<string, mixed>>                 $fields
         * @param array<string, mixed>                                $allBooleanFields
         * @param list<array{propSuffix: string, uriSegment: string}> $uploadFields
         *
         * @return array{string, list<string>}
         */
        public function buildEntityAttributeBlock(
            string            $entityClass, string $entityName, array $fields, string $dtoNs, string $stateNs,
            GenerationOptions $options, array $allBooleanFields, array $uploadFields): array {
            $routePrefix = $this->namespaceResolver->getRoutePrefix($entityClass);
            $uriBase     = $this->namespaceResolver->toApiPlatformUriBase($entityName);
            $link        = $options->linksArtifactsToResource(); /* false en --only-resource / --all : opérations « nues » */

            $definitions = $this->filterBuilder->buildDefinitions($fields);

            /* La virgule finale de chaque ligne est indispensable : les lignes sont jointes sans séparateur. */
            $operationLines = [];

            /* --with-provider : le Provider généré est câblé sur Get (sauf en mode « libre », où rien n'est lié). */
            $get = $link && $options->withProvider ? "new Get(provider: {$entityName}Provider::class)" : 'new Get()';

            if ($definitions === []) {
                $operationLines[] = "        {$get}, new GetCollection(),";
            }
            else {
                /* Get et GetCollection sont séparés : les paramètres de filtrage se rattachent à GetCollection uniquement. */
                $operationLines[] = "        {$get},";
                $operationLines[] = '        new GetCollection(';
                $operationLines[] = '            parameters: [';

                foreach ($this->filterBuilder->renderParameters($definitions, '                ') as $line) {
                    $operationLines[] = $line;
                }

                $operationLines[] = '            ],';
                $operationLines[] = '        ),';
            }

            if ($link) {
                $operationLines[] = "        new Post(input: {$entityName}CreateDto::class, processor: {$entityName}CreateProcessor::class),";
                $operationLines[] = "        new Patch(input: {$entityName}UpdateDto::class, processor: {$entityName}UpdateProcessor::class),";

                /* Le Toggle global n'apparaît que si l'option est active ET qu'il existe au moins un booléen. */
                if ($options->usesToggleEndpoint() && !empty($allBooleanFields)) {
                    $operationLines[] = "        new Patch(uriTemplate: '/{$uriBase}/{id}/toggle', input: {$entityName}ToggleDto::class, processor: {$entityName}ToggleProcessor::class),";
                }

                if ($options->detachBoolean) {
                    foreach ($allBooleanFields as $fieldName => $info) {
                        $pascal           = ucfirst($fieldName);
                        $uriSegment       = $this->namespaceResolver->toggleUriSegment((string)$fieldName); /* 'itDeleted' → 'toggle-it-deleted' */
                        $operationLines[] = "        new Patch(uriTemplate: '/{$uriBase}/{id}/{$uriSegment}', input: {$entityName}{$pascal}Dto::class, processor: {$entityName}{$pascal}Processor::class),";
                    }
                }

                foreach ($uploadFields as $uf) {
                    $suffix     = $uf['propSuffix'];
                    $uriSegment = $uf['uriSegment'];
                    /* multipart + deserialize: false : réglages obligatoires d'un endpoint d'upload (VichUploader gère le fichier) ; read: true charge l'entité existante. */
                    $operationLines[] = "        new Patch(\n"
                                        . "            uriTemplate: '/{$uriBase}/{id}/{$uriSegment}', inputFormats: ['multipart' => ['multipart/form-data']],\n"
                                        . "            input: {$entityName}Upload{$suffix}Dto::class, read: true, deserialize: false, processor: {$entityName}Upload{$suffix}Processor::class,\n"
                                        . '        ),';
                }
            }
            else {
                /* Mode « libre » (--only-resource / --all) : Post()/Patch() sans input/processor, à câbler manuellement. */
                $operationLines[] = '        new Post(),';
                $operationLines[] = '        new Patch(),';
            }

            $operationsBlock = "[\n" . implode("\n", $operationLines) . "\n    ]";
            $orderClause     = $this->resolveOrderClause($fields);

            /*
             * Directives de ressource sur une ligne : routePrefix (sous-namespace), mercure (--with-mercure) et order.
             *
             * Mercure n'est injecté QUE si --with-mercure est actif : privé par défaut
             * (mercure: ['private' => true], abonné authentifié), public avec --public (mercure: true).
             */
            $directives = [];

            if ($routePrefix) {
                $directives[] = "routePrefix: '{$routePrefix}'";
            }

            if ($options->withMercure) {
                $directives[] = $options->publicMercure ? 'mercure: true' : "mercure: ['private' => true]";
            }

            if ($orderClause !== null) {
                $directives[] = $orderClause;
            }

            $directivesLine = $directives !== [] ? '    ' . implode(', ', $directives) . ",\n" : '';

            /*
             * GraphQL : le #[ApiFilter] de classe filtrait aussi la collection GraphQL, ce que ne font PAS
             * des paramètres posés sur GetCollection. --graphql-filters les reporte sur QueryCollection.
             */
            if ($options->graphqlFilters && $definitions !== []) {
                $graphQl = "    graphQlOperations: [\n"
                           . "        new Query(),\n"
                           . "        new QueryCollection(\n"
                           . "            paginationType: 'page',\n"
                           . "            parameters: [\n"
                           . implode("\n", $this->filterBuilder->renderParameters($definitions, '                ')) . "\n"
                           . "            ],\n"
                           . "        ),\n"
                           . "    ],\n";
            }
            else {
                $graphQl = "    graphQlOperations: [new Query(), new QueryCollection(paginationType: 'page')],\n";
            }

            $attrBlock = "#[ApiResource(\n"
                         . "    operations: {$operationsBlock},\n"
                         . $directivesLine
                         . $graphQl
                         . ')]';

            $requiredFqcns = $this->buildRequiredFqcns(
                $entityName, $dtoNs, $stateNs, $options, $allBooleanFields, $uploadFields,
                $this->filterBuilder->requiredFqcns($definitions),
            );

            return [$attrBlock, $requiredFqcns];
        }

        /* ── Tri par défaut ─── */

        /*
         * Détermine la clause `order` du #[ApiResource] (indépendante des paramètres de tri) :
         *  1. attributs de nommage (ASC) : fullName, headLine, givenName, shortName, shortHead, familyName, title, name
         *  2. champ createdAt (DESC)
         *  3. repli sur id (DESC), si l'entité a un champ id ; sinon aucune clause
         */
        /**
         * @param array<string, mixed> $fields
         */
        private function resolveOrderClause(array $fields): ?string {
            $nameAttributes = ['fullName', 'headLine', 'givenName', 'shortName', 'shortHead', 'familyName', 'title', 'name'];

            foreach ($nameAttributes as $attr) {
                if (isset($fields[$attr])) {
                    return "order: ['{$attr}' => 'ASC']";
                }
            }

            if (isset($fields['createdAt'])) {
                return "order: ['createdAt' => 'DESC']";
            }

            return isset($fields['id']) ? "order: ['id' => 'DESC']" : null;
        }

        /* ── Liste des FQCN à importer ─── */

        /*
         * Retourne la liste triée et dédupliquée des FQCN à importer dans l'entité.
         *
         * Les imports de DTO/Processors ne sont nécessaires que si les artefacts sont RÉFÉRENCÉS dans les
         * opérations (linksArtifactsToResource()). $filterFqcns vient de FilterDefinitionBuilder::requiredFqcns().
         */
        /**
         * @param array<string, mixed>            $allBooleanFields
         * @param list<array{propSuffix: string}> $uploadFields
         * @param list<string>                    $filterFqcns
         *
         * @return list<string>
         */
        private function buildRequiredFqcns(
            string $entityName, string $dtoNs, string $stateNs, GenerationOptions $options,
            array  $allBooleanFields, array $uploadFields, array $filterFqcns): array {
            $fqcns = [
                'ApiPlatform\\Metadata\\ApiResource',
                'ApiPlatform\\Metadata\\Get',
                'ApiPlatform\\Metadata\\GetCollection',
                'ApiPlatform\\Metadata\\GraphQl\\Query',
                'ApiPlatform\\Metadata\\GraphQl\\QueryCollection',
                'ApiPlatform\\Metadata\\Patch',
                'ApiPlatform\\Metadata\\Post',
                ...$filterFqcns,
            ];

            if ($options->linksArtifactsToResource()) {
                $fqcns[] = $dtoNs . '\\' . $entityName . 'CreateDto';
                $fqcns[] = $dtoNs . '\\' . $entityName . 'UpdateDto';
                $fqcns[] = $stateNs . '\\' . $entityName . 'CreateProcessor';
                $fqcns[] = $stateNs . '\\' . $entityName . 'UpdateProcessor';

                if ($options->withProvider) {
                    $fqcns[] = $stateNs . '\\' . $entityName . 'Provider';
                }

                if ($options->usesToggleEndpoint() && !empty($allBooleanFields)) {
                    $fqcns[] = $dtoNs . '\\' . $entityName . 'ToggleDto';
                    $fqcns[] = $stateNs . '\\' . $entityName . 'ToggleProcessor';
                }

                if ($options->detachBoolean) {
                    foreach (array_keys($allBooleanFields) as $fieldName) {
                        $pascal  = ucfirst((string)$fieldName);
                        $fqcns[] = $dtoNs . '\\' . $entityName . $pascal . 'Dto';
                        $fqcns[] = $stateNs . '\\' . $entityName . $pascal . 'Processor';
                    }
                }

                foreach ($uploadFields as $uf) {
                    $suffix  = $uf['propSuffix'];
                    $fqcns[] = $dtoNs . '\\' . $entityName . 'Upload' . $suffix . 'Dto';
                    $fqcns[] = $stateNs . '\\' . $entityName . 'Upload' . $suffix . 'Processor';
                }
            }

            $fqcns = array_values(array_unique($fqcns));
            sort($fqcns);

            return $fqcns;
        }

        /* ── Écriture et aperçu ─── */

        /*
         * Termine l'injection : aperçu éventuel, puis écriture SAUF en lecture seule.
         *
         * Paramètre $entityClass : FQCN affiché dans l'aperçu.
         * Paramètre $addedUses   : lignes `use` ajoutées (affichées en --preview).
         *
         * Retour : chemin du fichier (réel ou simulé) ou 'error'
         */
        /**
         * @param list<string> $addedUses
         */
        private function commit(string $filePath, string $entityClass, string $newSource, string $attrBlock, array $addedUses, GenerationOptions $options, SymfonyStyle $io): string {
            if ($options->preview) {
                $io->newLine();
                $io->section(sprintf('📄 %s (attributs injectés)', $entityClass));
                $io->write($attrBlock);

                if ($addedUses !== []) {
                    $io->newLine(2);
                    $io->write(implode("\n", array_map('trim', $addedUses)));
                }

                $io->newLine();
            }

            /* --dry-run et --preview : le fichier de l'entité n'est JAMAIS écrit. */
            if ($options->isReadOnly()) {
                return $filePath;
            }

            if (file_put_contents($filePath, $newSource) === false) {
                $io->warning(sprintf('Impossible d\'écrire dans %s — vérifiez les droits.', $filePath));

                return 'error';
            }

            return $filePath;
        }

        /* ── Suppression des attributs injectés (--force) ─── */

        /*
         * Retire ce que le générateur a injecté, et seulement cela :
         *  1. les #[ApiResource] PRINCIPAUX de classe (les sous-ressources sont conservées) ;
         *  2. les #[ApiFilter] de CLASSE (ancien format ; ceux posés sur des propriétés sont conservés) ;
         *  3. les `use` générés (filtres, attributs API Platform, DTO/Processors/Providers) qui ne sont
         *     plus référencés dans le fichier.
         *
         * Le nettoyage des `use` est indépendant des fins de ligne (LF/CRLF).
         */
        private function removeInjectedAttributes(string $source, string $entityName): string {
            $eol = $this->detectEol($source);

            /* Chaque passe retire une occurrence puis recalcule les offsets : la source raccourcit à chaque tour, la boucle se termine. */
            while (true) {
                $target = null;

                foreach ($this->findClassAttributes($source, $entityName, 'ApiResource') as $attribute) {
                    if (!$this->isSubResourceAttribute($attribute['text'])) {
                        $target = $attribute;
                        break;
                    }
                }

                if ($target === null) {
                    break;
                }

                $source = substr($source, 0, $target['start']) . substr($source, $target['end']);
            }

            while (($legacy = $this->findClassAttributes($source, $entityName, 'ApiFilter')) !== []) {
                $source = substr($source, 0, $legacy[0]['start']) . substr($source, $legacy[0]['end']);
            }

            $source = $this->removeOrphanGeneratedImports($source);

            /* Les suppressions de lignes `use` peuvent laisser des lignes vides consécutives : on les résorbe dans l'en-tête uniquement. */
            $declStart = $this->findDeclarationStart($source, $entityName);

            if ($declStart !== null) {
                $head   = (string)preg_replace('/(?:\r?\n){3,}/', $eol . $eol, substr($source, 0, $declStart));
                $source = $head . substr($source, $declStart);
            }

            return $source;
        }

        /*
         * Supprime les `use` appartenant au générateur qui ne sont plus référencés dans le reste du fichier.
         *
         * Un import encore utilisé (par exemple `Link` dans une sous-ressource conservée, ou `ApiProperty`
         * ajouté à la main, qui n'est de toute façon pas dans la liste) est conservé.
         */
        private function removeOrphanGeneratedImports(string $source): string {
            $usePattern = '/^[ \t]*use\s+([^;\s]+)\s*;[ \t]*\r?\n/m';

            /* Corps du fichier sans aucune ligne `use` : sert à savoir si un nom court est encore référencé. */
            $body = (string)preg_replace('/^[ \t]*use\s+[^;]+;[ \t]*\r?\n/m', '', $source);

            return (string)preg_replace_callback(
                $usePattern,
                function (array $match) use ($body): string {
                    $fqcn = $match[1];

                    if (!$this->isGeneratorOwned($fqcn)) {
                        return $match[0];
                    }

                    $short = substr($fqcn, (int)strrpos($fqcn, '\\') + 1);

                    return preg_match('/\b' . preg_quote($short, '/') . '\b/', $body) === 1 ? $match[0] : '';
                },
                $source,
            );
        }

        /*
         * Indique si un FQCN a été importé par le générateur : liste explicite, ou DTO/Processor/Provider généré.
         */
        private function isGeneratorOwned(string $fqcn): bool {
            if (in_array($fqcn, self::OWNED_FQCNS, true)) {
                return true;
            }

            foreach ([$this->config->dtoNamespace, $this->config->stateNamespace] as $namespace) {
                if (str_starts_with($fqcn, $namespace . '\\') && preg_match('/(?:Dto|Processor|Provider)$/', $fqcn) === 1) {
                    return true;
                }
            }

            return false;
        }

        /* ── Attributs : détection, portée de classe, sous-ressources ─── */

        /*
         * Indique si l'entité porte un #[ApiResource] PRINCIPAL (hors sous-ressource générée).
         */
        private function hasMainApiResource(string $source, string $entityName): bool {
            foreach ($this->findClassAttributes($source, $entityName, 'ApiResource') as $attribute) {
                if (!$this->isSubResourceAttribute($attribute['text'])) {
                    return true;
                }
            }

            return false;
        }

        /*
         * Indique si le texte d'un #[ApiResource] est une sous-ressource générée par --sub-resources :
         * son PREMIER argument est `uriTemplate:` (une ressource principale commence par `operations:`)
         * et elle déclare un `new Link(`.
         */
        private function isSubResourceAttribute(string $text): bool {
            return preg_match('/^#\[\s*ApiResource\s*\(\s*uriTemplate\s*:/', $text) === 1 && str_contains($text, 'new Link(');
        }

        /*
         * Retourne les attributs de CLASSE portant le nom $name (ceux situés avant la déclaration de la classe).
         *
         * Les attributs de propriété ou de méthode sont ignorés : c'est ce qui protège un #[ApiFilter]
         * posé à la main sur une propriété. Le repérage de la fin d'un attribut tient compte des chaînes
         * de caractères (une parenthèse dans une chaîne ne fausse plus le comptage).
         *
         * Chaque élément contient :
         *  - 'start'/'end' : plage à supprimer (ligne complète, fin de ligne comprise, si l'attribut est seul sur sa ligne)
         *  - 'text'        : texte de l'attribut, de `#[` à `]`
         *  - 'ownLine'     : vrai si l'attribut occupe seul sa ligne
         *  - 'indent'      : indentation de cette ligne
         *
         * Retour : liste (vide si la classe n'est pas trouvée)
         */
        /**
         * @return list<array{start: int, end: int, text: string, ownLine: bool, indent: string}>
         */
        private function findClassAttributes(string $source, string $entityName, string $name): array {
            $declStart = $this->findDeclarationStart($source, $entityName);

            if ($declStart === null) {
                return [];
            }

            $pattern = '/#\[\s*' . preg_quote($name, '/') . '(?=[\s(\]])/';
            $found   = [];
            $offset  = 0;

            while (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $start = (int)$m[0][1];

                if ($start >= $declStart) {
                    break; /* au-delà de la déclaration de classe : attributs de propriétés ou de méthodes */
                }

                $end = $this->findAttributeEnd($source, $start + strlen($m[0][0]));

                if ($end === null) {
                    $offset = $start + 2; /* attribut malformé ou groupé : ignoré */
                    continue;
                }

                $lineBreak = strrpos(substr($source, 0, $start), "\n");
                $lineStart = $lineBreak === false ? 0 : $lineBreak + 1;
                $before    = substr($source, $lineStart, $start - $lineStart);
                $ownLine   = trim($before) === '' && preg_match('/\G[ \t]*(?:\r?\n|\z)/', $source, $tail, 0, $end) === 1;

                $found[] = [
                    'start'   => $ownLine ? $lineStart : $start,
                    'end'     => $ownLine ? $end + strlen($tail[0]) : $end,
                    'text'    => substr($source, $start, $end - $start),
                    'ownLine' => $ownLine,
                    'indent'  => $ownLine ? $before : '',
                ];

                $offset = $end;
            }

            return $found;
        }

        /*
         * Retourne la position juste après le `]` qui ferme l'attribut commençant avant $pos.
         *
         * $pos est situé juste après le nom de l'attribut. Le comptage des parenthèses ignore le
         * contenu des chaînes ('...' et "...", échappements compris).
         *
         * Retour : position, ou null si l'attribut est malformé ou groupé (#[A(), B()])
         */
        private function findAttributeEnd(string $source, int $pos): ?int {
            $len = strlen($source);

            while ($pos < $len && ctype_space($source[$pos])) {
                $pos++;
            }

            if ($pos < $len && $source[$pos] === '(') {
                $depth = 0;
                $quote = null;

                for (; $pos < $len; $pos++) {
                    $c = $source[$pos];

                    if ($quote !== null) {
                        if ($c === '\\') {
                            $pos++; /* caractère échappé : on saute le suivant */
                        }
                        elseif ($c === $quote) {
                            $quote = null;
                        }

                        continue;
                    }

                    if ($c === "'" || $c === '"') {
                        $quote = $c;
                    }
                    elseif ($c === '(') {
                        $depth++;
                    }
                    elseif ($c === ')') {
                        $depth--;

                        if ($depth === 0) {
                            $pos++;
                            break;
                        }
                    }
                }

                if ($depth !== 0) {
                    return null;
                }

                while ($pos < $len && ctype_space($source[$pos])) {
                    $pos++;
                }
            }

            return ($pos < $len && $source[$pos] === ']') ? $pos + 1 : null;
        }

        /* ── Tokenisation : déclaration de classe et imports ─── */

        /*
         * Localise le premier token de la déclaration de classe (modificateurs final/abstract/readonly compris).
         *
         * Le tokeniseur natif de PHP évite les faux positifs dans les chaînes et les commentaires. Seule
         * la classe portant exactement le nom $entityName est ciblée (pas les classes anonymes).
         *
         * Retour : offset en octets, ou null si la classe n'est pas trouvée
         */
        private function findDeclarationStart(string $source, string $entityName): ?int {
            $tokens = token_get_all($source);
            $count  = count($tokens);
            $offset = 0;
            $offsets = [];

            foreach ($tokens as $i => $token) {
                $offsets[$i] = $offset;
                $offset     += strlen(is_array($token) ? $token[1] : $token);
            }

            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CLASS) {
                    continue;
                }

                /* Nom de la classe : premier token non blanc après `class`. */
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }

                    if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $entityName) {
                        continue 2; /* `Foo::class` ou une autre classe : on poursuit la recherche */
                    }

                    break;
                }

                /* Remontée sur les modificateurs (final, abstract, readonly), sans franchir un attribut. */
                $first = $i;

                for ($k = $i - 1; $k >= 0; $k--) {
                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        continue;
                    }

                    if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                        $first = $k;
                        continue;
                    }

                    break;
                }

                return $offsets[$first];
            }

            return null;
        }

        /*
         * Calcule où insérer un bloc « sur sa propre ligne » avant la déclaration située à $declStart.
         *
         * Retour : [offset du début de ligne, indentation de cette ligne]. Si du code précède la
         * déclaration sur la même ligne (ex. `#[ORM\Entity] final class`), l'offset est celui de la déclaration.
         */
        /**
         * @return array{int, string}
         */
        private function resolveLineStart(string $source, int $declStart): array {
            $lineBreak = strrpos(substr($source, 0, $declStart), "\n");
            $lineStart = $lineBreak === false ? 0 : $lineBreak + 1;
            $prefix    = substr($source, $lineStart, $declStart - $lineStart);

            return trim($prefix) === '' ? [$lineStart, $prefix] : [$declStart, ''];
        }

        /*
         * Ajoute les `use` manquants après le dernier `use` racine, à défaut après la ligne `namespace`.
         *
         * Retour : [nouvelle source, lignes `use` ajoutées]
         */
        /**
         * @param list<string> $fqcns
         *
         * @return array{string, list<string>}
         */
        private function addImports(string $source, array $fqcns, string $eol): array {
            $useIndent = '';

            if (preg_match('/^([ \t]+)use \S/m', $source, $indentMatch)) {
                $useIndent = $indentMatch[1]; /* on s'aligne sur l'indentation des `use` existants */
            }

            $newUseLines = [];

            foreach ($fqcns as $fqcn) {
                if (!preg_match('/^[ \t]*use ' . preg_quote($fqcn, '/') . ';/m', $source)) {
                    $newUseLines[] = $useIndent . 'use ' . $fqcn . ';';
                }
            }

            if ($newUseLines === []) {
                return [$source, []];
            }

            sort($newUseLines);
            $insertion  = $eol . implode($eol, $newUseLines);
            $lastUseEnd = $this->findLastRootUseEnd($source);

            if ($lastUseEnd !== null) {
                $source = substr($source, 0, $lastUseEnd) . $insertion . substr($source, $lastUseEnd);
            }
            elseif (preg_match('/^[ \t]*namespace\s+[^;{]+;/m', $source, $ns, PREG_OFFSET_CAPTURE)) {
                $end    = (int)$ns[0][1] + strlen($ns[0][0]);
                $source = substr($source, 0, $end) . $eol . $insertion . substr($source, $end);
            }

            return [$source, $newUseLines];
        }

        /*
         * Retourne la position juste après le `;` du dernier `use` situé à la racine du fichier.
         *
         * Les `use` de fermetures et les `use` de traits, qui se trouvent à l'intérieur d'accolades,
         * sont ignorés grâce au suivi de profondeur.
         */
        private function findLastRootUseEnd(string $source): ?int {
            $tokens  = token_get_all($source);
            $count   = count($tokens);
            $offsets = [];
            $offset  = 0;

            foreach ($tokens as $i => $token) {
                $offsets[$i] = $offset;
                $offset     += strlen(is_array($token) ? $token[1] : $token);
            }

            $depth = 0;
            $last  = null;

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (!is_array($token)) {
                    if ($token === '{') {
                        $depth++;
                    }
                    elseif ($token === '}') {
                        $depth--;
                    }

                    continue;
                }

                if ($depth === 0 && $token[0] === T_USE) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        if (!is_array($tokens[$j]) && $tokens[$j] === ';') {
                            $last = $offsets[$j] + 1;
                            break;
                        }
                    }
                }
            }

            return $last;
        }

        /* ── Utilitaires de mise en forme ─── */

        /*
         * Retourne la fin de ligne du fichier : "\r\n" s'il en contient, "\n" sinon.
         */
        private function detectEol(string $source): string {
            return str_contains($source, "\r\n") ? "\r\n" : "\n";
        }

        /*
         * Préfixe chaque ligne non vide du bloc par $indent et joint les lignes avec $eol.
         */
        private function indentBlock(string $block, string $indent, string $eol): string {
            $lines = explode("\n", $block);

            foreach ($lines as $i => $line) {
                $lines[$i] = $line === '' ? '' : $indent . $line;
            }

            return implode($eol, $lines);
        }
    }
