<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Settings;

use OCA\ScimServer\AppInfo\Application;
use OCA\ScimServer\Db\Store;
use OCA\ScimServer\Service\Settings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(private Store $store, private Settings $settings, private IURLGenerator $urls) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'tokens' => $this->store->listTokens(),
			'deleteMode' => $this->settings->deleteMode(),
			'purgeDays' => $this->settings->purgeDays(),
			'protectAdmins' => $this->settings->protectAdmins(),
			'managedOnly' => $this->settings->managedOnly(),
			'groupRegex' => $this->settings->groupRegex(),
			'baseUrl' => preg_replace('#/ServiceProviderConfig$#', '', $this->urls->linkToRouteAbsolute('scim_server.scim.serviceProviderConfig')),
			'settingsUrl' => $this->urls->linkToRoute('scim_server.admin.save'),
			'tokensUrl' => $this->urls->linkToRoute('scim_server.admin.createToken'),
		], '');
	}

	public function getSection(): string {
		return 'security';
	}

	public function getPriority(): int {
		return 60;
	}
}
