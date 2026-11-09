<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Service;

use OCA\ScimServer\Db\Store;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

class UserService {
	public const SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';
	public const MAX = 200;

	public function __construct(
		private IUserManager $users,
		private IGroupManager $groups,
		private Store $store,
		private Settings $settings,
		private ITimeFactory $time,
		private ISecureRandom $random,
		private IURLGenerator $urls,
	) {
	}

	public function toScim(IUser $user): array {
		$uid = $user->getUID();
		$row = $this->store->userRow($uid);
		$res = [
			'schemas' => [self::SCHEMA],
			'id' => $row['scim_id'],
			'userName' => $uid,
			'displayName' => $user->getDisplayName(),
			'active' => $user->isEnabled(),
			'meta' => [
				'resourceType' => 'User',
				'created' => gmdate('c', $row['created_at']),
				'location' => $this->urls->linkToRouteAbsolute('scim_server.scim.getUser', ['id' => $row['scim_id']]),
			],
		];
		if ($row['external_id'] !== null) {
			$res['externalId'] = $row['external_id'];
		}
		$mail = $user->getSystemEMailAddress();
		if ($mail) {
			$res['emails'] = [['value' => $mail, 'primary' => true]];
		}
		return $res;
	}

	public function get(string $scimId): array {
		return $this->toScim($this->find($scimId));
	}

	public function list(?string $filter, int $startIndex, int $count): array {
		$startIndex = max(1, $startIndex);
		$count = max(0, min($count, self::MAX));
		$f = Filter::parse($filter);
		if ($f !== null) {
			$user = match ($f[0]) {
				'username' => $this->users->get($f[1]),
				'externalid' => ($uid = $this->store->uidByExternalId($f[1])) !== null ? $this->users->get($uid) : null,
				default => throw new ScimException(400, "Cannot filter on $f[0]", 'invalidFilter'),
			};
			$found = $user === null ? [] : [$user];
			$total = count($found);
			$page = $startIndex === 1 ? $found : [];
		} else {
			$total = array_sum($this->users->countUsers());
			$page = $count === 0 ? [] : array_values($this->users->search('', $count, $startIndex - 1));
		}
		return [
			'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
			'totalResults' => $total,
			'startIndex' => $startIndex,
			'itemsPerPage' => count($page),
			'Resources' => array_map(fn (IUser $u) => $this->toScim($u), array_slice($page, 0, $count ?: self::MAX)),
		];
	}

	public function create(array $body): array {
		$uid = $body['userName'] ?? null;
		if (!is_string($uid) || trim($uid) === '') {
			throw new ScimException(400, 'userName is required', 'invalidValue');
		}
		if ($this->users->userExists($uid)) {
			throw new ScimException(409, "User $uid already exists", 'uniqueness');
		}
		$this->assertExternalIdFree($body['externalId'] ?? null, null);
		// Login happens through the IdP; the password only has to satisfy any password policy.
		$password = $this->random->generate(32) . 'Aa1!';
		try {
			$user = $this->users->createUser($uid, $password);
		} catch (\InvalidArgumentException $e) {
			throw new ScimException(400, $e->getMessage(), 'invalidValue');
		}
		if (!$user instanceof IUser) {
			throw new ScimException(400, "Cannot create user $uid", 'invalidValue');
		}
		$this->apply($user, $body, true);
		return $this->toScim($user);
	}

	/** PUT: attributes that are present replace the current ones. */
	public function replace(string $scimId, array $body): array {
		$user = $this->find($scimId);
		$this->apply($user, $body);
		return $this->toScim($user);
	}

	public function patch(string $scimId, array $body): array {
		$user = $this->find($scimId);
		$this->apply($user, Patch::userAttrs($body));
		return $this->toScim($user);
	}

