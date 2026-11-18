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

	/**
	 * Group ids that SCIM may not change or delete, besides `admin`.
	 * ponytail: no UI, set with `occ config:app:set scim_server protected_groups --value="gid1,gid2"`.
	 * @return list<string>
	 */
	public function protectedGroups(): array {
		return array_values(array_filter(array_map('trim', explode(',', $this->config->getValueString(self::APP, 'protected_groups', '')))));
	}

	/** Accept SCIM requests over plain HTTP (test setups only): `occ config:app:set scim_server allow_http --value=true --type=boolean`. */
	public function allowHttp(): bool {
		return $this->config->getValueBool(self::APP, 'allow_http', false);
	}

	/**
	 * SCIM only sees and changes the accounts and groups that were created through SCIM. Off: everything
	 * in Nextcloud is listed and can be taken over ("adopted") by the identity provider.
	 */
	public function managedOnly(): bool {
		return $this->config->getValueBool(self::APP, 'managed_only', true);
	}

	/** Regular expression (without delimiters) a group name must match to be created in Nextcloud; empty = all. */
	public function groupRegex(): string {
		return $this->config->getValueString(self::APP, 'group_regex', '');
	}

	public function groupAllowed(string $name): bool {
		$regex = $this->groupRegex();
		return $regex === '' || preg_match(self::pattern($regex), $name) === 1;
	}

	private static function pattern(string $regex): string {
		return '~' . str_replace('~', '\\~', $regex) . '~u';
	}

	public function save(string $mode, int $days, bool $protect, bool $managedOnly, string $groupRegex = ''): void {
		if (!in_array($mode, self::MODES, true) || $days < 1 || $days > 3650
			|| ($groupRegex !== '' && @preg_match(self::pattern($groupRegex), '') === false)) {
			throw new \InvalidArgumentException('Invalid settings (is the group filter a valid regular expression?)');
		}
		$this->config->setValueString(self::APP, 'group_regex', $groupRegex);
		$this->config->setValueString(self::APP, 'delete_mode', $mode);
		$this->config->setValueInt(self::APP, 'purge_days', $days);
		$this->config->setValueBool(self::APP, 'protect_admins', $protect);
		$this->config->setValueBool(self::APP, 'managed_only', $managedOnly);
	}
}
