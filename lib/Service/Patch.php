<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Service;

/** Pure translation of RFC 7644 PATCH operations (as sent by authentik, Okta and Entra) into plain changes. */
class Patch {
	/**
	 * @return list<array{op:string,attr:string,sub:?string,filter:?array{0:string,1:string},value:mixed}>
	 */
	public static function parse(array $body): array {
		$ops = $body['Operations'] ?? $body['operations'] ?? null;
		if (!is_array($ops)) {
			throw new ScimException(400, 'Operations missing', 'invalidSyntax');
		}
		$out = [];
		foreach ($ops as $op) {
			// Entra sends "Add"/"Replace"/"Remove".
			$name = is_array($op) && is_string($op['op'] ?? null) ? strtolower($op['op']) : '';
			if (!in_array($name, ['add', 'replace', 'remove'], true)) {
				throw new ScimException(400, 'Unknown operation', 'invalidSyntax');
			}
			$path = $op['path'] ?? null;
			$value = $op['value'] ?? null;
			if ($path === null || $path === '') {
				if (!is_array($value)) {
					throw new ScimException(400, 'Operation without path needs an object value', 'invalidSyntax');
				}
				foreach ($value as $attr => $v) {
					$out[] = self::entry($name, (string)$attr, $v);
				}
				continue;
			}
			$out[] = self::entry($name, (string)$path, $value);
		}
		return $out;
	}

	private static function entry(string $op, string $path, mixed $value): array {
		if (str_starts_with($path, 'urn:')) {
			$path = substr($path, (int)strrpos($path, ':') + 1);
		}
		// attr, attr.sub, attr[filter], attr[filter].sub
		if (!preg_match('/^([A-Za-z]+)(?:\[(.*)\])?(?:\.([A-Za-z]+))?$/', $path, $m)) {
			throw new ScimException(400, "Unsupported path $path", 'invalidPath');
		}
		return [
			'op' => $op,
			'attr' => strtolower($m[1]),
			'sub' => isset($m[3]) ? strtolower($m[3]) : null,
			'filter' => ($m[2] ?? '') !== '' ? Filter::parse($m[2]) : null,
			'value' => $value,
		];
	}

	/**
	 * Reduce user operations to the attribute shape of a SCIM User body (only changed keys).
	 * @return array<string,mixed>
	 */
	public static function userAttrs(array $body): array {
		$attrs = [];
		foreach (self::parse($body) as $o) {
			$remove = $o['op'] === 'remove';
			switch ($o['attr']) {
				case 'active':
					$attrs['active'] = $o['value'];
					break;
				case 'displayname':
					$attrs['displayName'] = $remove ? null : $o['value'];
					break;
				case 'externalid':
					$attrs['externalId'] = $remove ? null : $o['value'];
					break;
				case 'username':
					$attrs['userName'] = $o['value'];
					break;
				case 'name':
					if ($o['sub'] !== null) {
						$attrs['name'][self::nameKey($o['sub'])] = $remove ? null : $o['value'];
					} elseif (is_array($o['value'])) {
						$attrs['name'] = ($attrs['name'] ?? []) + $o['value'];
					}
					break;
				case 'emails':
					if ($remove) {
						$attrs['emails'] = [];
					} elseif ($o['sub'] === 'value') { // emails[type eq "work"].value
						$attrs['emails'] = [['value' => $o['value'], 'primary' => true]];
					} elseif (is_array($o['value'])) {
						$attrs['emails'] = $o['value'];
					}
					break;
				default:
					break; // ponytail: unknown attributes are ignored, as RFC 7644 allows for unsupported ones
			}
		}
		return $attrs;
	}

	private static function nameKey(string $sub): string {
		return ['givenname' => 'givenName', 'familyname' => 'familyName', 'formatted' => 'formatted'][$sub] ?? $sub;
	}

	/**
	 * Reduce group operations to an ordered member change list plus an optional new displayName.
	 * @return array{displayName:?string,members:list<array{0:string,1:list<string>}>}
	 */
	public static function groupChanges(array $body): array {
		$res = ['displayName' => null, 'members' => []];
		foreach (self::parse($body) as $o) {
			if ($o['attr'] === 'displayname' && $o['op'] !== 'remove' && is_string($o['value'])) {
				$res['displayName'] = $o['value'];
			} elseif ($o['attr'] === 'members') {
				$ids = $o['filter'] !== null && $o['filter'][0] === 'value'
					? [$o['filter'][1]]
					: self::memberIds($o['value']);
				$op = $o['op'];
				if ($op === 'remove' && $o['filter'] === null && $o['value'] === null) {
					$op = 'replace'; // "remove members" without a target clears the group
				}
				$res['members'][] = [$op, $ids];
			}
		}
		return $res;
	}

	/** @return list<string> */
	public static function memberIds(mixed $members): array {
		$ids = [];
		foreach (is_array($members) ? $members : [] as $m) {
			if (is_array($m) && is_string($m['value'] ?? null)) {
				$ids[] = $m['value'];
			}
		}
		return $ids;
	}
}
