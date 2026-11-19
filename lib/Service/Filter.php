<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Service;

/**
 * Parses the only filter form IdPs use for lookups: `attr eq "value"`. The attribute may be a sub-attribute with
 * a selector, as Entra ID sends when its matching attribute is an email: `emails[type eq "work"].value`
 * (returned as `emails.value`).
 */
class Filter {
	/** @return array{0:string,1:string}|null [lower-case attribute, value] */
	public static function parse(?string $filter): ?array {
		if ($filter === null || trim($filter) === '') {
			return null;
		}
		// ponytail: only `eq`; add `and`/`co`/`sw` if a client needs them.
		if (!preg_match('/^\s*([A-Za-z][A-Za-z0-9.:_-]*(?:\[[^\]]*\])?(?:\.[A-Za-z]+)?)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"\s*$/i', $filter, $m)) {
			throw new ScimException(400, 'Unsupported filter, only: attribute eq "value"', 'invalidFilter');
		}
		$value = json_decode('"' . $m[2] . '"'); // SCIM filter values are JSON strings
		if (!is_string($value)) {
			throw new ScimException(400, 'Invalid string in filter', 'invalidFilter');
		}
		$attr = preg_replace('/\[[^\]]*\]/', '', $m[1]);
		if (str_starts_with($attr, 'urn:')) {
			$attr = substr($attr, (int)strrpos($attr, ':') + 1); // urn:...:User:userName
		}
		return [strtolower($attr), $value];
	}
}
