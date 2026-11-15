<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** "managed": the account or group was created through SCIM (see Settings::managedOnly). */
class Version0003Date20261115000000 extends SimpleMigrationStep {
	private const TABLES = ['scim_users', 'scim_groups'];

	public function __construct(private IDBConnection $db) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		foreach (self::TABLES as $name) {
			$t = $schema->getTable($name);
			if (!$t->hasColumn('managed')) {
				$t->addColumn('managed', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
		}
		return $schema;
	}

	/**
	 * Earlier versions did not record who created a resource. One with an externalId was written by
	 * the identity provider, so it counts as SCIM's; everything else was only ever looked at.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach (self::TABLES as $name) {
			$q = $this->db->getQueryBuilder();
			$q->update($name)->set('managed', $q->createNamedParameter(1))->where($q->expr()->isNotNull('external_id'))->executeStatement();
		}
	}
}
