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
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/** Bearer-token authentication for the SCIM endpoints, and SCIM-formatted errors. */
class ScimMiddleware extends Middleware {
	public function __construct(private IRequest $request, private Store $store) {
	}

	public function beforeController($controller, $methodName): void {
		if (!$controller instanceof ScimController) {
			return;
		}
		$header = $this->request->getHeader('Authorization');
		if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m) || !$this->store->checkToken($m[1])) {
			throw new ScimException(401, 'Missing or invalid bearer token');
		}
	}

	public function afterException($controller, $methodName, \Exception $exception): Response {
		if (!$controller instanceof ScimController || !$exception instanceof ScimException) {
			throw $exception;
		}
		$response = new JSONResponse($exception->toArray(), $exception->status);
		$response->addHeader('Content-Type', 'application/scim+json');
		if ($exception->status === 401) {
			$response->addHeader('WWW-Authenticate', 'Bearer');
		}
		return $response;
	}
}
