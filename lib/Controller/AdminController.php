<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Controller;

use OCA\ScimServer\Db\Store;
use OCA\ScimServer\Service\Settings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** Admin-only (Nextcloud's default for controller methods): settings and token management. */
class AdminController extends Controller {
	public function __construct(string $appName, IRequest $request, private Store $store, private Settings $settings) {
		parent::__construct($appName, $request);
	}

	#[PasswordConfirmationRequired]
	public function save(string $deleteMode, int $purgeDays, bool $protectAdmins, bool $managedOnly): DataResponse {
		try {
			$this->settings->save($deleteMode, $purgeDays, $protectAdmins, $managedOnly);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		if ($deleteMode !== 'disable_purge') {
			// Otherwise old dates would all fire at once when the purge mode is selected again.
			$this->store->clearPendingDelete();
		}
		return new DataResponse(['ok' => true]);
	}

	#[PasswordConfirmationRequired]
	public function createToken(string $label): DataResponse {
		return new DataResponse($this->store->createToken(trim($label) ?: 'token'));
	}

	#[PasswordConfirmationRequired]
	public function deleteToken(int $id): DataResponse {
		$this->store->deleteToken($id);
		return new DataResponse(['ok' => true]);
	}
}
