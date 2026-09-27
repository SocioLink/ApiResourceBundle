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

    use PhpParser\Node;
    use ReflectionProperty;
    use PhpParser\ParserFactory;
    use PhpParser\NodeTraverser;
    use Doctrine\DBAL\Types\Type;
    use Doctrine\DBAL\Types\Types;
    use PhpParser\NodeVisitorAbstract;
    use Doctrine\ORM\Mapping\OneToMany;
    use PhpParser\PrettyPrinter\Standard;
    use Doctrine\ORM\Mapping\ClassMetadata;

    /**
     * Analyse les champs d'une entité Doctrine ORM et fournit des données normalisées
     * utilisées par DtoBuilder et ProcessorBuilder pour la génération de code.
     *
     * Responsabilités :
     *  - Extraction des champs scalaires et des associations depuis les métadonnées Doctrine
     *  - Conversion des types Doctrine vers les types natifs PHP
     *  - Génération des attributs #[Assert\*] de validation Symfony
     *  - Détection des champs uploadables (attribut VichUploader)
     *  - Production des chaînes de mapping fluent pour les Processors (Create/Update)
     *
     * @internal Réservé à l'usage interne de la commande generate:resource.
     */
    final class FieldAnalyser {
        /**
         * Types Doctrine non reconnus rencontrés pendant l'analyse.
         * Utilisé pour émettre des avertissements en fin de génération.
         *
         * @var array<string, true>
         */
        private static array $unrecognizedTypes = [];

        /**
         * $namespaceResolver donne le chemin du fichier source de l'entité : plusieurs méthodes le lisent
         * directement sur disque (extractUseStatements, extractAssertAttributes, scanUploadFieldsFromSource)
         * plutôt que de se limiter aux seules métadonnées Doctrine en mémoire. $config fournit les listes
         * de champs système exclus des DTOs.
         *
         * @param NamespaceResolver $namespaceResolver Résolution du chemin des fichiers d'entité
         * @param GeneratorConfig   $config            Champs système et conventions du projet
         */
        public function __construct(private NamespaceResolver $namespaceResolver, private GeneratorConfig $config) {}

        /**
         * Retourne les types Doctrine non reconnus rencontrés depuis le dernier reset.
         *
         * @return list<string> Types non reconnus (ex. ['App\Enum\Status'])
         */
        public static function getUnrecognizedTypes(): array {
            return array_keys(self::$unrecognizedTypes);
        }

        /**
         * Réinitialise la liste des types non reconnus.
         * À appeler en début de chaque exécution de la commande.
         */
        public static function resetUnrecognizedTypes(): void {
            self::$unrecognizedTypes = [];
        }

        /* ── Extraction des champs ─── */

        /**
         * Extrait et normalise l'ensemble des champs d'une entité Doctrine.
         *
         * Retourne un tableau indexé par nom de champ, chaque entrée contenant :
         *  - 'doctrineType' (string)  : type Doctrine ou FQCN cible (pour les relations)
         *  - 'nullable'     (bool)    : vrai si le champ accepte NULL
         *  - 'isRelation'   (bool)    : vrai pour les associations Doctrine
         *  - 'isToMany'     (bool)    : vrai pour OneToMany/ManyToMany
         *  - 'length'       (int|null): longueur max pour string/text, null sinon
         *
         * Note API Doctrine ORM 3.x :
         *  - getFieldMapping()       → objet FieldMapping (accès par propriétés)
         *  - getAssociationMapping() → tableau associatif (accès par clés)
         *
         * @param ClassMetadata<object> $metadata
         *
         * @return array<string, array{doctrineType: string, nullable: bool, isRelation: bool, isToMany: bool, isOneToMany: bool, length: int|null}>
         * @throws \Doctrine\ORM\Mapping\MappingException|\ReflectionException
         */
        public function getEntityFields(ClassMetadata $metadata): array {
            $fields = [];

            /* ── Champs scalaires ────────────────────────────────────────────────── */
            foreach ($metadata->getFieldNames() as $name) {
                $mapping = $metadata->getFieldMapping($name); /* objet FieldMapping (Doctrine ORM 3.x) */

                /* Détection des enums backed (PHP 8.1+) */
                $isEnum    = false;
                $enumCases = [];
                $propClass = $metadata->getName();

                if (enum_exists($propClass)) {
                    $reflectionEnum = new \ReflectionEnum($propClass);
                    $backedType     = $reflectionEnum->getBackingType();

                    if ($backedType !== null) {
                        $isEnum = true;

                        foreach ($reflectionEnum->cases() as $case) {
                            $enumCases[] = $case->getBackingValue();
                        }
                    }
                }

                $fields[$name] = [
                    'doctrineType' => $metadata->getTypeOfField($name) ?? 'string',
                    'nullable'     => $metadata->isNullable($name),
                    'isRelation'   => false,
                    'isToMany'     => false,
                    'isOneToMany'  => false,
                    'length'       => $mapping->length ?? null,
                    'isEnum'       => $isEnum,
                    'enumCases'    => $enumCases,
                ];
            }

            /* ── Associations ────────────────────────────────────────────────────── */
            foreach ($metadata->getAssociationNames() as $name) {
                $assoc    = $metadata->getAssociationMapping($name);
                $nullable = $this->resolveAssociationNullability($assoc);

                /* isCollectionValuedAssociation() détecte OneToMany + ManyToMany. */
                /* Pour distinguer les deux, on inspecte l'attribut PHP natif de la propriété */
                /* via Reflection — fiable quelle que soit la version de Doctrine ORM. */
                /*
                 * Doctrine ne distingue pas nativement OneToMany de ManyToMany dans
                 * isCollectionValuedAssociation() (les deux retournent true) : on tranche
                 * donc en lisant directement l'attribut PHP #[OneToMany] sur la propriété
                 * via Reflection — c'est la seule source 100% fiable, indépendante de la
                 * façon dont Doctrine expose ses metadata internes selon la version ORM.
                 */
                $isToMany      = $metadata->isCollectionValuedAssociation($name);
                $oneToManyAttr = new ReflectionProperty($metadata->getName(), $name)->getAttributes(OneToMany::class);
                $isOneToMany   = $isToMany && !empty($oneToManyAttr);

                /* Extraction du mappedBy depuis l'attribut PHP #[OneToMany(mappedBy: '...')] */
                $mappedBy = '';
                if ($isOneToMany && !empty($oneToManyAttr)) {
                    $attrInstance = $oneToManyAttr[0]->newInstance();
                    $mappedBy     = $attrInstance->mappedBy ?? '';
                }

                $fields[$name] = [
                    /* targetEntity peut théoriquement être absent (mapping cassé) ; chaîne vide en repli plutôt qu'une exception, pour ne pas interrompre toute l'analyse pour une seule association mal formée. */
                    'doctrineType' => $assoc->targetEntity ?? '',
                    'nullable'     => $nullable,
                    'isRelation'   => true,
                    'isToMany'     => $isToMany,
                    'isOneToMany'  => $isOneToMany,
                    'mappedBy'     => $mappedBy,
                    'length'       => null,
                ];
            }

            return $fields;
        }

        /**
         * Filtre les champs de type booléen (scalaires uniquement, hors relations).
         *
         * Retourne TOUS les booléens, y compris itDeleted et itErased.
         * Le filtrage des BOOLEAN_SPECIAL_FIELDS est délégué à l'appelant selon le contexte.
         *
         * @param array<string, array{doctrineType: string, isRelation: bool}> $fields
         *
         * @return array<string, array{doctrineType: string, isRelation: bool}>
         */
        public function getBooleanFields(array $fields): array {
            /* !$info['isRelation'] exclut les associations : seuls les champs scalaires peuvent être de type 'boolean', mais la garde reste défensive si jamais une relation portait accidentellement ce nom de type. strtolower() neutralise une éventuelle variation de casse du type Doctrine. */
            return array_filter($fields, static fn(array $info): bool => !$info['isRelation'] && strtolower((string)$info['doctrineType']) === 'boolean');
        }

        /* ── Conversion de types ─── */

        /**
         * Convertit un type Doctrine en son équivalent PHP natif.
         *
         * Les types non reconnus tombent sur 'string' (fallback pour les types personnalisés,
         * enums backed string, etc.).
         *
         * @param string $doctrineType Identifiant de type Doctrine
         *
         * @return string Type PHP natif (ex. 'string', 'int', 'bool', 'DateTimeImmutable', 'Uuid', 'array')
         */
        public function toPhpType(string $doctrineType): string {
            /*
             * Chaque branche du match liste à la fois la constante Types::* (API moderne
             * Doctrine DBAL) ET sa version chaîne brute équivalente (ex. 'string') : cette
             * redondance volontaire couvre le cas où $doctrineType provient d'une source
             * qui ne passe pas par les constantes typées (ex. valeur lue depuis un attribut
             * brut ou une ancienne configuration YAML/XML Doctrine).
             * Toutes les variantes datetime/date/time (mutable, immutable, avec ou sans
             * timezone) convergent vers 'DateTimeImmutable' : les DTOs et leurs Processors
             * manipulent systématiquement des objets immuables, jamais les versions mutables.
             */
            $type = match ($doctrineType) {
                Types::STRING, Types::TEXT, 'string', 'text'                                    => 'string',
                Types::INTEGER, Types::SMALLINT, Types::BIGINT, 'integer', 'smallint', 'bigint' => 'int',
                Types::FLOAT, Types::DECIMAL, 'float', 'decimal'                                => 'float',
                Types::BOOLEAN, 'boolean'                                                       => 'bool',
                Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE, Types::DATE_MUTABLE, Types::DATE_IMMUTABLE,
                Types::TIME_MUTABLE, Types::TIME_IMMUTABLE, Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE,
                'datetime', 'datetime_immutable', 'date', 'date_immutable', 'time', 'time_immutable',
                'datetimetz', 'datetimetz_immutable'                                            => 'DateTimeImmutable',
                Types::SIMPLE_ARRAY, Types::JSON, 'array', 'simple_array', 'json', 'json_array' => 'array',
                Types::GUID, 'uuid', 'uuid_binary', 'guid'                                      => 'Uuid',
                /* Tout type Doctrine non reconnu (custom type métier, enum backed string non listé…) tombe sur 'string', plutôt que de lever une exception qui interromprait toute la génération pour un seul champ exotique. */
                default                                                                         => 'string',
            };

            /* Avertissement pour les types non reconnus (sauf les types qui sont clairement des enums ou des types custom intentionnels) */
            if ($type === 'string' && !in_array($doctrineType, ['string', 'text', Types::STRING, Types::TEXT, ''], true)) {
                /*
                 * Type enregistré auprès du registre Doctrine (ex. phone_number via
                 * odolbeau/phone-number-bundle) : pas d'avertissement, car le type est
                 * connu de Doctrine et simplement non listé dans la correspondance
                 * hardcoded PHP ci-dessus. Seuls les types VRAIMENT inconnus du registre
                 * déclenchent l'avertissement.
                 */
                if (!Type::hasType($doctrineType)) {
                    self::$unrecognizedTypes[$doctrineType] = true;
                }
            }

            return $type;
        }

        /* ── Contraintes de validation ─── */

        /**
         * Génère les attributs #[Assert\*] de validation Symfony pour un champ.
         *
         * Les contraintes sont choisies automatiquement selon :
         *  - La nullabilité (NotBlank pour les champs obligatoires)
         *  - Le type Doctrine (Length, Type, Email, Url, PositiveOrZero…)
         *  - Le nom du champ (détection sémantique : email, url, expiration, quantité…)
         *
         * @param string                                                                          $fieldName Nom du champ (ex. 'email', 'quantity', 'expiresAt')
         * @param array{doctrineType: string, nullable: bool, isRelation: bool, length: int|null} $info
         *
         * @return list<string> Attributs prêts à insérer (ex. ['#[Assert\NotBlank(...)]', '#[Assert\Length(...)]'])
         */
        public function buildConstraints(string $fieldName, array $info): array {
            $constraints = [];
            $type        = strtolower((string)$info['doctrineType']);

            /* NotBlank n'est ajouté QUE si le champ est non-nullable côté entité : un champ optionnel ne doit pas forcer une valeur sur le DTO de création. */
            if (!$info['nullable']) {
                $constraints[] = "#[Assert\\NotBlank(message: \"Le champ {$fieldName} est obligatoire\")]";
            }

            /* Enums backed : contrainte Choice avec les valeurs du backing */
            if (!empty($info['isEnum']) && !empty($info['enumCases'])) {
                $casesStr      = implode(', ', array_map(static fn(mixed $c): string => is_string($c) ? "'{$c}'" : (string)$c, $info['enumCases']));
                $constraints[] = "#[Assert\\Choice({$casesStr}, message: \"La valeur de {$fieldName} doit être l'une des valeurs autorisées\")]";

                return $constraints;
            }

            /* Les relations n'ont droit qu'à NotBlank (ci-dessus) — aucune contrainte de type/longueur n'a de sens sur une association (la cible est typée par le langage lui-même via le type-hint PHP). */
            if ($info['isRelation']) {
                return $constraints;
            }

            if (in_array($type, ['string', 'text'], true)) {
                /* 255 : longueur par défaut conventionnelle d'une colonne VARCHAR si $info['length'] n'a pas été renseigné par Doctrine (ex. colonne TEXT sans longueur définie). */
                $max           = $info['length'] ?? 255;
                $constraints[] = "#[Assert\\Length(max: {$max}, maxMessage: \"{$fieldName} ne peut pas dépasser {{ limit }} caractères\")]";

                /*
                 * Détection SÉMANTIQUE par nom de champ (et non par un attribut Doctrine
                 * dédié, qui n'existe pas) : si le nom contient 'email'/'url'/'website'/'link',
                 * on enrichit automatiquement la validation. C'est une heuristique pratique
                 * qui couvre la grande majorité des conventions de nommage réelles, mais qui
                 * peut produire un faux positif sur un champ nommé de façon inhabituelle
                 * (ex. 'emailTemplate' contenant du texte libre serait quand même validé comme un e-mail).
                 */
                if (stripos($fieldName, 'email') !== false) {
                    $constraints[] = "#[Assert\\Email(message: \"{{ value }} n'est pas une adresse e-mail valide\")]";
                }

                if (stripos($fieldName, 'url') !== false || stripos($fieldName, 'website') !== false || stripos($fieldName, 'link') !== false) {
                    $constraints[] = "#[Assert\\Url(message: \"{{ value }} n'est pas une URL valide\")]";
                }
            }
            elseif (in_array($type, ['integer', 'smallint', 'bigint'], true)) {
                $constraints[] = "#[Assert\\Type(type: \"integer\", message: \"{$fieldName} doit être un entier\")]";

                /* Même logique heuristique par nom : un champ qui ressemble à une quantité/montant/prix ne devrait logiquement jamais être négatif. */
                if (preg_match('/quantity|count|amount|price/i', $fieldName)) {
                    $constraints[] = "#[Assert\\PositiveOrZero(message: \"{$fieldName} doit être positif ou zéro\")]";
                }
            }
            elseif (in_array($type, ['float', 'decimal'], true)) {
                $constraints[] = "#[Assert\\Type(type: \"numeric\", message: \"{$fieldName} doit être un nombre\")]";
            }
            elseif ($type === 'boolean') {
                $constraints[] = "#[Assert\\Type(type: \"bool\", message: \"{$fieldName} doit être un booléen\")]";
            }
            elseif (stripos($fieldName, 'expir') !== false && in_array($type, ['datetime', 'datetime_immutable', 'date', 'date_immutable'], true)) {
                /* Heuristique combinée nom + type : un champ de date contenant 'expir' (expiration, expiresAt…) est présumé devoir être dans le futur au moment de la création. */
                $constraints[] = "#[Assert\\GreaterThan(\"today\", message: \"La date d'expiration doit être dans le futur\")]";
            }
            elseif (in_array($type, ['json', 'array', 'simple_array', 'json_array'], true)) {
                $constraints[] = "#[Assert\\Type(type: \"array\", message: \"{$fieldName} doit être un tableau JSON valide\")]";
            }

            return $constraints;
        }

        /* ── Imports des entités liées ─── */

        /**
         * Collecte les instructions `use` pour les entités cibles des associations.
         *
         * Les associations OneToMany sont exclues : leurs cibles ne sont pas référencées
         * dans le code généré (ni dans les DTOs, ni dans les Processors).
         *
         * @param array<string, array{isRelation: bool, isOneToMany: bool, doctrineType: string}> $fields
         *
         * @return list<string> Instructions use dédupliquées (ex. ['use App\Entity\Category;'])
         */
        public function relationUses(array $fields): array {
            $uses = [];

            foreach ($fields as $info) {
                if (!$info['isRelation']) {
                    continue;
                }

                /* OneToMany : aucune référence directe à l'entité cible dans le code généré */
                /* Une collection OneToMany (ex. Article::getComments()) n'est jamais exposée comme propriété d'un DTO ni manipulée par un Processor : son entité cible n'a donc pas besoin d'être importée ici, contrairement à une ManyToOne/OneToOne qui apparaît bien en propriété typée. */
                if ($info['isOneToMany'] ?? false) {
                    continue;
                }

                $stmt = 'use ' . $info['doctrineType'] . ';';

                /* Garde anti-doublon : deux champs distincts de l'entité peuvent cibler la même classe (ex. createdBy et updatedBy pointant tous deux vers App\Entity\User). */
                if (!in_array($stmt, $uses, true)) {
                    $uses[] = $stmt;
                }
            }

            return $uses;
        }

        /* ── Détection des champs uploadables (VichUploader) ─── */

        /**
         * Détecte les propriétés annotées avec #[UploadableField] de VichUploader.
         *
         * Stratégie hybride :
         *  1. Reflection API si VichUploader est installé (robuste, insensible au formatage)
         *  2. Parsing du code source en fallback
         *
         * @param string $entityClass FQCN complet de l'entité
         *
         * @return list<array{fieldName: string, fileNameProperty: string|null, mapping: string, propSuffix: string, uriSegment: string, assertFileRaw: string}>
         */
        public function detectUploadFields(string $entityClass): array {
            /*
             * La Reflection API est privilégiée dès que possible : elle lit directement
             * l'attribut PHP réellement résolu par le runtime (newInstance()), donc fiable
             * peu importe le style d'écriture du développeur dans le fichier source.
             * Le parsing AST (scanUploadFieldsFromSource) n'est qu'un FALLBACK pour les
             * environnements où le bundle VichUploader ne serait pas installé — situation
             * où la classe d'attribut elle-même n'existe pas pour Reflection.
             */
            if (class_exists('Vich\UploaderBundle\Mapping\Attribute\UploadableField')) {
                return $this->detectUploadFieldsViaReflection($entityClass);
            }

            $filePath = $this->namespaceResolver->entityFilePath($entityClass);

            if (file_exists($filePath)) {
                return $this->scanUploadFieldsFromSource((string)file_get_contents($filePath));
            }

            return [];
        }

        /* ── Mappings fluent pour les Processors ─── */

        /**
         * Génère les lignes de setters fluents pour la création (POST).
         *
         * Exclut : id, SYSTEM_FIELDS, associations OneToMany (silencieusement).
         * Génère un commentaire TODO pour les ManyToMany.
         * INCLUT les booléens et les ManyToOne.
         *
         * @param array<string, array{isRelation: bool, isToMany: bool, isOneToMany: bool, doctrineType: string}> $fields
         *
         * @return array{chain: list<string>, todos: list<string>}
         */
        public function buildFluentMapping(array $fields): array {
            $chain = [];
            $todos = [];

            foreach ($fields as $fieldName => $info) {
                if ($fieldName === 'id' || in_array($fieldName, $this->config->systemFields, true)) {
                    continue;
                }

                if ($info['isRelation'] && $info['isToMany']) {
                    /* OneToMany : exclu silencieusement (pas de TODO) */
                    /* ManyToMany : TODO conservé pour traitement manuel */
                    /*
                     * Une collection OneToMany se peuple naturellement côté propriétaire
                     * (ex. en settant Comment::setArticle($article), pas l'inverse) : aucune
                     * action n'est donc requise dans le Processor de l'entité possédant la
                     * collection — d'où l'absence de TODO. Une ManyToMany, en revanche,
                     * nécessite une décision manuelle (quelle stratégie d'association :
                     * IDs en payload ? sous-ressource dédiée ?) — non automatisable de façon
                     * fiable, d'où le TODO laissé pour le développeur.
                     */
                    if (!($info['isOneToMany'] ?? false)) {
                        $todos[] = "        // TODO: gérer la collection {$fieldName}";
                    }
                    continue;
                }

                /* Setter fluent simple : à la création, AUCUNE valeur de repli n'est nécessaire (contrairement à PATCH) puisque le CreateDto garantit déjà la présence des champs obligatoires via ses propres contraintes de validation. */
                $chain[] = '            ->set' . ucfirst($fieldName) . "(\$data->{$fieldName})";
            }

            return compact('chain', 'todos');
        }

        /**
         * Génère les lignes de setters fluents pour la mise à jour partielle (PATCH).
         *
         * Pattern : setter($data->field ?? $entity->getField()) — conserve la valeur si null.
         * Exclut : id, UPDATE_SYSTEM_FIELDS, associations OneToMany (silencieusement).
         * Génère un commentaire TODO pour les ManyToMany.
         * INCLUT les booléens et les ManyToOne.
         *
         * @param array<string, array{isRelation: bool, isToMany: bool, isOneToMany: bool, doctrineType: string}> $fields
         *
         * @return array{chain: list<string>, todos: list<string>}
         */
        public function buildUpdateMapping(array $fields): array {
            $chain = [];
            $todos = [];

            foreach ($fields as $fieldName => $info) {
                if ($fieldName === 'id' || in_array($fieldName, $this->config->updateSystemFields, true)) {
                    continue;
                }

                if ($info['isRelation'] && $info['isToMany']) {
                    /* OneToMany : exclu silencieusement (pas de TODO) */
                    /* ManyToMany : TODO conservé pour traitement manuel */
                    if (!($info['isOneToMany'] ?? false)) {
                        $todos[] = "        // TODO: gérer la collection {$fieldName}";
                    }
                    continue;
                }

                /*
                 * Pattern caractéristique du PATCH partiel : '$data->field ?? $entity->getField()'.
                 * Si le client n'a pas envoyé ce champ dans le payload (donc $data->field reste
                 * null, valeur par défaut de toute propriété de l'UpdateDto), on conserve la
                 * valeur ACTUELLE de l'entité via son getter — c'est ce mécanisme qui rend
                 * possible une mise à jour de seulement quelques champs sans devoir renvoyer
                 * l'objet complet.
                 */
                $setter  = 'set' . ucfirst($fieldName);
                $getter  = 'get' . ucfirst($fieldName);
                $chain[] = "            ->{$setter}(\$data->{$fieldName} ?? \$entity->{$getter}())";
            }

            return compact('chain', 'todos');
        }

        /* ── Extraction des contraintes depuis le code source de l'entité ─── */

        /**
         * Extrait les instructions `use` déclarées dans le fichier source de l'entité.
         *
         * @param string $entityClass FQCN complet de l'entité
         *
         * @return array<string, string> ['ShortName' => 'Full\Qualified\ClassName']
         */
        public function extractUseStatements(string $entityClass): array {
            $filePath = $this->namespaceResolver->entityFilePath($entityClass);

            if (!file_exists($filePath)) {
                return [];
            }

            try {
                $source = (string)file_get_contents($filePath);
                /*
                 * nikic/php-parser plutôt qu'une regex : les instructions `use` peuvent
                 * comporter des alias ('use Foo\Bar as Baz;'), être multi-lignes ou groupées
                 * (PHP 7+, 'use Foo\{Bar, Baz};') — un vrai parsing AST est nécessaire pour
                 * les couvrir fiablement sans faux positifs/négatifs.
                 */
                $parser = (new ParserFactory())->createForNewestSupportedVersion();
                $stmts  = $parser->parse($source);

                $uses      = [];
                $traverser = new NodeTraverser();
                /*
                 * Classe anonyme NodeVisitorAbstract : pattern classique de php-parser pour
                 * parcourir l'AST. &$uses (référence) permet à enterNode() d'alimenter
                 * directement le tableau local de la méthode englobante, sans avoir à
                 * retourner une valeur depuis le visiteur.
                 */
                $traverser->addVisitor(new class($uses) extends NodeVisitorAbstract {
                    public function __construct(private array &$uses) {}

                    public function enterNode(Node $node) {
                        if ($node instanceof Node\Stmt\Use_) {
                            foreach ($node->uses as $use) {
                                /*
                                 * getAlias() retourne TOUJOURS un identifiant (jamais null) :
                                 * pour 'use Foo\Bar;' sans alias explicite, php-parser renvoie
                                 * 'Bar' (le nom court déduit automatiquement) — ce qui permet
                                 * de traiter alias explicites et implicites de façon uniforme.
                                 */
                                $alias              = $use->getAlias()->name;
                                $this->uses[$alias] = $use->name->toString();
                            }
                        }
                    }
                });
                $traverser->traverse($stmts);

                /* Ajouter implicitement les classes du même namespace */
                /*
                 * Une classe du MÊME namespace que l'entité (ex. un Enum 'Status' dans
                 * src/Entity/Blog/) est utilisable sans `use` explicite en PHP — mais
                 * DtoBuilder a quand même besoin de connaître son FQCN complet pour
                 * générer un import correct dans le DTO (autre namespace). On parcourt
                 * donc le répertoire contenant l'entité pour déduire ces classes "voisines"
                 * implicitement disponibles, sans écraser un alias déjà détecté explicitement
                 * ci-dessus (isset() protège cette priorité).
                 */
                $namespace = substr($entityClass, 0, strrpos($entityClass, '\\'));
                $directory = dirname($filePath);
                if (is_dir($directory)) {
                    foreach (glob($directory . '/*.php') as $file) {
                        $className = basename($file, '.php');
                        if (!isset($uses[$className])) {
                            $uses[$className] = $namespace . '\\' . $className;
                        }
                    }
                }

                return $uses;
            }
            catch (\Exception) {
                /* Fichier illisible ou syntaxiquement invalide : on retourne un mapping vide plutôt que de faire échouer toute la génération pour cette seule entité — DtoBuilder fonctionnera simplement sans pouvoir résoudre de constantes de classes externes. */
                return [];
            }
        }

        /**
         * Extrait les attributs #[Assert\*] depuis le code source via AST.
         *
         * @param string $entityClass FQCN complet de l'entité
         *
         * @return array<string, list<string>>
         */
        public function extractAssertAttributes(string $entityClass): array {
            $filePath = $this->namespaceResolver->entityFilePath($entityClass);

            if (!file_exists($filePath)) {
                return [];
            }

            try {
                $source = (string)file_get_contents($filePath);
                $parser = (new ParserFactory())->createForNewestSupportedVersion();
                $stmts  = $parser->parse($source);

                $result = [];
                /*
                 * Standard (PrettyPrinter de php-parser) permet de reconvertir un nœud AST
                 * en code PHP textuel fidèle — c'est ce qui permet de RECOPIER littéralement
                 * un groupe d'attributs #[Assert\*] depuis l'entité vers le DTO généré,
                 * plutôt que de devoir réimplémenter manuellement le rendu de chaque variante
                 * de contrainte Symfony (avec tous ses paramètres possibles).
                 */
                $printer   = new Standard();
                $traverser = new NodeTraverser();

                $traverser->addVisitor(new class($result, $printer) extends NodeVisitorAbstract {
                    public function __construct(private array &$result, private Standard $printer) {}

                    public function enterNode(Node $node) {
                        if ($node instanceof Node\Stmt\Property) {
                            $propName = $node->props[0]->name->name;
                            foreach ($node->attrGroups as $attrGroup) {
                                foreach ($attrGroup->attrs as $attr) {
                                    $name = $attr->name->toString();
                                    /*
                                     * Le test couvre deux écritures équivalentes : soit le FQCN
                                     * complet de la contrainte contient 'Assert' (ex.
                                     * 'Symfony\Component\Validator\Constraints\NotBlank' — qui
                                     * contient 'Constraints', pas 'Assert' littéralement, mais
                                     * l'alias local 'Assert\NotBlank' si le `use ... as Assert`
                                     * est utilisé), soit l'attribut référence directement le
                                     * namespace 'Symfony\Component\Validator\Constraints'.
                                     * On copie le GROUPE D'ATTRIBUTS ENTIER ($attrGroup, pas
                                     * juste $attr) pour préserver plusieurs contraintes déclarées
                                     * ensemble dans un même bloc #[...].
                                     */
                                    if (str_contains($name, 'Assert') || $name === 'Symfony\Component\Validator\Constraints') {
                                        $this->result[$propName][] = '    ' . $this->printer->prettyPrint([$attrGroup]);
                                    }
                                }
                            }
                        }
                    }
                });
                $traverser->traverse($stmts);

                return $result;
            }
            catch (\Exception) {
                return [];
            }
        }

        /**
         * Résout la nullabilité d'une association depuis ses joinColumns.
         *
         * @param object $assoc Objet AssociationMapping retourné par Doctrine ORM 3.x
         *
         * @return bool true si l'association peut être nulle
         */
        private function resolveAssociationNullability(object $assoc): bool {
            /* OneToMany / ManyToMany : pas de joinColumn côté inverse → nullable par défaut */
            /* Le côté "inverse" d'une association (celui qui ne porte pas la colonne de jointure en base) n'a pas de notion de nullabilité au sens SQL — on considère donc cette situation comme nullable par convention (aucune contrainte d'obligation possible sans colonne physique). */
            if (!isset($assoc->joinColumns) || empty($assoc->joinColumns)) {
                return true;
            }

            foreach ($assoc->joinColumns as $jc) {
                /* JoinColumnMapping expose nullable comme propriété d'objet dans ORM 3.x */
                /* Double support objet/tableau : Doctrine ORM 3.x utilise des objets JoinColumnMapping, mais on garde un fallback tableau (['nullable' => ...]) pour rester compatible avec d'éventuelles versions antérieures ou des mappings construits manuellement. */
                $nullable = is_object($jc) ? ($jc->nullable ?? true) : ($jc['nullable'] ?? true); /* fallback tableau pour compatibilité */

                /* On ne lit QUE la première colonne de jointure rencontrée : une association simple (non composite) n'en a qu'une seule de toute façon, et c'est elle qui porte la contrainte NOT NULL pertinente. */
                return (bool)$nullable;
            }

            return true;
        }

        /**
         * Détecte les champs uploadables via la Reflection API PHP.
         *
         * @param string $entityClass
         *
         * @return list<array{fieldName: string, fileNameProperty: string|null, mapping: string, propSuffix: string, uriSegment: string, assertFileRaw: string}>
         */
        private function detectUploadFieldsViaReflection(string $entityClass): array {
            if (!class_exists($entityClass)) {
                return [];
            }

            $results        = [];
            $reflection     = new \ReflectionClass($entityClass);
            $uploadableAttr = 'Vich\UploaderBundle\Mapping\Attribute\UploadableField';
            $assertFileAttr = 'Symfony\Component\Validator\Constraints\File';

            foreach ($reflection->getProperties() as $property) {
                $uploadAttrs = $property->getAttributes($uploadableAttr);

                /* Propriété sans #[UploadableField] : non concernée, on passe à la suivante. */
                if (empty($uploadAttrs)) {
                    continue;
                }

                /*
                 * newInstance() instancie réellement l'attribut #[UploadableField] (et non
                 * juste ses arguments bruts) : on récupère ainsi directement ->mapping et
                 * ->fileNameProperty tels que VichUploader les interprétera lui-même au runtime,
                 * en bénéficiant de ses propres valeurs par défaut éventuelles.
                 */
                /** @var object{mapping: string, fileNameProperty?: string} $inst */
                $inst            = $uploadAttrs[0]->newInstance();
                $fieldName       = $property->getName();
                $mapping         = $inst->mapping ?? '';
                $fileNameProp    = $inst->fileNameProperty ?? null;
                $assertFileAttrs = $property->getAttributes($assertFileAttr);
                $assertFileRaw   = $this->buildAssertFileRaw($assertFileAttrs);
                $propSuffix      = $this->uploadPropSuffix($fieldName);

                $results[] = [
                    'fieldName'        => $fieldName,
                    'fileNameProperty' => $fileNameProp,
                    'mapping'          => $mapping,
                    'propSuffix'       => $propSuffix,
                    'uriSegment'       => $this->uploadUriSegment($propSuffix),
                    'assertFileRaw'    => $assertFileRaw,
                ];
            }

            return $results;
        }

        /**
         * Reconstruit la chaîne de l'attribut #[Assert\File(...)] depuis les instances Reflection.
         *
         * @param list<\ReflectionAttribute<object>> $assertFileAttrs
         *
         * @return string Attribut formaté ou '#[Assert\File]' minimal
         */
        private function buildAssertFileRaw(array $assertFileAttrs): string {
            /* Aucun attribut #[Assert\File] trouvé sur la propriété : on retombe sur un attribut minimal sans paramètres plutôt que de laisser le champ d'upload totalement sans validation de fichier. */
            if (empty($assertFileAttrs)) {
                return '    #[Assert\\File]';
            }

            $args = $assertFileAttrs[0]->getArguments();

            if (empty($args)) {
                return '    #[Assert\\File]';
            }

            /*
             * Reconstruction manuelle de la syntaxe d'appel PHP depuis les valeurs déjà
             * résolues par Reflection (et non depuis l'AST comme extractAssertAttributes()) :
             * ici on n'a accès qu'à des VALEURS scalaires/array, pas à des nœuds de code,
             * donc on doit re-sérialiser chaque type manuellement (chaînes entre guillemets,
             * booléens en mots-clés littéraux, tableaux entre crochets…).
             */
            $argStrings = [];
            foreach ($args as $key => $value) {
                $formatted = match (true) {
                    is_string($value) => '"' . $value . '"',
                    is_bool($value)   => $value ? 'true' : 'false',
                    is_null($value)   => 'null',
                    is_array($value)  => '[' . implode(', ', array_map(static fn(mixed $v): string => is_string($v) ? '"' . $v . '"' : (string)$v, $value)) . ']',
                    default           => (string)$value,
                };

                /* Les arguments NOMMÉS (ex. 'maxSize' => '5M') sont reproduits en syntaxe nommée PHP 8 ('maxSize: "5M"') ; les arguments POSITIONNELS (clé entière auto-incrémentée par Reflection) sont reproduits sans nom. */
                $argStrings[] = is_string($key) ? "{$key}: {$formatted}" : $formatted;
            }

            return '    #[Assert\\File(' . implode(', ', $argStrings) . ')]';
        }

        /**
         * Parcourt le code source pour détecter les champs uploadables via AST.
         *
         * @param string $source Contenu brut du fichier PHP de l'entité
         *
         * @return list<array{fieldName: string, fileNameProperty: string|null, mapping: string, propSuffix: string, uriSegment: string, assertFileRaw: string}>
         */
        private function scanUploadFieldsFromSource(string $source): array {
            try {
                $parser = (new ParserFactory())->createForNewestSupportedVersion();
                $stmts  = $parser->parse($source);

                $results   = [];
                $printer   = new Standard();
                $traverser = new NodeTraverser();

                /*
                 * Fallback utilisé uniquement quand VichUploader n'est PAS installé (cf.
                 * detectUploadFields()) : on ne peut alors pas instancier l'attribut
                 * #[UploadableField] par Reflection (la classe n'existe pas), donc on
                 * relit ses arguments BRUTS directement dans l'AST du fichier source.
                 * $this (l'instance de FieldAnalyser) est injecté dans la classe anonyme
                 * pour réutiliser uploadPropSuffix()/uploadUriSegment() sans dupliquer leur logique.
                 */
                $traverser->addVisitor(new class($results, $printer, $this) extends NodeVisitorAbstract {
                    public function __construct(private array &$results, private Standard $printer, private $analyser) {}

                    public function enterNode(Node $node) {
                        if ($node instanceof Node\Stmt\Property) {
                            $fieldName = $node->props[0]->name->name;
                            foreach ($node->attrGroups as $attrGroup) {
                                foreach ($attrGroup->attrs as $attr) {
                                    /* str_contains() (et non une comparaison stricte du FQCN) tolère un alias d'import quelconque pour l'attribut — seul le nom court 'UploadableField' doit apparaître dans la référence textuelle de l'AST. */
                                    if (str_contains($attr->name->toString(), 'UploadableField')) {
                                        $mapping      = '';
                                        $fileNameProp = null;
                                        foreach ($attr->args as $arg) {
                                            /*
                                             * Lecture des arguments NOMMÉS d'abord (ex.
                                             * #[UploadableField(mapping: 'avatar')]) : $arg->name
                                             * n'est non-null QUE pour cette syntaxe ; chaque
                                             * valeur n'est récupérée que si elle est bien un
                                             * littéral chaîne dans le code source (String_),
                                             * ce qui exclut volontairement une éventuelle
                                             * constante ou expression dynamique non résolvable statiquement par l'AST.
                                             */
                                            if ($arg->name?->name === 'mapping') {
                                                $mapping = $arg->value instanceof Node\Scalar\String_ ? $arg->value->value : '';
                                            }
                                            if ($arg->name?->name === 'fileNameProperty') {
                                                $fileNameProp = $arg->value instanceof Node\Scalar\String_ ? $arg->value->value : null;
                                            }
                                        }
                                        /* Fallback positional arguments if mapping not found yet */
                                        /* Si 'mapping' n'a pas été trouvé en argument nommé, on tente l'argument POSITIONNEL #0 (ex. #[UploadableField('avatar')] sans nom de paramètre explicite) — c'est la syntaxe alternative la plus courante pour ce premier paramètre. */
                                        if ($mapping === '' && isset($attr->args[0]) && $attr->args[0]->value instanceof Node\Scalar\String_) {
                                            $mapping = $attr->args[0]->value->value;
                                        }

                                        $assertFileRaw = '    #[Assert\\File]';
                                        /*
                                         * On reparcourt TOUS les groupes d'attributs de la MÊME
                                         * propriété (pas seulement celui d'UploadableField) à la
                                         * recherche d'un attribut dont le nom contient 'File'
                                         * (typiquement #[Assert\File(...)]) : prettyPrint()
                                         * régénère alors le code PHP exact de ce groupe pour le
                                         * recopier tel quel dans le DTO d'upload.
                                         */
                                        foreach ($node->attrGroups as $ag) {
                                            foreach ($ag->attrs as $a) {
                                                if (str_contains($a->name->toString(), 'File')) {
                                                    $assertFileRaw = '    ' . $this->printer->prettyPrint([$ag]);
                                                }
                                            }
                                        }

                                        $propSuffix      = $this->analyser->uploadPropSuffix($fieldName);
                                        $this->results[] = [
                                            'fieldName'        => $fieldName,
                                            'fileNameProperty' => $fileNameProp,
                                            'mapping'          => $mapping,
                                            'propSuffix'       => $propSuffix,
                                            'uriSegment'       => $this->analyser->uploadUriSegment($propSuffix),
                                            'assertFileRaw'    => $assertFileRaw,
                                        ];
                                    }
                                }
                            }
                        }
                    }
                });
                $traverser->traverse($stmts);

                return $results;
            }
            catch (\Exception) {
                return [];
            }
        }

        /**
         * Dérive le suffixe CamelCase pour les artefacts d'upload.
         * Exemple : 'imageFile' → 'Image' ; 'avatar' → 'Avatar'
         */
        public function uploadPropSuffix(string $fieldName): string {
            /* Le suffixe 'File' (convention VichUploader, ex. 'imageFile' pour la propriété qui reçoit l'UploadedFile temporaire) est retiré car il n'a pas sa place dans les noms de classes générées ({Entity}Upload{Suffix}Dto) — seul 'Image' importe sémantiquement. */
            $name = (string)preg_replace('/File$/i', '', $fieldName);

            return ucfirst($name !== '' ? $name : $fieldName);
        }

        /**
         * Convertit un suffixe CamelCase en segment d'URI kebab-case.
         * Exemple : 'ProfilePhoto' → 'upload-profile-photo'
         */
        public function uploadUriSegment(string $propSuffix): string {
            /* Même technique regex que NamespaceResolver::toApiPlatformUriBase() : '(?<!^)[A-Z]' insère un tiret devant chaque majuscule sauf la première, pour transformer 'ProfilePhoto' en 'Profile-Photo' avant la mise en minuscules. */
            $kebab = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $propSuffix));

            return 'upload-' . $kebab;
        }
    }
