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

class Version0001Date20261109000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('scim_tokens')) {
			$t = $schema->createTable('scim_tokens');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('token_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('last_used', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['token_hash'], 'scim_tok_hash');
		}

		foreach (['scim_users' => 'uid', 'scim_groups' => 'gid'] as $name => $key) {
			if ($schema->hasTable($name)) {
				continue;
			}
			$t = $schema->createTable($name);
			$t->addColumn($key, Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('scim_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('external_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			if ($name === 'scim_users') {
				$t->addColumn('pending_delete_at', Types::BIGINT, ['notnull' => false]);
				$t->addIndex(['pending_delete_at'], 'scim_usr_pend');
			}
			$t->setPrimaryKey([$key]);
			$t->addUniqueIndex(['scim_id'], $name . '_sid');
			$t->addIndex(['external_id'], $name . '_ext');
		}
		return $schema;
	}
}
