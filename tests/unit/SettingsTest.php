<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Tests;

use OCA\ScimServer\Service\Settings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {
	private function settings(string $regex): Settings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn ($app, $key, $default) => $key === 'group_regex' ? $regex : $default);
		return new Settings($config);
	}

	public function testEmptyFilterAllowsEverything(): void {
		$this->assertTrue($this->settings('')->groupAllowed('anything'));
	}

	public function testFilterMatchesWithoutDelimitersAndWithTildes(): void {
		$s = $this->settings('^(nextcloud|nc)-');
		$this->assertTrue($s->groupAllowed('nextcloud-staff'));
		$this->assertTrue($s->groupAllowed('nc-admins'));
		$this->assertFalse($s->groupAllowed('everyone'));
		$this->assertTrue($this->settings('a~b')->groupAllowed('xa~by'));
	}

	public function testInvalidRegexIsRejectedOnSave(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('setValueString');
		$this->expectException(\InvalidArgumentException::class);
		(new Settings($config))->save('disable', 30, true, '(unclosed');
	}
}
