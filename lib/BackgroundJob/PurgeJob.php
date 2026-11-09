<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\BackgroundJob;

use OCA\ScimServer\Service\UserService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Hard-deletes accounts that were deprovisioned and whose purge date has passed. */
class PurgeJob extends TimedJob {
	public function __construct(ITimeFactory $time, private UserService $users) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void {
		$this->users->purgeDue();
	}
}
