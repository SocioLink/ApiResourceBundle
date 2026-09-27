<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Exception levée lorsqu'une combinaison d'options de la commande est invalide.
     */

    declare(strict_types=1);

    namespace BlackSheep\Symfony\ApiResourceBundle\Console;

    use InvalidArgumentException;

    /**
     * Combinaison d'options invalide (options exclusives, --reinit sans --force, --public sans --with-mercure).
     *
     * Le message est destiné à l'utilisateur de la commande : il est affiché tel quel.
     */
    final class InvalidOptionsException extends InvalidArgumentException {
    }
