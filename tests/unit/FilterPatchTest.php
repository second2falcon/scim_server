<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Tests;

use OCA\ScimServer\Service\Filter;
use OCA\ScimServer\Service\Patch;
use OCA\ScimServer\Service\ScimException;
use PHPUnit\Framework\TestCase;

class FilterPatchTest extends TestCase {
	public function testFilter(): void {
		$this->assertNull(Filter::parse(null));
		$this->assertSame(['username', 'a "b"'], Filter::parse('userName eq "a \"b\""'));
		$this->assertSame(['externalid', 'x'], Filter::parse(' externalId EQ "x" '));
		$this->assertSame(['username', 'é\\x41'], Filter::parse('userName eq "\u00e9\\\\x41"')); // JSON escapes, not C ones
	}

	public function testInvalidEscapeInFilter(): void {
		$this->expectException(ScimException::class);
		Filter::parse('userName eq "\\x41"');
	}

	public function testRequestSizeLimits(): void {
		foreach ([
			fn () => Patch::parse(['Operations' => array_fill(0, Patch::MAX + 1, ['op' => 'add', 'path' => 'displayName', 'value' => 'x'])]),
			fn () => Patch::memberIds(array_fill(0, Patch::MAX + 1, ['value' => 'u'])),
		] as $call) {
			try {
				$call();
				$this->fail('expected 413');
			} catch (ScimException $e) {
				$this->assertSame(413, $e->status);
			}
		}
	}

	public function testUnsupportedFilter(): void {
		$this->expectException(ScimException::class);
		Filter::parse('userName co "x"');
	}

	public function testUserPatchOktaStyle(): void {
		$attrs = Patch::userAttrs(['Operations' => [
			['op' => 'replace', 'path' => 'active', 'value' => false],
			['op' => 'replace', 'path' => 'name.givenName', 'value' => 'Ann'],
		]]);
		$this->assertSame(['active' => false, 'name' => ['givenName' => 'Ann']], $attrs);
	}

	public function testUserPatchEntraStyle(): void {
		$attrs = Patch::userAttrs(['Operations' => [
			['op' => 'Replace', 'value' => ['active' => 'False', 'displayName' => 'Ann B']],
			['op' => 'Add', 'path' => 'emails[type eq "work"].value', 'value' => 'a@example.com'],
			['op' => 'Remove', 'path' => 'externalId'],
		]]);
		$this->assertSame('False', $attrs['active']);
		$this->assertSame('Ann B', $attrs['displayName']);
		$this->assertSame([['value' => 'a@example.com', 'primary' => true]], $attrs['emails']);
		$this->assertNull($attrs['externalId']);
	}

	public function testUrnPrefixedPath(): void {
		$attrs = Patch::userAttrs(['Operations' => [
			['op' => 'replace', 'path' => 'urn:ietf:params:scim:schemas:core:2.0:User:displayName', 'value' => 'X'],
		]]);
		$this->assertSame(['displayName' => 'X'], $attrs);
	}

	public function testGroupPatch(): void {
		$c = Patch::groupChanges(['Operations' => [
			['op' => 'add', 'path' => 'members', 'value' => [['value' => 'u1'], ['value' => 'u2']]],
			['op' => 'remove', 'path' => 'members[value eq "u1"]'],
			['op' => 'remove', 'path' => 'members', 'value' => [['value' => 'u2']]],
			['op' => 'remove', 'path' => 'members'],
			['op' => 'replace', 'path' => 'displayName', 'value' => 'Staff'],
		]]);
		$this->assertSame('Staff', $c['displayName']);
		$this->assertSame([['add', ['u1', 'u2']], ['remove', ['u1']], ['remove', ['u2']], ['replace', []]], $c['members']);
	}

	public function testInvalidOperations(): void {
		$this->expectException(ScimException::class);
		Patch::parse(['Operations' => [['op' => 'move', 'path' => 'x']]]);
	}
}
