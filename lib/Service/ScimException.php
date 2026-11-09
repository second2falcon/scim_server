<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Service;

/** An error that is reported to the SCIM client as RFC 7644 error JSON. */
class ScimException extends \RuntimeException {
	public function __construct(public readonly int $status, string $detail, public readonly ?string $scimType = null) {
		parent::__construct($detail);
	}

	public function toArray(): array {
		return array_filter([
			'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
			'status' => (string)$this->status,
			'scimType' => $this->scimType,
			'detail' => $this->getMessage(),
		], static fn ($v) => $v !== null);
	}
}
