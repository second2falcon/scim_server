<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Tests;

use OCA\ScimServer\Controller\ScimController;
use OCA\ScimServer\Service\GroupService;
use OCA\ScimServer\Service\ScimException;
use OCA\ScimServer\Service\UserService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class ScimControllerTest extends TestCase {
	/** Every action must refuse to run when the middleware did not authenticate the request. */
	public function testNoActionRunsWithoutTheMiddleware(): void {
		$users = $this->createMock(UserService::class);
		$groups = $this->createMock(GroupService::class);
		$users->expects($this->never())->method($this->anything());
		$groups->expects($this->never())->method($this->anything());
		$controller = new ScimController('scim_server', $this->createMock(IRequest::class), $users, $groups);
		$actions = array_filter(
			(new \ReflectionClass(ScimController::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
			static fn (\ReflectionMethod $m) => $m->class === ScimController::class && !in_array($m->name, ['__construct', 'markAuthenticated'], true),
		);
		$this->assertCount(13, $actions); // update when adding an endpoint
		foreach ($actions as $action) {
			try {
				$action->invokeArgs($controller, array_fill(0, $action->getNumberOfRequiredParameters(), 'x'));
				$this->fail($action->name . ' ran without authentication');
			} catch (ScimException $e) {
				$this->assertSame(401, $e->status, $action->name);
			}
		}
	}

	public function testActionsRunOnceAuthenticated(): void {
		$users = $this->createMock(UserService::class);
		$users->expects($this->once())->method('get')->with('sid')->willReturn(['id' => 'sid']);
		$controller = new ScimController('scim_server', $this->createMock(IRequest::class), $users, $this->createMock(GroupService::class));
		$controller->markAuthenticated();
		$this->assertSame(['id' => 'sid'], $controller->getUser('sid')->getData());
	}
}
