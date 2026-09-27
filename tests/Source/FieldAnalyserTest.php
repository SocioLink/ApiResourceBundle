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
	 * Description: Tests unitaires de la conversion des types Doctrine en types PHP (FieldAnalyser::toPhpType()).
	 *              Vérifient le type personnalisé prédéfini `phone_number`, l'ajout et la redéfinition de types par la
	 *              configuration `custom_types`, une classe sans espace de noms, et le repli sur string (signalé) pour
	 *              un type inconnu.
	 */

	declare(strict_types=1);

	namespace SocioLink\ApiResourceBundle\Tests\Source;

	use PHPUnit\Framework\TestCase;
	use SocioLink\ApiResourceBundle\Source\FieldAnalyser;
	use SocioLink\ApiResourceBundle\Source\GeneratorConfig;
	use SocioLink\ApiResourceBundle\Source\NamespaceResolver;

	final class FieldAnalyserTest extends TestCase {
		/**
		 * @param array<string, string> $customTypes
		 */
		private function analyser(array $customTypes = []): FieldAnalyser {
			$config = new GeneratorConfig(sys_get_temp_dir(), customTypes: $customTypes);

			return new FieldAnalyser(new NamespaceResolver($config), $config);
		}

		public function testPhoneNumberIsPredefined(): void {
			$analyser = $this->analyser();

			$this->assertSame('PhoneNumber', $analyser->toPhpType('phone_number'));
			$this->assertSame('libphonenumber\PhoneNumber', $analyser->customTypeClass('phone_number'));
			$this->assertSame([], $analyser->getUnrecognizedTypes());
		}

		public function testConfigurationAddsAndOverridesCustomTypes(): void {
			$analyser = $this->analyser(['money' => '\App\ValueObject\Money', 'phone_number' => 'App\Phone', 'point' => 'Point']);

			$this->assertSame('Money', $analyser->toPhpType('money'));
			$this->assertSame('App\ValueObject\Money', $analyser->customTypeClass('money')); /* antislash initial retiré */
			$this->assertSame('App\Phone', $analyser->customTypeClass('phone_number'));
			$this->assertSame('Point', $analyser->toPhpType('point')); /* classe sans espace de noms : nom intact */
		}

		public function testUnknownTypeFallsBackToStringAndIsReported(): void {
			$analyser = $this->analyser();

			$this->assertSame('string', $analyser->toPhpType('binary'));
			$this->assertNull($analyser->customTypeClass('binary'));
			$this->assertSame(['binary'], $analyser->getUnrecognizedTypes());
		}
	}
