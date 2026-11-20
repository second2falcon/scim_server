<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Tests;

use OCA\ScimServer\Controller\ScimController;
use OCA\ScimServer\Db\Store;
use OCA\ScimServer\Middleware\ScimMiddleware;
use OCA\ScimServer\Service\Settings;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MiddlewareTest extends TestCase {
	private function middleware(): ScimMiddleware {
		return new ScimMiddleware(
			$this->createMock(IRequest::class), $this->createMock(Store::class), $this->createMock(Settings::class),
			$this->createMock(IThrottler::class), $this->createMock(LoggerInterface::class),
		);
	}

	public function testABusyDatabaseIsATemporaryError(): void {
		$response = $this->middleware()->afterException($this->createMock(ScimController::class), 'createUser', new \OCP\DB\Exception('SQLSTATE[HY000]: General error: 5 database is locked'));
		$this->assertSame(503, $response->getStatus());
	}

	public function testOtherDatabaseErrorsAreNotHidden(): void {
		$this->expectException(\OCP\DB\Exception::class);
		$this->middleware()->afterException($this->createMock(ScimController::class), 'createUser', new \OCP\DB\Exception('syntax error'));
	}
}
