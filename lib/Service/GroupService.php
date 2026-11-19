<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Service;

use OCA\ScimServer\Db\Store;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;

class GroupService {
	public const SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';

	public function __construct(
		private IGroupManager $groups,
		private IUserManager $users,
		private Store $store,
		private Settings $settings,
		private IURLGenerator $urls,
	) {
	}

	/** The Nextcloud group id/name for a group name as the identity provider knows it (see Settings::groupPrefix). */
	private function prefixed(string $name): string {
		return $this->settings->groupPrefix() . $name;
	}

	/** The name the identity provider knows: the Nextcloud name without the prefix. */
	private function plain(string $name): string {
		$prefix = $this->settings->groupPrefix();
		return $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
	}

	public function toScim(IGroup $group): array {
		$row = $this->store->groupRow($group->getGID());
		$members = [];
		// ponytail: one lookup per member; batch if groups with thousands of members matter
		foreach ($group->getUsers() as $u) {
			if (!$this->userVisible($u->getUID())) {
				continue;
			}
			$members[] = ['value' => $this->store->userRow($u->getUID())['scim_id'], 'display' => $u->getDisplayName()];
		}
		$res = [
			'schemas' => [self::SCHEMA],
			'id' => $row['scim_id'],
			'displayName' => $this->plain($group->getDisplayName()),
			'members' => $members,
			'meta' => [
				'resourceType' => 'Group',
				'created' => gmdate('c', $row['created_at']),
				'location' => $this->urls->linkToRouteAbsolute('scim_server.scim.getGroup', ['id' => $row['scim_id']]),
			],
		];
		if ($row['external_id'] !== null) {
			$res['externalId'] = $row['external_id'];
		}
		return $res;
	}

	public function get(string $scimId): array {
		if (($gid = $this->stubGid($scimId)) !== null) {
			return $this->stub($gid);
		}
		return $this->toScim($this->find($scimId));
	}

	/**
	 * A group that the group filter keeps out of Nextcloud: the IdP still gets a stable id for it and
	 * every later request succeeds, but nothing is created or changed in Nextcloud.
	 */
	private function stub(string $gid): array {
		$row = $this->store->groupRow($gid);
		$res = [
			'schemas' => [self::SCHEMA],
			'id' => $row['scim_id'],
			'displayName' => $this->plain($gid),
			'members' => [],
			'meta' => [
				'resourceType' => 'Group',
				'created' => gmdate('c', $row['created_at']),
				'location' => $this->urls->linkToRouteAbsolute('scim_server.scim.getGroup', ['id' => $row['scim_id']]),
			],
		];
		if ($row['external_id'] !== null) {
			$res['externalId'] = $row['external_id'];
		}
		return $res;
	}

	private function stubGid(string $scimId): ?string {
		$gid = $this->store->gidByScimId($scimId);
		return $gid !== null && $this->store->ignoredGroupRow($gid) !== null ? $gid : null;
	}

	/** A previously excluded group got a name that passes the filter: create it for real, keeping its SCIM id. */
	private function promote(string $gid, string $name, array $body): IGroup {
		$this->resolve(Patch::memberIds($body['members'] ?? []));
		$group = $this->groups->createGroup($this->prefixed($name));
		if (!$group instanceof IGroup) {
			throw new ScimException(400, "Cannot create group $name", 'invalidValue');
		}
		$this->store->deleteGroupRow($group->getGID()); // a stale row of the same name must not clash
		$this->store->moveGroupRow($gid, $group->getGID());
		$this->store->setGroupManaged($group->getGID());
		$this->setExternalId($group, $body);
		$this->syncMembers($group, Patch::memberIds($body['members'] ?? []));
		return $group;
	}

	public function list(?string $filter, int $startIndex, int $count): array {
		$startIndex = max(1, $startIndex);
		$count = max(0, min($count, UserService::MAX));
		$f = Filter::parse($filter);
		if ($f !== null) {
			$group = match ($f[0]) {
				'displayname' => $this->byName($this->prefixed($f[1])),
				'externalid' => ($gid = $this->store->gidByExternalId($f[1])) !== null ? $this->groups->get($gid) : null,
				default => throw new ScimException(400, "Cannot filter on $f[0]", 'invalidFilter'),
			};
			$stubGid = $f[0] === 'displayname' ? mb_substr($this->prefixed($f[1]), 0, 64) : $this->store->gidByExternalId($f[1]);
			$all = $group !== null ? ($this->groupVisible($group->getGID()) ? [$group] : [])
				: ($stubGid !== null && $this->store->ignoredGroupRow($stubGid) !== null ? [$stubGid] : []);
		} elseif ($this->settings->managedOnly()) {
			$all = array_values(array_filter(array_map(fn (string $gid) => $this->groups->get($gid), $this->store->managedGroups())));
		} else {
			$all = array_values($this->groups->search(''));
		}
		$page = array_slice($all, $startIndex - 1, $count);
		return [
			'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
			'totalResults' => count($all),
			'startIndex' => $startIndex,
			'itemsPerPage' => count($page),
			'Resources' => array_map(fn (IGroup|string $g) => is_string($g) ? $this->stub($g) : $this->toScim($g), $page),
		];
	}

