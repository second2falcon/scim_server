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
	private bool $groupManaged = false;

	protected function setUp(): void {
		$this->gm = $this->createMock(IGroupManager::class);
		$this->um = $this->createMock(IUserManager::class);
		$this->store = $this->createMock(Store::class);
		$this->settings = $this->createMock(Settings::class);
		$this->store->method('groupRow')->willReturnCallback(fn () => ['scim_id' => 'gsid', 'external_id' => null, 'created_at' => 1, 'managed' => $this->groupManaged]);
		$this->store->method('userRow')->willReturn(['scim_id' => 'usid', 'external_id' => null, 'created_at' => 1, 'pending_delete_at' => null, 'managed' => false]);
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

	public function testGroupsAndMembersNotCreatedThroughScimAreLeftAlone(): void {
		$local = $this->member('local');
		$g = $this->group('staff', [$local]);
		$this->settings->method('managedOnly')->willReturn(true);
		try { // the group itself was not created through SCIM
			$this->svc->delete('gsid');
			$this->fail('expected 404');
		} catch (ScimException $e) {
			$this->assertSame(404, $e->status);
		}
		// A SCIM group: a member that SCIM cannot see is neither listed nor removed by a full sync.
		$this->groupManaged = true;
		$g->expects($this->never())->method('removeUser');
		$this->assertSame([], $this->svc->replace('gsid', ['members' => []])['members']);
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

	public function testExcludedGroupIsAcknowledgedButNotCreated(): void {
		$this->gm->method('get')->willReturn(null);
		$this->gm->method('search')->willReturn([]);
		$this->settings->method('groupAllowed')->with('Everyone')->willReturn(false);
		$this->store->method('ignoredGroupRow')->willReturn(null);
		$this->gm->expects($this->never())->method('createGroup');
		$this->store->expects($this->once())->method('setGroupIgnored')->with('Everyone', true);
		$res = $this->svc->create(['displayName' => 'Everyone', 'members' => [['value' => 'usid']]]);
		$this->assertSame('gsid', $res['id']);
		$this->assertSame([], $res['members']);
	}

	public function testExcludedGroupAcceptsLaterRequests(): void {
		$this->store->method('gidByScimId')->with('gsid')->willReturn('Everyone');
		$this->store->method('ignoredGroupRow')->willReturn(['scim_id' => 'gsid', 'external_id' => null, 'created_at' => 1]);
		$this->settings->method('groupAllowed')->willReturn(false);
		$this->gm->expects($this->never())->method('createGroup');
		$this->assertSame([], $this->svc->get('gsid')['members']);
		$this->assertSame([], $this->svc->patch('gsid', ['Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => 'usid']]]]])['members']);
		$this->assertSame([], $this->svc->replace('gsid', ['displayName' => 'Everyone'])['members']);
		$this->store->expects($this->once())->method('deleteGroupRow')->with('Everyone');
		$this->svc->delete('gsid');
	}

	public function testRenamedToAllowedNameCreatesGroupKeepingScimId(): void {
		$this->store->method('gidByScimId')->with('gsid')->willReturn('Everyone');
		$this->store->method('ignoredGroupRow')->willReturn(['scim_id' => 'gsid', 'external_id' => null, 'created_at' => 1]);
		$this->settings->method('groupAllowed')->with('nextcloud-staff')->willReturn(true);
		$g = $this->createMock(IGroup::class);
		$g->method('getGID')->willReturn('nextcloud-staff');
		$g->method('getDisplayName')->willReturn('nextcloud-staff');
		$g->method('getUsers')->willReturn([]);
		$this->gm->expects($this->once())->method('createGroup')->with('nextcloud-staff')->willReturn($g);
		$this->store->expects($this->once())->method('moveGroupRow')->with('Everyone', 'nextcloud-staff');
		$res = $this->svc->replace('gsid', ['displayName' => 'nextcloud-staff']);
		$this->assertSame('gsid', $res['id']);
	}

	public function testUnknownMemberFailsBeforeAnythingIsCreated(): void {
		$this->gm->method('get')->willReturn(null);
		$this->gm->method('search')->willReturn([]);
		$this->store->method('ignoredGroupRow')->willReturn(null);
		$this->store->method('uidByScimId')->willReturn(null);
		$this->settings->method('groupAllowed')->willReturn(true);
		$this->gm->expects($this->never())->method('createGroup');
		try {
			$this->svc->create(['displayName' => 'staff', 'members' => [['value' => 'ghost']]]);
			$this->fail('expected 400');
		} catch (ScimException $e) {
			$this->assertSame(400, $e->status);
			$this->assertStringContainsString('created through SCIM', $e->getMessage());
		}
	}

	public function testPrefixIsAddedForNextcloudAndHiddenFromTheIdp(): void {
		$settings = $this->createMock(Settings::class);
		$settings->method('groupPrefix')->willReturn('SAML_');
		$settings->method('groupAllowed')->with('DATA-MGMT')->willReturn(true); // the filter sees the plain name
		$svc = new GroupService($this->gm, $this->um, $this->store, $settings, $this->createMock(IURLGenerator::class));
		$this->gm->method('get')->willReturn(null);
		$this->gm->method('search')->willReturn([]);
		$this->store->method('ignoredGroupRow')->willReturn(null);
		$g = $this->createMock(IGroup::class);
		$g->method('getGID')->willReturn('SAML_DATA-MGMT');
		$g->method('getDisplayName')->willReturn('SAML_DATA-MGMT');
		$g->method('getUsers')->willReturn([]);
		$this->gm->expects($this->once())->method('createGroup')->with('SAML_DATA-MGMT')->willReturn($g);
		$this->assertSame('DATA-MGMT', $svc->create(['displayName' => 'DATA-MGMT'])['displayName']);
	}

	public function testPrefixedGroupIsFoundByItsPlainName(): void {
		$settings = $this->createMock(Settings::class);
		$settings->method('groupPrefix')->willReturn('SAML_');
		$svc = new GroupService($this->gm, $this->um, $this->store, $settings, $this->createMock(IURLGenerator::class));
		$g = $this->createMock(IGroup::class);
		$g->method('getGID')->willReturn('SAML_DATA-MGMT');
		$g->method('getDisplayName')->willReturn('SAML_DATA-MGMT');
		$g->method('getUsers')->willReturn([]);
		$this->gm->expects($this->once())->method('get')->with('SAML_DATA-MGMT')->willReturn($g);
		$res = $svc->list('displayName eq "DATA-MGMT"', 1, 100);
		$this->assertSame('DATA-MGMT', $res['Resources'][0]['displayName']);
	}

	/** A service with "managed only" on, where existing groups matching the adopt regex may be managed, and a group that SCIM did not create. */
	private function adoptedSetup(bool $adoptable): array {
		$settings = $this->createMock(Settings::class);
		$settings->method('managedOnly')->willReturn(true);
		$settings->method('groupAdoptable')->willReturn($adoptable);
		$store = $this->createMock(Store::class);
		$store->method('gidByScimId')->with('gsid')->willReturn('SAML_DATA');
		$store->method('groupRow')->willReturn(['scim_id' => 'gsid', 'external_id' => null, 'created_at' => 1, 'managed' => false]);
		$store->method('ignoredGroupRow')->willReturn(null);
		$store->method('uidByScimId')->willReturnCallback(fn ($id) => $id === 'usid' ? 'scimuser' : null);
		// the SCIM-created account is managed; the SAML account is not
		$store->method('userRow')->willReturnCallback(fn ($uid) => ['scim_id' => 'x' . $uid, 'external_id' => null, 'created_at' => 1, 'pending_delete_at' => null, 'managed' => $uid === 'scimuser']);
		$scim = $this->member('scimuser');
		$saml = $this->member('samluser');
		$g = $this->createMock(IGroup::class);
		$g->method('getGID')->willReturn('SAML_DATA');
		$g->method('getDisplayName')->willReturn('SAML_DATA');
		$g->method('getUsers')->willReturn([$scim, $saml]);
		$this->gm->method('get')->with('SAML_DATA')->willReturn($g);
		$this->um->method('get')->willReturn($scim);
		return [new GroupService($this->gm, $this->um, $store, $settings, $this->createMock(IURLGenerator::class)), $g, $scim, $saml, $store];
	}

	public function testExistingGroupNotOnTheAdoptListStaysInvisible(): void {
		[$svc] = $this->adoptedSetup(false);
		try {
			$svc->get('gsid');
			$this->fail('expected 404');
		} catch (ScimException $e) {
			$this->assertSame(404, $e->status);
		}
	}

	public function testAdoptedGroupOnlyChangesScimMembersAndKeepsItsName(): void {
		[$svc, $g, $scim, $saml] = $this->adoptedSetup(true);
		$g->method('inGroup')->willReturn(true);
		$g->expects($this->never())->method('setDisplayName');
		$g->expects($this->once())->method('removeUser')->with($scim); // empty member list: only the SCIM account goes
		$res = $svc->replace('gsid', ['displayName' => 'Renamed', 'members' => []]);
		$this->assertCount(0, array_filter($res['members'], fn ($m) => $m['value'] === 'xsamluser')); // the SAML member is not even listed
	}

	public function testDeletingAnAdoptedGroupOnlyRemovesScimMembers(): void {
		[$svc, $g, $scim, , $store] = $this->adoptedSetup(true);
		$g->expects($this->never())->method('delete');
		$g->expects($this->once())->method('removeUser')->with($scim);
		$store->expects($this->once())->method('deleteGroupRow')->with('SAML_DATA');
		$svc->delete('gsid');
	}
}
