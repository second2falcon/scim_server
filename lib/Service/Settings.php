<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Service;

use OCP\IAppConfig;

/** Admin-configurable behaviour. */
class Settings {
	public const APP = 'scim_server';
	/** disable: only disable; disable_purge: disable, hard-delete after purge_days; delete: delete at once */
	public const MODES = ['disable', 'disable_purge', 'delete'];

	public function __construct(private IAppConfig $config) {
	}

	public function deleteMode(): string {
		$mode = $this->config->getValueString(self::APP, 'delete_mode', 'disable_purge');
		return in_array($mode, self::MODES, true) ? $mode : 'disable_purge';
	}

	public function purgeDays(): int {
		return max(1, $this->config->getValueInt(self::APP, 'purge_days', 30));
	}

	/** Never disable/delete administrators or change the admin group through SCIM. */
	public function protectAdmins(): bool {
		return $this->config->getValueBool(self::APP, 'protect_admins', true);
	}

	public function save(string $mode, int $days, bool $protect): void {
		if (!in_array($mode, self::MODES, true) || $days < 1 || $days > 3650) {
			throw new \InvalidArgumentException('Invalid settings');
		}
		$this->config->setValueString(self::APP, 'delete_mode', $mode);
		$this->config->setValueInt(self::APP, 'purge_days', $days);
		$this->config->setValueBool(self::APP, 'protect_admins', $protect);
	}
}
