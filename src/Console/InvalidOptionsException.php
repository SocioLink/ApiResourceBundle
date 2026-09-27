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
	 * Creation Date: 21/09/2026
	 *
	 * Description: Exception levée par GenerationOptionsFactory lorsqu'une combinaison d'options de generate:resource est
	 *              invalide (options de mode combinées, --reinit sans --force, --public sans --with-mercure).
	 *              Son message est destiné à l'utilisateur final : la commande l'affiche tel quel puis se termine en échec,
	 *              avant toute lecture ou écriture de fichier.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Console;

	use InvalidArgumentException;

	/**
	 * Combinaison d'options invalide (options exclusives, --reinit sans --force, --public sans --with-mercure).
	 *
	 * Le message est destiné à l'utilisateur de la commande : il est affiché tel quel.
	 */
	final class InvalidOptionsException extends InvalidArgumentException {
	}
