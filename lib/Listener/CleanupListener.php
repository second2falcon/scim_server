<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Listener;

use OCA\ScimServer\Db\Store;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;

/**
 * Keeps the bookkeeping in step with changes made outside SCIM, so a later account or group with the
 * same id never inherits an old SCIM id or deletion date, and an account that an administrator
 * enabled again is no longer scheduled for deletion.
 * @template-implements IEventListener<Event>
 */
class CleanupListener implements IEventListener {
	public function __construct(private Store $store) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$this->store->deleteUserRow($event->getUser()->getUID());
		} elseif ($event instanceof GroupDeletedEvent) {
			$this->store->deleteGroupRow($event->getGroup()->getGID());
		} elseif ($event instanceof UserChangedEvent && $event->getFeature() === 'enabled' && $event->getValue()) {
			$this->store->clearPendingDelete($event->getUser()->getUID());
		}
	}
}
