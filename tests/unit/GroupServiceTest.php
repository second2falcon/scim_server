<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Tests;

use OCA\ScimServer\Db\Store;
use OCA\ScimServer\Service\GroupService;
use OCA\ScimServer\Service\ScimException;
use OCA\ScimServer\Service\Settings;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GroupServiceTest extends TestCase {
	private IGroupManager&MockObject $gm;
	private IUserManager&MockObject $um;
	private Store&MockObject $store;
	private Settings&MockObject $settings;
	private GroupService $svc;

	protected function setUp(): void {
		$this->gm = $this->createMock(IGroupManager::class);
		$this->um = $this->createMock(IUserManager::class);
		$this->store = $this->createMock(Store::class);
		$this->settings = $this->createMock(Settings::class);
		$this->store->method('groupRow')->willReturn(['scim_id' => 'gsid', 'external_id' => null, 'created_at' => 1]);
		$this->store->method('userRow')->willReturn(['scim_id' => 'usid', 'external_id' => null, 'created_at' => 1, 'pending_delete_at' => null]);
		$this->svc = new GroupService($this->gm, $this->um, $this->store, $this->settings, $this->createMock(IURLGenerator::class));
	}

	private function group(string $gid, array $members = []): IGroup&MockObject {
		$g = $this->createMock(IGroup::class);
		$g->method('getGID')->willReturn($gid);
		$g->method('getDisplayName')->willReturn($gid);
		$g->method('getUsers')->willReturn($members);
		$this->store->method('gidByScimId')->with('gsid')->willReturn($gid);
		$this->gm->method('get')->with($gid)->willReturn($g);
		return $g;
	}

	public function testProtectedGroupsCannotBeChanged(): void {
		$g = $this->group('finance');
		$this->settings->method('protectedGroups')->willReturn(['finance']);
		$g->expects($this->never())->method('delete');
		try {
			$this->svc->delete('gsid');
			$this->fail('expected 403');
		} catch (ScimException $e) {
			$this->assertSame(403, $e->status);
		}
	}

	private function member(string $uid): IUser&MockObject {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		return $u;
	}

	private function knownUser(string $scimId, IUser $u): void {
		$this->store->method('uidByScimId')->willReturnCallback(fn ($id) => $id === $scimId ? $u->getUID() : null);
		$this->um->method('get')->willReturn($u);
	}

	public function testPatchAddMember(): void {
		$g = $this->group('staff');
		$this->knownUser('usid', $ann = $this->member('ann'));
		$g->method('inGroup')->willReturn(false);
		$g->expects($this->once())->method('addUser')->with($ann);
		$this->svc->patch('gsid', ['Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => 'usid']]]]]);
	}

	public function testPatchRemoveMemberWithFilterPath(): void {
		$g = $this->group('staff');
		$this->knownUser('usid', $ann = $this->member('ann'));
		$g->method('inGroup')->willReturn(true);
		$g->expects($this->once())->method('removeUser')->with($ann);
		$this->svc->patch('gsid', ['Operations' => [['op' => 'remove', 'path' => 'members[value eq "usid"]']]]);
	}

	public function testPutReplacesMembership(): void {
		$old = $this->member('old');
		$g = $this->group('staff', [$old]);
		$this->knownUser('usid', $ann = $this->member('ann'));
		$g->method('inGroup')->willReturn(false);
		$g->expects($this->once())->method('removeUser')->with($old);
		$g->expects($this->once())->method('addUser')->with($ann);
		$this->svc->replace('gsid', ['displayName' => 'staff', 'members' => [['value' => 'usid']]]);
	}

	public function testUnknownMemberIsRejected(): void {
		$this->group('staff');
		$this->store->method('uidByScimId')->willReturn(null);
		try {
			$this->svc->patch('gsid', ['Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => 'ghost']]]]]);
			$this->fail('expected 400');
		} catch (ScimException $e) {
			$this->assertSame(400, $e->status);
		}
	}

	public function testRenameKeepsGid(): void {
		$g = $this->group('staff');
		$g->expects($this->once())->method('setDisplayName')->with('Staff members');
		$this->svc->replace('gsid', ['displayName' => 'Staff members']);
	}

	public function testCreateConflictAndAdminGroupProtected(): void {
		$this->gm->method('get')->willReturn($this->createMock(IGroup::class));
		try {
			$this->svc->create(['displayName' => 'staff']);
			$this->fail('expected 409');
		} catch (ScimException $e) {
			$this->assertSame(409, $e->status);
		}
	}

	public function testAdminGroupProtected(): void {
		$this->group('admin');
		$this->settings->method('protectAdmins')->willReturn(true);
		try {
			$this->svc->delete('gsid');
			$this->fail('expected 403');
		} catch (ScimException $e) {
			$this->assertSame(403, $e->status);
		}
	}

	public function testDeleteRemovesGroupOnly(): void {
		$g = $this->group('staff');
		$g->expects($this->once())->method('delete');
		$this->store->expects($this->once())->method('deleteGroupRow')->with('staff');
		$this->svc->delete('gsid');
	}
}