	public function create(array $body): array {
		$name = $body['displayName'] ?? null;
		if (!is_string($name) || trim($name) === '') {
			throw new ScimException(400, 'displayName is required', 'invalidValue');
		}
		$full = $this->prefixed($name);
		if ($this->byName($full) !== null) {
			throw new ScimException(409, "Group $name already exists", 'uniqueness');
		}
		if ($this->store->ignoredGroupRow(mb_substr($full, 0, 64)) !== null) {
			throw new ScimException(409, "Group $name already exists", 'uniqueness');
		}
		$this->assertExternalIdFree($body['externalId'] ?? null, null);
		if (!$this->settings->groupAllowed($name)) {
			$gid = mb_substr($full, 0, 64);
			$this->store->setGroupIgnored($gid, true);
			$this->setExternalId(null, $body, $gid);
			return $this->stub($gid);
		}
		$this->resolve(Patch::memberIds($body['members'] ?? [])); // fail before creating, not after: no empty groups left behind
		$group = $this->groups->createGroup($full);
		if (!$group instanceof IGroup) {
			throw new ScimException(400, "Cannot create group $name", 'invalidValue');
		}
		$this->store->setGroupManaged($group->getGID());
		$this->setExternalId($group, $body);
		$this->syncMembers($group, Patch::memberIds($body['members'] ?? []));
		return $this->toScim($group);
	}

	public function replace(string $scimId, array $body): array {
		if (($gid = $this->stubGid($scimId)) !== null) {
			$name = is_string($body['displayName'] ?? null) ? $body['displayName'] : $this->plain($gid);
			if ($this->settings->groupAllowed($name)) {
				return $this->toScim($this->promote($gid, $name, $body));
			}
			$this->setExternalId(null, $body, $gid);
			return $this->stub($gid);
		}
		$group = $this->find($scimId);
		$this->guardAdmin($group);
		if (is_string($body['displayName'] ?? null) && $body['displayName'] !== '' && !$this->foreign($group)) {
			$this->rename($group, $this->prefixed($body['displayName']));
		}
		$this->setExternalId($group, $body);
		if (array_key_exists('members', $body)) {
			$this->syncMembers($group, Patch::memberIds($body['members']));
		}
		return $this->toScim($group);
	}

	public function patch(string $scimId, array $body): array {
		if (($gid = $this->stubGid($scimId)) !== null) {
			$name = Patch::groupChanges($body)['displayName'];
			if ($name !== null && $this->settings->groupAllowed($name)) {
				return $this->toScim($this->promote($gid, $name, []));
			}
			return $this->stub($gid);
		}
		$group = $this->find($scimId);
		$this->guardAdmin($group);
		$changes = Patch::groupChanges($body);
		if ($changes['displayName'] !== null && !$this->foreign($group)) {
			$this->rename($group, $this->prefixed($changes['displayName']));
		}
		foreach ($changes['members'] as [$op, $ids]) {
			match ($op) {
				'add' => $this->addMembers($group, $ids),
				'remove' => $this->removeMembers($group, $ids),
				default => $this->syncMembers($group, $ids),
			};
		}
		return $this->toScim($group);
	}

	public function delete(string $scimId): void {
		if (($gid = $this->stubGid($scimId)) !== null) {
			$this->store->deleteGroupRow($gid);
			return;
		}
		$group = $this->find($scimId);
		$this->guardAdmin($group);
		$gid = $group->getGID();
		if ($this->foreign($group)) {
			foreach ($group->getUsers() as $u) { // only the accounts SCIM manages leave; the group and its other members stay
				if ($this->userVisible($u->getUID())) {
					$group->removeUser($u);
				}
			}
			$this->store->deleteGroupRow($gid);
			return;
		}
		$group->delete(); // removes memberships only, never accounts or files
		$this->store->deleteGroupRow($gid);
	}

