# SocioLink\ApiResourceBundle

[![Packagist](https://img.shields.io/packagist/v/sociolink/api-resource-bundle.svg)](https://packagist.org/packages/sociolink/api-resource-bundle)
[![License](https://img.shields.io/badge/license-proprietary-red.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.5-777bb4.svg)](composer.json)

Génère les ressources API Platform (DTOs, State Processors/Providers, paramètres de filtrage
`#[QueryParameter]`) à partir des entités Doctrine, via une commande CLI interactive.

**Outil de développement.** Le code qu'il génère ne référence jamais le bundle — il ne dépend que
d'API Platform, de Symfony et de Doctrine — et fonctionne donc en production sans lui.

> **Licence.** Ce dépôt est public afin d'être distribué via Packagist, mais il n'est **pas**
> open source : aucun droit d'utilisation, de modification ou de redistribution n'est accordé en
> dehors des projets explicitement autorisés par l'auteur. Voir [LICENSE](LICENSE).

## Prérequis

|              | Minimum                                                                         |
|--------------|---------------------------------------------------------------------------------|
| PHP          | 8.5                                                                             |
| Symfony      | 7.4 (compatible 7.4, 8.0, 8.1 et suivants)                                      |
| API Platform | 5.0, composants `metadata`, `state`, `doctrine-orm` et `validator`              |
| Doctrine     | ORM 3.5, DBAL 4.0                                                               |

Le bundle dépend des **composants** d'API Platform, jamais du paquet monolithique `api-platform/core`.
Il s'installe donc sans rien retirer, que le projet utilise les composants (`api-platform/symfony`,
installation actuelle) ou `api-platform/core` (qui remplace ces composants). Les tests générés par
`--with-tests` utilisent en plus `api-platform/test` (`ApiPlatform\Test\ApiTestCase`) :

```bash
composer require --dev api-platform/test
```

## Installation

```bash
composer require --dev sociolink/api-resource-bundle
```

### Avec Symfony Flex

Dans un projet utilisant **Symfony Flex** (tout projet créé avec `symfony new` ou `symfony/skeleton`),
le bundle est enregistré automatiquement. Le paquet étant installé avec `--dev`, Flex le réserve aux
environnements `dev` et `test`.

Par défaut, Flex applique une recette **auto-générée**, qui enregistre le bundle sans créer de fichier
de configuration (toutes les clés sont optionnelles, voir [Configuration](#configuration)) :

```
Configuring sociolink/api-resource-bundle (>=1.0): From auto-generated recipe
```

Le dépôt publie aussi sa **propre recette** (dossier `flex/`, compilé depuis `recipe/`), qui crée en
plus `config/packages/sociolink_api_resource.yaml`, toutes les clés en commentaire avec leur valeur par
défaut. Pour l'utiliser, déclarez ce dépôt de recettes dans le `composer.json` **du projet**, avant
d'installer le bundle :

```json
"extra": {
    "symfony": {
        "endpoint": [
            "https://api.github.com/repos/SocioLink/ApiResourceBundle/contents/flex/index.json",
            "flex://defaults"
        ]
    }
}
```

`flex://defaults` conserve les recettes officielles de Symfony pour les autres paquets. Composer
affiche alors `From github.com/SocioLink/ApiResourceBundle:main`.

### Sans Flex

Ajoutez le bundle à la main dans `config/bundles.php` :

```php
return [
    // ...
    SocioLink\ApiResourceBundle\ApiResourceBundle::class => ['dev' => true, 'test' => true],
];
```

## Utilisation

```bash
# Menu interactif (terminal requis) : entités regroupées par namespace, « All » en dernier
php bin/console generate:resource

# Une entité précise — nom court ou FQCN
php bin/console generate:resource Article
php bin/console generate:resource 'App\Entity\Blog\Article'

# Toutes les entités, sans menu (CI, --no-interaction)
php bin/console generate:resource '*' --force

# Écraser les fichiers existants
php bin/console generate:resource Article --force

# Aperçu sans écriture
php bin/console generate:resource Article --preview
```

Sans argument :

- **dans un terminal**, le menu numéroté s'affiche (voir ci-dessous) ;
- **sans terminal** (CI, `--no-interaction`), la commande échoue avec un message explicite —
  jamais de traitement implicite de toutes les entités.

### Menu interactif

```
Sélection de l'entité
----------------------

App\Entity
  [1] User

App\Entity\Blog
  [2] Article
  [3] Comment

App\Entity\Shop
  [4] Order
  [5] Product

  [6] All — toutes les entités (5)

Numéro de votre choix (1-6):
```

Les entités sont regroupées par namespace (ordre alphabétique), numérotées en continu, choix **unique** ; « All » (dernier numéro) traite toutes les entités existantes.

## Options

### Destructrices

| Option            | Effet                                                                                                                                               |
|-------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------|
| `--force` / `-f`  | Écrase les fichiers existants et réinjecte `#[ApiResource]`.                                                                                        |
| `--reinit` / `-r` | Avec `--force` : supprime les dossiers `DTO/<Entité>` et `State/<Entité>` des **seules** entités traitées (confirmation demandée dans un terminal). |

### Mutuellement exclusives (une seule à la fois)

| Option                    | Effet                                                              |
|---------------------------|--------------------------------------------------------------------|
| `--only-resource` / `-o`  | Injecte `#[ApiResource]` + filtres uniquement, aucun artefact.     |
| `--with-provider` / `-w`  | Mode par défaut + Provider pour `GET {id}`, câblé sur `Get`.       |
| `--toggle-boolean` / `-t` | Un `ToggleDto`/`ToggleProcessor` unique pour tous les booléens.    |
| `--detach-boolean` / `-d` | Un DTO + Processor `PATCH` individuel par booléen.                 |
| `--all` / `-a`            | `#[ApiResource]` libre + tous les artefacts générés mais non liés. |

### Indépendantes

| Option              | Effet                                                                                                                              |
|---------------------|------------------------------------------------------------------------------------------------------------------------------------|
| `--dry-run`         | Simule sans écrire aucun fichier — **entités comprises**.                                                                          |
| `--preview`         | Affiche le code généré dans la console (implique `--dry-run`).                                                                     |
| `--with-tests`      | Génère des tests fonctionnels `ApiTestCase` (paquet `api-platform/test`).                                                          |
| `--sub-resources`   | Sous-ressources pour les relations `OneToMany`.                                                                                    |
| `--graphql-filters` | Reporte les paramètres de filtrage sur `QueryCollection` (GraphQL).                                                                |
| `--with-mercure`    | Injecte la directive `mercure` (**privée** par défaut : `mercure: ['private' => true]`).                                           |
| `--public`          | Avec `--with-mercure` : `mercure: true` (mises à jour publiées **publiquement**) au lieu de privées. Refusé sans `--with-mercure`. |

Sans `--with-mercure`, **aucune** directive `mercure` n'est injectée.

La commande se termine en échec (code 1) si une entité n'a pas pu être traitée ; les autres entités
sont tout de même générées.

### Artefacts générés

| Artefact                               | Opération                                                               |
|----------------------------------------|-------------------------------------------------------------------------|
| `<Entité>CreateDto` + Processor        | `POST` — contraintes `#[Assert\*]` de l'entité recopiées                |
| `<Entité>UpdateDto` + Processor        | `PATCH` partiel — `null` = champ non envoyé (`NotBlank` accepte `null`) |
| `<Entité>ToggleDto` + Processor        | `PATCH /{id}/toggle` (`--toggle-boolean`)                               |
| `<Entité><Champ>Dto` + Processor       | `PATCH /{id}/toggle-<champ>` (`--detach-boolean`)                       |
| `<Entité>Upload<Champ>Dto` + Processor | `PATCH /{id}/<champ>` multipart, pour chaque `#[Vich\UploadableField]`  |
| `<Entité>Provider`                     | `GET /{id}` avec chargement des relations ToOne (`--with-provider`)     |

## Filtres générés

Les filtres sont des paramètres `#[QueryParameter]` attachés à `GetCollection` (`#[ApiFilter]`,
déprécié depuis API Platform 4.4, n'est jamais généré) :

| Champ Doctrine                    | Filtre généré                                      | Requête                        |
|-----------------------------------|----------------------------------------------------|--------------------------------|
| `string`, énumération, type perso | `ExactFilter`                                      | `?name=Chair`                  |
| `uuid` / `guid` (hors `id`)       | `UuidFilter`                                       | `?reference=<uuid>`            |
| `boolean`                         | `ExactFilter` (schéma booléen)                     | `?active=true`                 |
| `integer`, `float`, `decimal`     | `ChainFilter` (`ExactFilter` + `ComparisonFilter`) | `?price=25` · `?price[gte]=10` |
| date / heure                      | `DateFilter`                                       | `?createdAt[after]=2026-01-01` |
| relation ToOne                    | `IriFilter`                                        | `?author=/api/authors/{uuid}`  |
| relation ToMany, champ nullable   | `ExistsFilter`                                     | `?exists[deletedAt]=true`      |
| tri                               | `SortFilter`                                       | `?order[name]=asc`             |

Exclus de tout filtre : `id`, `text`, `json`, `array`, `simple_array`, `blob`, `binary`, `dateinterval`,
ainsi que les champs listés dans `filters.excluded_fields`.

## Configuration

Toutes les clés sont optionnelles ; les valeurs par défaut conviennent à une application Symfony
standard (`App\` dans `src/`). Fichier `config/packages/dev/sociolink_api_resource.yaml` :

```yaml
sociolink_api_resource:
    root_namespace        : App                  # racine PSR-4 du projet
    source_dir            : src                      # dossier des sources, relatif au projet
    entity_namespace      : Entity             # relatif à root_namespace → App\Entity
    dto_namespace         : DTO                   # relatif à root_namespace → App\DTO
    state_namespace       : State               # relatif à root_namespace → App\State
    tests                 :
        namespace: App\Tests\Functional
        directory: tests/Functional
    admin_role            : ROLE_ADMIN               # rôle requis pour lever un soft-erase (itErased)
    system_fields         : # champs exclus du CreateDto
        - details
        - status
        - createdAt
        # ...
    update_system_fields  : # champs exclus de l'UpdateDto
        - details
        - createdAt
        # ...
    boolean_special_fields: # booléens à logique soft-delete / soft-erase
        - itDeleted
        - itErased
    filters               :
        excluded_fields         : [ ]              # champs exclus des filtres et du tri (ex. createdBy)
        sort_on_to_one_relations: false  # autorise order[author]=asc
    templates_directory   : null            # gabarits Twig prioritaires (surcharge), relatif au projet ou absolu
```

Pour surcharger un gabarit, copiez-le depuis `templates/` du bundle dans `templates_directory` : les
gabarits absents du dossier de surcharge restent ceux du bundle.

## Structure des fichiers générés

```
src/
├── DTO/
│   └── Article/
│       ├── ArticleCreateDto.php
│       └── ArticleUpdateDto.php
└── State/
    └── Article/
        ├── ArticleCreateProcessor.php
        └── ArticleUpdateProcessor.php

tests/
└── Functional/
    └── Article/
        └── ArticleApiTest.php        (si --with-tests)
```

## Qualité

```bash
composer install
composer check      # PHPStan (niveau 6) puis PHPUnit
```

La suite comprend un test de bout en bout (`tests/Functional`) : le bundle est enregistré dans un
noyau Symfony avec un EntityManager Doctrine réel, la commande est exécutée dans chaque mode, puis
chaque classe générée est chargée et chaque `#[ApiResource]` injecté est instancié avec API
Platform. `SOCIOLINK_E2E_KEEP=1 composer test` conserve le projet généré pour inspection.

Les tests de `tests/Recipe` vérifient aussi que le fichier de configuration de la recette Flex reprend
exactement les valeurs par défaut, et que `flex/` correspond à la recompilation de `recipe/`
(`php recipe/build.php`).

CI GitHub Actions (`.github/workflows/ci.yml`) : PHP 8.5 × Symfony 7.4/8.0/8.1 avec les dépendances
les plus récentes, plus une tâche avec les versions minimales de `composer.json` (`--prefer-lowest`).

## Portage depuis la commande de projet

Ce bundle remplace la commande `generate:resource` qui vivait auparavant dans un projet applicatif (`App\Command\GenerateResource`). Changements de comportement à connaître lors du portage :

- toutes les conventions codées en dur (`App\`, `src/`, `ROLE_ADMIN`, champs système) sont
  désormais dans `GeneratorConfig`, configurables via `sociolink_api_resource.*` ;
- la directive `mercure` n'est **plus injectée par défaut** — utilisez `--with-mercure` pour
  retrouver l'ancien comportement (`mercure: ['private' => true]`) ;
- les tests générés (`--with-tests`) vivent dans `tests/Functional/` et non plus `src/Tests/` ;
- sans argument, un terminal affiche désormais un **menu à choix unique** au lieu de traiter
  toutes les entités ;
- `--reinit` ne supprime plus les dossiers racines `DTO/` et `State/`, seulement ceux des entités traitées.

### Depuis une version antérieure du bundle (`BlackSheep\Symfony\ApiResourceBundle`)

- espace de noms : `SocioLink\ApiResourceBundle` (à mettre à jour dans `config/bundles.php`) ;
- racine de configuration : `sociolink_api_resource` (au lieu de `black_sheep_api_resource`) ;
- alias de commande : `sociolink:api-resource:generate` (au lieu de `black-sheep:api-resource:generate`).

## Licence

Ce projet est distribué sous licence propriétaire (voir [LICENSE](LICENSE)).
Le dépôt est public afin de permettre sa distribution via [Packagist](https://packagist.org/),
mais **aucun droit d'utilisation, de modification ou de redistribution n'est
accordé** en dehors des projets explicitement autorisés par l'auteur.

## Auteur

Xavier Kongolo