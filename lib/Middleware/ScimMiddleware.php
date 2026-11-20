<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Middleware;

use OCA\ScimServer\Controller\ScimController;
use OCA\ScimServer\Db\Store;
use OCA\ScimServer\Service\ScimException;
use OCA\ScimServer\Service\Settings;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use Psr\Log\LoggerInterface;

/** Bearer-token authentication for the SCIM endpoints, and SCIM-formatted errors. */
class ScimMiddleware extends Middleware {
	private const ACTION = 'scim_server';

	public function __construct(
		private IRequest $request,
		private Store $store,
		private Settings $settings,
		private IThrottler $throttler,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController($controller, $methodName): void {
		if (!$controller instanceof ScimController) {
			return;
		}
		if ($this->request->getServerProtocol() !== 'https' && !$this->settings->allowHttp()) {
			throw new ScimException(403, 'SCIM is only available over HTTPS. Behind a reverse proxy, set overwriteprotocol and trusted_proxies in config.php.');
		}
		$header = $this->request->getHeader('Authorization');
		$token = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $this->store->checkToken($m[1]) : null;
		if ($token === null) {
			// Only failures are slowed down, so a valid token is never delayed by someone else's guesses.
			$ip = $this->request->getRemoteAddress();
			try {
				$this->throttler->sleepDelayOrThrowOnMax($ip, self::ACTION);
			} catch (MaxDelayReached) {
				throw new ScimException(429, 'Too many failed attempts');
			}
			$this->throttler->registerAttempt(self::ACTION, $ip);
			$this->logger->warning('SCIM: missing or invalid bearer token from {ip}', ['app' => 'scim_server', 'ip' => $ip]);
			throw new ScimException(401, 'Missing or invalid bearer token');
		}
		$controller->markAuthenticated(); // the controller refuses to do anything without this
		if ($this->request->getMethod() !== 'GET') {
			$this->logger->info('SCIM: {method} {path} with token #{id} ({label})', [
				'app' => 'scim_server', 'method' => $this->request->getMethod(), 'path' => (string)$this->request->getPathInfo(),
				'id' => $token['id'], 'label' => $token['label'],
			]);
		}
	}

	public function afterException($controller, $methodName, \Exception $exception): Response {
		if ($controller instanceof ScimController && $exception instanceof \OCP\DB\Exception && $this->isBusy($exception)) {
			// A busy database (SQLite allows one writer, or a deadlock) is temporary: authentik and others retry on 503.
			$exception = new ScimException(503, 'The database is busy, try again');
		}
		if (!$controller instanceof ScimController || !$exception instanceof ScimException) {
			throw $exception;
		}
		$response = new JSONResponse($exception->toArray(), $exception->status);
		$response->addHeader('Content-Type', 'application/scim+json');
		if ($exception->status === 401) {
			$response->addHeader('WWW-Authenticate', 'Bearer');
		}
		if ($exception->status === 503) {
			$response->addHeader('Retry-After', '2');
		}
		return $response;
	}

	private function isBusy(\OCP\DB\Exception $e): bool {
		return in_array($e->getReason(), [\OCP\DB\Exception::REASON_DEADLOCK, \OCP\DB\Exception::REASON_LOCK_WAIT_TIMEOUT], true)
			|| str_contains($e->getMessage(), 'database is locked');
	}
}