	public function delete(string $scimId): void {
		$user = $this->find($scimId);
		$uid = $user->getUID();
		$this->guardAdmin($user);
		switch ($this->settings->deleteMode()) {
			case 'delete':
				$user->delete();
				$this->store->deleteUserRow($uid);
				return;
			case 'disable_purge':
				if ($this->store->userRow($uid)['pending_delete_at'] === null) {
					$this->store->setPendingDelete($uid, $this->time->getTime() + $this->settings->purgeDays() * 86400);
				}
				// no break: disabled as well
			default:
				$user->setEnabled(false);
		}
	}

	/** Hard-delete accounts whose purge date has passed (background job). Returns how many. */
	public function purgeDue(): int {
		if ($this->settings->deleteMode() !== 'disable_purge') {
			return 0; // ponytail: pending dates are only honoured while the purge mode is selected
		}
		$n = 0;
		foreach ($this->store->duePurges($this->time->getTime()) as $uid) {
			$user = $this->users->get($uid);
			if ($user !== null && !$user->isEnabled()) {
				$user->delete();
				$n++;
			}
			$this->store->deleteUserRow($uid);
		}
		return $n;
	}

	private function find(string $scimId): IUser {
		$uid = $this->store->uidByScimId($scimId);
		$user = $uid === null ? null : $this->users->get($uid);
		if ($user === null) {
			if ($uid !== null) {
				$this->store->deleteUserRow($uid); // removed in Nextcloud meanwhile
			}
			throw new ScimException(404, "User $scimId not found");
		}
		return $user;
	}

	private function apply(IUser $user, array $a, bool $creating = false): void {
		$uid = $user->getUID();
		if (isset($a['userName']) && is_string($a['userName']) && strcasecmp($a['userName'], $uid) !== 0) {
			throw new ScimException(400, 'userName cannot be changed', 'mutability');
		}
		$active = null;
		if (array_key_exists('active', $a)) {
			$active = filter_var($a['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE); // Entra: "False"
			if ($active === null) {
				throw new ScimException(400, 'active must be a boolean', 'invalidValue');
			}
			if (!$active) {
				$this->guardAdmin($user);
			}
		}
		if (array_key_exists('externalId', $a)) {
			$ext = is_string($a['externalId']) && $a['externalId'] !== '' ? $a['externalId'] : null;
			$this->assertExternalIdFree($ext, $uid);
			$this->store->setUserExternalId($uid, $ext);
		} elseif ($creating) {
			$this->store->userRow($uid);
		}
		$name = is_array($a['name'] ?? null) ? $a['name'] : [];
		$display = $a['displayName'] ?? $name['formatted'] ?? trim(($name['givenName'] ?? '') . ' ' . ($name['familyName'] ?? ''));
		if (is_string($display) && $display !== '') {
			$user->setDisplayName($display);
		}
		if (array_key_exists('emails', $a) && is_array($a['emails'])) {
			$user->setSystemEMailAddress($this->primaryEmail($a['emails']));
		}
		if ($active !== null) {
			$user->setEnabled($active);
			if ($active) {
				$this->store->setPendingDelete($uid, null);
			}
		}
	}

	private function primaryEmail(array $emails): string {
		$first = null;
		foreach ($emails as $e) {
			if (is_array($e) && is_string($e['value'] ?? null)) {
				if (!empty($e['primary'])) {
					return $e['value'];
				}
				$first ??= $e['value'];
			}
		}
		return $first ?? '';
	}

	private function assertExternalIdFree(mixed $ext, ?string $uid): void {
		if (is_string($ext) && $ext !== '') {
			$owner = $this->store->uidByExternalId($ext);
			if ($owner !== null && $owner !== $uid) {
				throw new ScimException(409, "externalId $ext is used by another user", 'uniqueness');
			}
		}
	}

	private function guardAdmin(IUser $user): void {
		if ($this->settings->protectAdmins() && $this->groups->isAdmin($user->getUID())) {
			throw new ScimException(403, 'Administrators are protected from SCIM (see the app settings)');
		}
	}
}
