<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Tests;

use OCA\ScimServer\Db\Store;
use OCA\ScimServer\Service\ScimException;
use OCA\ScimServer\Service\Settings;
use OCA\ScimServer\Service\UserService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserServiceTest extends TestCase {
	private IUserManager&MockObject $um;
	private IGroupManager&MockObject $gm;
	private Store&MockObject $store;
	private Settings&MockObject $settings;
	private UserService $svc;

	protected function setUp(): void {
		$this->um = $this->createMock(IUserManager::class);
		$this->gm = $this->createMock(IGroupManager::class);
		$this->store = $this->createMock(Store::class);
		$this->settings = $this->createMock(Settings::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('r');
		$this->store->method('userRow')->willReturn(['scim_id' => 'sid', 'external_id' => null, 'created_at' => 1, 'pending_delete_at' => null]);
		$this->svc = new UserService($this->um, $this->gm, $this->store, $this->settings, $time, $random, $this->createMock(IURLGenerator::class));
	}

	private function user(string $uid = 'ann', bool $enabled = true): IUser&MockObject {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('isEnabled')->willReturn($enabled);
		$this->store->method('uidByScimId')->with('sid')->willReturn($uid);
		$this->um->method('get')->with($uid)->willReturn($u);
		return $u;
	}

	public function testCreate(): void {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn('ann');
		$this->um->method('userExists')->willReturn(false);
		$this->um->expects($this->once())->method('createUser')->with('ann', 'rAa1!')->willReturn($u);
		$u->expects($this->once())->method('setDisplayName')->with('Ann B');
		$u->expects($this->once())->method('setSystemEMailAddress')->with('a@example.com');
		$this->store->expects($this->once())->method('setUserExternalId')->with('ann', 'ext1');
		$res = $this->svc->create([
			'userName' => 'ann', 'externalId' => 'ext1', 'name' => ['givenName' => 'Ann', 'familyName' => 'B'],
			'emails' => [['value' => 'a@example.com', 'primary' => true]],
		]);
		$this->assertSame('sid', $res['id']);
		$this->assertSame('ann', $res['userName']);
	}

	public function testCreateConflict(): void {
		$this->um->method('userExists')->willReturn(true);
		$this->expectException(ScimException::class);
		$this->expectExceptionCode(0);
		try {
			$this->svc->create(['userName' => 'ann']);
		} catch (ScimException $e) {
			$this->assertSame(409, $e->status);
			throw $e;
		}
	}

	public function testDeactivateDisablesAndKeepsData(): void {
		$u = $this->user();
		$this->gm->method('isAdmin')->willReturn(false);
		$u->expects($this->once())->method('setEnabled')->with(false);
		$u->expects($this->never())->method('delete');
		$this->svc->patch('sid', ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => 'False']]]);
	}

	public function testReactivateClearsPendingDelete(): void {
		$u = $this->user('ann', false);
		$u->expects($this->once())->method('setEnabled')->with(true);
		$this->store->expects($this->once())->method('setPendingDelete')->with('ann', null);
		$this->svc->replace('sid', ['userName' => 'ann', 'active' => true]);
	}

	public function testDeleteDisablePurgeSchedulesDeletion(): void {
		$u = $this->user();
		$this->gm->method('isAdmin')->willReturn(false);
		$this->settings->method('deleteMode')->willReturn('disable_purge');
		$this->settings->method('purgeDays')->willReturn(7);
		$u->expects($this->once())->method('setEnabled')->with(false);
		$u->expects($this->never())->method('delete');
		$this->store->expects($this->once())->method('setPendingDelete')->with('ann', 1000 + 7 * 86400);
		$this->svc->delete('sid');
	}

	public function testDeleteDisableOnly(): void {
		$u = $this->user();
		$this->gm->method('isAdmin')->willReturn(false);
		$this->settings->method('deleteMode')->willReturn('disable');
		$u->expects($this->once())->method('setEnabled')->with(false);
		$u->expects($this->never())->method('delete');
		$this->store->expects($this->never())->method('setPendingDelete');
		$this->svc->delete('sid');
	}

	public function testDeleteImmediately(): void {
		$u = $this->user();
		$this->gm->method('isAdmin')->willReturn(false);
		$this->settings->method('deleteMode')->willReturn('delete');
		$u->expects($this->once())->method('delete');
		$this->store->expects($this->once())->method('deleteUserRow')->with('ann');
		$this->svc->delete('sid');
	}

	public function testAdminsAreProtected(): void {
		$u = $this->user('root');
		$this->gm->method('isAdmin')->with('root')->willReturn(true);
		$this->settings->method('protectAdmins')->willReturn(true);
		$u->expects($this->never())->method('setEnabled');
		try {
			$this->svc->delete('sid');
			$this->fail('expected 403');
		} catch (ScimException $e) {
			$this->assertSame(403, $e->status);
		}
	}

	public function testUnknownUser(): void {
		$this->store->method('uidByScimId')->willReturn(null);
		try {
			$this->svc->get('nope');
			$this->fail('expected 404');
		} catch (ScimException $e) {
			$this->assertSame(404, $e->status);
		}
	}

	public function testUserNameCannotChange(): void {
		$this->user();
		$this->expectException(ScimException::class);
		$this->svc->replace('sid', ['userName' => 'other']);
	}

	public function testFilterByUserNameReturnsListResponse(): void {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn('ann');
		$this->um->method('get')->with('ann')->willReturn($u);
		$res = $this->svc->list('userName eq "ann"', 1, 100);
		$this->assertSame(1, $res['totalResults']);
		$this->assertCount(1, $res['Resources']);
		$this->um->method('get')->with('zed')->willReturn(null);
	}

	public function testPurgeOnlyDisabledAccountsPastDeadline(): void {
		$this->settings->method('deleteMode')->willReturn('disable_purge');
		$this->store->method('duePurges')->with(1000)->willReturn(['off', 'back']);
		$off = $this->createMock(IUser::class);
		$off->method('isEnabled')->willReturn(false);
		$back = $this->createMock(IUser::class);
		$back->method('isEnabled')->willReturn(true);
		$this->um->method('get')->willReturnMap([['off', $off], ['back', $back]]);
		$off->expects($this->once())->method('delete');
		$back->expects($this->never())->method('delete');
		$this->assertSame(1, $this->svc->purgeDue());
	}

	public function testNoPurgeWhenModeChanged(): void {
		$this->settings->method('deleteMode')->willReturn('disable');
		$this->store->expects($this->never())->method('duePurges');
		$this->assertSame(0, $this->svc->purgeDue());
	}
}
