<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** externalId becomes unique in the database too, so concurrent requests cannot give two resources the same one. */
class Version0002Date20261114000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		foreach (['scim_users', 'scim_groups'] as $name) {
			$t = $schema->getTable($name);
			if ($t->hasIndex($name . '_ext')) {
				$t->dropIndex($name . '_ext');
				$t->addUniqueIndex(['external_id'], $name . '_extu');
			}
		}
		return $schema;
	}
}