	private function find(string $scimId): IGroup {
		$gid = $this->store->gidByScimId($scimId);
		$group = $gid === null ? null : $this->groups->get($gid);
		if ($group === null || !$this->groupVisible($group->getGID())) { // rows of groups removed in Nextcloud are cleaned up by CleanupListener
			throw new ScimException(404, "Group $scimId not found");
		}
		return $group;
	}

	/** Lookup by display name (which is the group id unless renamed). */
	private function byName(string $name): ?IGroup {
		$g = $this->groups->get($name);
		if ($g !== null) {
			return $g;
		}
		foreach ($this->groups->search($name) as $g) {
			if (strcasecmp($g->getDisplayName(), $name) === 0) {
				return $g;
			}
		}
		return null;
	}

	private function rename(IGroup $group, string $name): void {
		if ($group->getDisplayName() !== $name) {
			$group->setDisplayName($name); // the group id stays what it was
		}
	}

	private function setExternalId(?IGroup $group, array $body, ?string $gid = null): void {
		$gid ??= $group?->getGID();
		if ($gid !== null && array_key_exists('externalId', $body)) {
			$ext = is_string($body['externalId']) && $body['externalId'] !== '' ? $body['externalId'] : null;
			$this->assertExternalIdFree($ext, $gid);
			$this->store->setGroupExternalId($gid, $ext);
		}
	}

	private function assertExternalIdFree(mixed $ext, ?string $gid): void {
		if (is_string($ext) && $ext !== '') {
			if (mb_strlen($ext) > 255) {
				throw new ScimException(400, 'externalId is longer than 255 characters', 'invalidValue');
			}
			$owner = $this->store->gidByExternalId($ext);
			if ($owner !== null && $owner !== $gid) {
				throw new ScimException(409, "externalId $ext is used by another group", 'uniqueness');
			}
		}
	}

	/** @param list<string> $scimIds */
	private function resolve(array $scimIds): array {
		$out = [];
		foreach ($scimIds as $id) {
			$uid = $this->store->uidByScimId($id);
			$user = $uid === null ? null : $this->users->get($uid);
			if ($user === null || !$this->userVisible($user->getUID())) {
				throw new ScimException(400, "Unknown member $id: no such account was created through SCIM (sync users before groups, or switch off \"only manage accounts and groups created through SCIM\" so existing accounts can be adopted)", 'invalidValue');
			}
			$out[$user->getUID()] = $user;
		}
		return $out;
	}

	private function addMembers(IGroup $group, array $ids): void {
		foreach ($this->resolve($ids) as $user) {
			if (!$group->inGroup($user)) {
				$group->addUser($user);
			}
		}
	}

	private function removeMembers(IGroup $group, array $ids): void {
		foreach ($this->resolve($ids) as $user) {
			if ($group->inGroup($user)) {
				$group->removeUser($user);
			}
		}
	}

	private function syncMembers(IGroup $group, array $ids): void {
		$want = $this->resolve($ids);
		foreach ($group->getUsers() as $u) {
			// Members that SCIM cannot see (added in Nextcloud, not created through SCIM) are left alone.
			if (!isset($want[$u->getUID()]) && $this->userVisible($u->getUID())) {
				$group->removeUser($u);
			}
		}
		$this->addMembers($group, $ids);
	}

	private function userVisible(string $uid): bool {
		return !$this->settings->managedOnly() || $this->store->userRow($uid)['managed'];
	}

	private function groupVisible(string $gid): bool {
		return !$this->settings->managedOnly() || $this->store->groupRow($gid)['managed'] || $this->adoptable($gid);
	}

	/** An existing group that the admin allowed SCIM to manage the members of (Settings::adoptRegex). */
	private function adoptable(string $gid): bool {
		return $this->settings->groupAdoptable($this->plain($gid));
	}

	/**
	 * A group SCIM did not create and may only manage the members of: it keeps its name and is never deleted,
	 * because deleting a group also deletes its shares and permissions for members that SCIM does not manage.
	 */
	private function foreign(IGroup $group): bool {
		return $this->settings->managedOnly() && !$this->store->groupRow($group->getGID())['managed'];
	}

	private function guardAdmin(IGroup $group): void {
		if ($this->settings->protectAdmins() && $group->getGID() === 'admin') {
			throw new ScimException(403, 'The admin group is protected from SCIM (see the app settings)');
		}
		if (in_array($group->getGID(), $this->settings->protectedGroups(), true)) {
			throw new ScimException(403, 'This group is protected from SCIM (app config protected_groups)');
		}
	}
}
