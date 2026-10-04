# Changelog

Toutes les modifications notables de ce bundle sont consignées dans ce fichier.

Le format suit [Keep a Changelog 1.1.0](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le
[versionnage sémantique 2.0.0](https://semver.org/lang/fr/) (voir [Versions](README.md#versions)).
Les ruptures de compatibilité sont signalées par **⚠ Rupture**.

## [Non publié]

## [2.0.0] — 2026-10-04

Version majeure : la forme du code généré change (voir « Migrer depuis 1.x » dans le [README](README.md#migrer-depuis-1x)).

### Modifié

- **⚠ Rupture** — Les filtres ne sont plus déclarés en `parameters: [...]` sur `#[ApiResource]` : chaque entité reçoit un
  **catalogue de filtres** `<Entité>Filters` (classe de l'application, `Filters/<Sous-espace>/`) que `GetCollection` et
  `QueryCollection` désignent par `extraProperties: ['collection_filters' => <Entité>Filters::class]`. C'est la façon de
  déclarer les filtres des API EasyTax. Le code généré change de forme (nouveau fichier, entité allégée) ; une régénération
  (`--force`) migre les entités existantes, écrase les catalogues existants et retire les imports devenus inutiles.
- Un champ `status` produit `new StatusParameter(required: true)` et un champ `realm` (relation ToOne ou colonne `uuid`)
  `new RealmParameter(required: true)`, au lieu d'un `QueryParameter` générique. Les tests générés (`--with-tests`) ajoutent
  ces paramètres obligatoires à chaque requête de collection.
- Une sous-ressource (`--sub-resources`) désigne le catalogue de l'entité enfant, généré au besoin.
- `--only-resource` et `--all` génèrent aussi le catalogue (l'entité y renvoie).
- Le catalogue n'est écrit que si l'injection dans l'entité a lieu : plus de fichier orphelin quand la ressource existe déjà.

### Ajouté

- Générateur de catalogue (`FilterCatalogueBuilder`, gabarit `filters.php.twig`, surchargeable comme les autres) ; un contrat
  du même espace de noms que le catalogue n'est pas importé.
- Configuration `filters.namespace`, `filters.catalogue_interface`, `filters.status_parameter` et `filters.realm_parameter` :
  les classes du contrat, de `StatusParameter` et de `RealmParameter` appartiennent à l'application et se règlent
  (défauts : `Filters`, `Filters\CollectionFiltersInterface`, `System\Filter\StatusParameter`, `System\Filter\RealmParameter`,
  relatifs à `root_namespace`). Le bundle ne les génère pas : voir « Ce que l'application doit fournir » dans le README.

## [1.0.1] — 2026-10-01

### Modifié

- Déplacement de la déclaration des paramètres de filtrage `#[QueryParameter]` (`parameters: [...]`)
  directement sur l'attribut `#[ApiResource]` (ressource principale et sous-ressources) plutôt que sur
  l'opération `GetCollection`. Cela permet à API Platform de cascader automatiquement et sans duplication
  les paramètres à la fois sur les collections REST et GraphQL.
- Simplification de la déclaration des opérations : `new GetCollection()` devient compact, et
  `graphQlOperations` ne duplique plus la liste des filtres.
- L'option `--graphql-filters` est conservée pour compatibilité ascendante (devenue implicite / no-op).

## [1.0.0] — 2026-09-30

Première version publiée. Les projets qui suivaient `dev-main` peuvent passer à la contrainte `^1.0`.

### Ajouté

- Commande `generate:resource` (alias `sociolink:api-resource:generate`) : génère depuis les entités
  Doctrine les DTOs (création, modification, toggle, booléens détachés, upload VichUploader), les State
  Processors et Providers, les tests fonctionnels (`--with-tests`) et injecte `#[ApiResource]` dans l'entité.
- Filtres générés en paramètres `#[QueryParameter]` sur `GetCollection` (et `QueryCollection` avec
  `--graphql-filters`) ; migration des anciens `#[ApiFilter]` de classe avec `--force`.
- Sous-ressources pour les relations `OneToMany` (`--sub-resources`).
- Options de génération : `--force`, `--reinit`, `--dry-run`, `--preview`, `--only-resource`, `--all`,
  `--with-provider`, `--toggle-boolean`, `--detach-boolean`, `--with-mercure`, `--public`.
- Configuration `sociolink_api_resource.*` (espaces de noms, dossiers, rôles, champs système), dont
  `custom_types` pour mapper des types Doctrine personnalisés ; `phone_number`
  (odolbeau/phone-number-bundle) reconnu sans configuration.
- Détection dynamique du champ identifiant des entités (plus de `id` codé en dur).
- Recette Symfony Flex (`recipe/`, compilée dans `flex/` par `recipe/build.php`).
- CI GitHub Actions : PHP 8.5 × Symfony 7.4 / 8.0 / 8.1, et dépendances minimales (`--prefer-lowest`).

### Corrigé

- La ressource principale est toujours le premier `#[ApiResource]` de la classe, et chaque sous-ressource
  porte un `shortName` propre (`{Parent}{Enfant}`) : API Platform ne renomme plus la ressource principale
  (`Place2`, URI `/activity/place2s`).
- Une sous-ressource existante est reconnue même dans un fichier réaligné par l'IDE
  (`uriTemplate : '…'`) : `--force` la remplace au lieu de la dupliquer.
- `--force --reinit` retire les sous-ressources dont la relation n'existe plus sur l'entité.

[Non publié]: https://github.com/SocioLink/ApiResourceBundle/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/SocioLink/ApiResourceBundle/compare/v1.0.1...v2.0.0
[1.0.1]: https://github.com/SocioLink/ApiResourceBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/SocioLink/ApiResourceBundle/releases/tag/v1.0.0
