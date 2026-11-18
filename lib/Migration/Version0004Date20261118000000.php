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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Groups excluded by the group filter keep a bookkeeping row (so the IdP gets stable ids) but no Nextcloud group. */
class Version0004Date20261118000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('scim_groups');
		if ($table->hasColumn('ignored')) {
			return null;
		}
		$table->addColumn('ignored', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		return $schema;
	}
}
