<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Controller;

use OCA\ScimServer\Service\GroupService;
use OCA\ScimServer\Service\ScimException;
use OCA\ScimServer\Service\UserService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * SCIM 2.0 (RFC 7643/7644) endpoints. Every action is #[PublicPage] #[NoCSRFRequired] (class-level attributes are not honoured by Nextcloud): ScimMiddleware requires a
 * bearer token instead, and turns ScimExceptions into SCIM errors.
 */
class ScimController extends Controller {
	public function __construct(string $appName, IRequest $request, private UserService $users, private GroupService $groups) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function serviceProviderConfig(): JSONResponse {
		return $this->json([
			'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
			'patch' => ['supported' => true],
			'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
			'filter' => ['supported' => true, 'maxResults' => UserService::MAX],
			'changePassword' => ['supported' => false],
			'sort' => ['supported' => false],
			'etag' => ['supported' => false],
			'authenticationSchemes' => [[
				'type' => 'oauthbearertoken',
				'name' => 'Bearer token',
				'description' => 'Token created in the admin settings of the SCIM Server app',
			]],
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function listUsers(?string $filter = null, int $startIndex = 1, int $count = 100): JSONResponse {
		return $this->json($this->users->list($filter, $startIndex, $count));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function getUser(string $id): JSONResponse {
		return $this->json($this->users->get($id));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function createUser(): JSONResponse {
		return $this->created($this->users->create($this->body()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function replaceUser(string $id): JSONResponse {
		return $this->json($this->users->replace($id, $this->body()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function patchUser(string $id): JSONResponse {
		return $this->json($this->users->patch($id, $this->body()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteUser(string $id): Response {
		$this->users->delete($id);
		return $this->noContent();
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function listGroups(?string $filter = null, int $startIndex = 1, int $count = 100): JSONResponse {
		return $this->json($this->groups->list($filter, $startIndex, $count));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function getGroup(string $id): JSONResponse {
		return $this->json($this->groups->get($id));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function createGroup(): JSONResponse {
		return $this->created($this->groups->create($this->body()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function replaceGroup(string $id): JSONResponse {
		return $this->json($this->groups->replace($id, $this->body()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function patchGroup(string $id): JSONResponse {
		return $this->json($this->groups->patch($id, $this->body()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteGroup(string $id): Response {
		$this->groups->delete($id);
		return $this->noContent();
	}

	private function json(array $data, int $status = 200): JSONResponse {
		$response = new JSONResponse($data, $status);
		$response->addHeader('Content-Type', 'application/scim+json');
		return $response;
	}

	private function created(array $resource): JSONResponse {
		$response = $this->json($resource, 201);
		$response->addHeader('Location', $resource['meta']['location'] ?? '');
		return $response;
	}

	private function noContent(): Response {
		$response = new Response();
		$response->setStatus(204);
		return $response;
	}

	/** The body is read raw: Nextcloud only parses application/json, SCIM clients send application/scim+json. */
	private function body(): array {
		$data = json_decode((string)file_get_contents('php://input'), true);
		if (!is_array($data)) {
			throw new ScimException(400, 'Request body is not valid JSON', 'invalidSyntax');
		}
		return $data;
	}
}
