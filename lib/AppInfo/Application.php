<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\AppInfo;

use OCA\ScimServer\Listener\CleanupListener;
use OCA\ScimServer\Middleware\ScimMiddleware;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'scim_server';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerMiddleware(ScimMiddleware::class);
		foreach ([UserDeletedEvent::class, GroupDeletedEvent::class, UserChangedEvent::class] as $event) {
			$context->registerEventListener($event, CleanupListener::class);
		}
	}

	public function boot(IBootContext $context): void {
	}
}
