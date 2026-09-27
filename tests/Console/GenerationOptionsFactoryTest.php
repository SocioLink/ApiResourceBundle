<?php

    /*
     * Copyright (c) 2026.
     * Date: 21/09/2026
     * Author: Xavier KONGOLO <xsompwe@gmail.com>
     * Description: Tests unitaires de la validation des options de la commande (GenerationOptionsFactory).
     */

    declare(strict_types=1);

    namespace BlackSheep\Symfony\ApiResourceBundle\Tests\Console;

    use Symfony\Component\Console\Input\ArrayInput;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Input\InputDefinition;
    use PHPUnit\Framework\TestCase;
    use BlackSheep\Symfony\ApiResourceBundle\Console\InvalidOptionsException;
    use BlackSheep\Symfony\ApiResourceBundle\Console\GenerationOptionsFactory;

    /**
     * Vérifie les règles de validation : options mutuellement exclusives, --reinit sans --force,
     * --public sans --with-mercure, et la construction normale des options.
     */
    final class GenerationOptionsFactoryTest extends TestCase {
        private function input(array $options): ArrayInput {
            $definition = new InputDefinition();

            foreach (['force', 'reinit', 'only-resource', 'with-provider', 'toggle-boolean', 'detach-boolean', 'all',
                      'dry-run', 'preview', 'interactive', 'with-tests', 'sub-resources', 'graphql-filters', 'with-mercure', 'public'] as $name) {
                $definition->addOption(new InputOption($name, null, InputOption::VALUE_NONE));
            }

            $input = new ArrayInput(array_combine(array_map(static fn(string $o): string => "--{$o}", array_keys($options)), array_values($options)), $definition);

            return $input;
        }

        public function testExclusiveOptionsCombinedThrow(): void {
            $this->expectException(InvalidOptionsException::class);
            new GenerationOptionsFactory()->create($this->input(['only-resource' => true, 'with-provider' => true]));
        }

        public function testReinitWithoutForceThrows(): void {
            $this->expectException(InvalidOptionsException::class);
            new GenerationOptionsFactory()->create($this->input(['reinit' => true]));
        }

        public function testPublicWithoutWithMercureThrows(): void {
            $this->expectException(InvalidOptionsException::class);
            new GenerationOptionsFactory()->create($this->input(['public' => true]));
        }

        public function testPublicWithWithMercureIsAccepted(): void {
            $options = new GenerationOptionsFactory()->create($this->input(['with-mercure' => true, 'public' => true]));

            $this->assertTrue($options->withMercure);
            $this->assertTrue($options->publicMercure);
        }

        public function testWithMercureAloneDefaultsToPrivate(): void {
            $options = new GenerationOptionsFactory()->create($this->input(['with-mercure' => true]));

            $this->assertTrue($options->withMercure);
            $this->assertFalse($options->publicMercure);
        }

        public function testNoFlagsProducesDefaultOptions(): void {
            $options = new GenerationOptionsFactory()->create($this->input([]));

            $this->assertFalse($options->force);
            $this->assertFalse($options->withMercure);
            $this->assertFalse($options->isReadOnly());
        }

        public function testForceAndReinitTogetherAreAccepted(): void {
            $options = new GenerationOptionsFactory()->create($this->input(['force' => true, 'reinit' => true]));

            $this->assertTrue($options->force);
            $this->assertTrue($options->reinit);
        }
    }
