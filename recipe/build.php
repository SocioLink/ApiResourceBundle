<?php

	/*
	 * Copyright (c) 2026, Xavier KONGOLO.
	 * All rights reserved.
	 *
	 * This source code is proprietary and confidential.
	 * Unauthorized copying, distribution, modification, publication,
	 * or use of this source code, in whole or in part, is strictly prohibited
	 * without the prior written authorization of the copyright owner.
	 *
	 * Author: Xavier KONGOLO <xsompwe@gmail.com>
	 * Creation Date: 27/09/2026
	 *
	 * Description: Compilation des recettes Symfony Flex du dossier recipe/ au format d'un dépôt de recettes privé.
	 *              Les sources suivent le format standard des recettes (vendor/package/version/ avec manifest.json,
	 *              fichiers à copier et post-install.txt) ; ce script produit index.json et un fichier
	 *              vendor.package.version.json par recette, servis par l'API GitHub à Flex via extra.symfony.endpoint.
	 *              La référence (ref) de chaque recette est l'empreinte SHA-1 de son contenu : elle change
	 *              automatiquement à chaque modification, ce qui permet à Flex de détecter les mises à jour.
	 *              Usage : php recipe/build.php [--repository=SocioLink/ApiResourceBundle] [--branch=main] [--output=flex]
	 */

	declare(strict_types=1);

	$options    = getopt('', ['repository:', 'branch:', 'output:']);
	$repository = $options['repository'] ?? 'SocioLink/ApiResourceBundle';
	$branch     = $options['branch'] ?? 'main';
	$output     = rtrim(str_replace('\\', '/', $options['output'] ?? dirname(__DIR__) . '/flex'), '/');
	$source     = str_replace('\\', '/', __DIR__);

	/* Chemin du dossier compilé DANS le dépôt (préfixe des URL de l'API GitHub), vide s'il est à la racine. */
	$root       = str_replace('\\', '/', dirname(__DIR__));
	$repoPrefix = str_starts_with($output, $root . '/') ? substr($output, strlen($root) + 1) . '/' : '';

	/**
	 * Découpe un contenu texte en lignes, au format « contents » des recettes compilées.
	 *
	 * @return list<string>
	 */
	function recipeLines(string $content): array {
		return explode("\n", str_replace("\r\n", "\n", $content));
	}

	$index = [];

	foreach (glob($source . '/*/*/*/manifest.json') ?: [] as $manifestPath) {
		$recipeDir = dirname($manifestPath);
		[$vendor, $package, $version] = array_slice(explode('/', str_replace('\\', '/', $recipeDir)), -3);
		$name = "{$vendor}/{$package}";

		$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

		if (is_file($recipeDir . '/post-install.txt')) {
			$manifest['post-install-output'] = recipeLines(rtrim((string)file_get_contents($recipeDir . '/post-install.txt'), "\n"));
		}

		/* Fichiers copiés par copy-from-recipe : chemin relatif au dossier de la recette. */
		$files    = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($recipeDir, FilesystemIterator::SKIP_DOTS));

		foreach ($iterator as $file) {
			$relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($recipeDir) + 1);

			if (in_array($relative, ['manifest.json', 'post-install.txt'], true)) {
				continue;
			}

			$files[$relative] = ['contents' => recipeLines((string)file_get_contents($file->getPathname())), 'executable' => false];
		}

		ksort($files);

		$recipe = ['manifest' => $manifest, 'files' => $files];
		$recipe['ref'] = sha1(json_encode($recipe, JSON_THROW_ON_ERROR));

		$dotted = str_replace('/', '.', $name);

		if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
			fwrite(STDERR, "Impossible de créer {$output}\n");
			exit(1);
		}

		file_put_contents(
			"{$output}/{$dotted}.{$version}.json",
			json_encode(['manifests' => [$name => $recipe]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
		);

		$index[$name][] = $version;
		echo "Recette compilée : {$name} {$version} (ref {$recipe['ref']})\n";
	}

	if ($index === []) {
		fwrite(STDERR, "Aucune recette trouvée dans {$source}\n");
		exit(1);
	}

	ksort($index);

	foreach ($index as &$versions) {
		usort($versions, 'version_compare');
	}

	unset($versions);

	file_put_contents($output . '/index.json', json_encode([
		'recipes'    => $index,
		'branch'     => $branch,
		'is_contrib' => true,
		'_links'     => [
			'repository'      => "github.com/{$repository}",
			'origin_template' => "{package}:{version}@github.com/{$repository}:{$branch}",
			'recipe_template' => "https://api.github.com/repos/{$repository}/contents/{$repoPrefix}{package_dotted}.{version}.json?ref={$branch}",
		],
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

	echo "Index écrit : {$output}/index.json\n";
