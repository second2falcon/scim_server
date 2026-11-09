<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Db;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * Tokens, plus the SCIM id / externalId bookkeeping of users and groups.
 * Users and groups themselves always live in Nextcloud; a row here is created lazily the first
 * time a resource is seen, so accounts that already existed (e.g. from SSO login) are adopted.
 */
class Store {
	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
		private ISecureRandom $random,
	) {
	}

	// ---- tokens ----

	/** @return array{id:int,token:string} the plain token is only available now */
	public function createToken(string $label): array {
		$token = $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
		$q = $this->db->getQueryBuilder();
		$q->insert('scim_tokens')->values([
			'label' => $q->createNamedParameter(mb_substr($label, 0, 128)),
			'token_hash' => $q->createNamedParameter(hash('sha256', $token)),
			'created_at' => $q->createNamedParameter($this->time->getTime()),
		])->executeStatement();
		return ['id' => $q->getLastInsertId(), 'token' => $token];
	}

	/** @return list<array<string,mixed>> */
	public function listTokens(): array {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'label', 'created_at', 'last_used')->from('scim_tokens')->orderBy('id');
		return $q->executeQuery()->fetchAll();
	}

	public function deleteToken(int $id): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('scim_tokens')->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeStatement();
	}

	/** Valid token? Also records last use (at most once a minute per token). */
	public function checkToken(string $token): bool {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'token_hash', 'last_used')->from('scim_tokens')
			->where($q->expr()->eq('token_hash', $q->createNamedParameter(hash('sha256', $token))));
		$row = $q->executeQuery()->fetch();
		if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $token))) {
			return false;
		}
		$now = $this->time->getTime();
		if ($row['last_used'] === null || $now - (int)$row['last_used'] >= 60) {
			$u = $this->db->getQueryBuilder();
			$u->update('scim_tokens')->set('last_used', $u->createNamedParameter($now))
				->where($u->expr()->eq('id', $u->createNamedParameter((int)$row['id'])))->executeStatement();
		}
		return true;
	}

	// ---- users ----

	/** @return array{scim_id:string,external_id:?string,created_at:int,pending_delete_at:?int} */
	public function userRow(string $uid): array {
		return $this->row('scim_users', 'uid', $uid);
	}

	public function uidByScimId(string $scimId): ?string {
		return $this->find('scim_users', 'uid', 'scim_id', $scimId);
	}

	public function uidByExternalId(string $externalId): ?string {
		return $this->find('scim_users', 'uid', 'external_id', $externalId);
	}

	public function setUserExternalId(string $uid, ?string $externalId): void {
		$this->userRow($uid);
		$this->set('scim_users', 'uid', $uid, 'external_id', $externalId);
	}

	public function setPendingDelete(string $uid, ?int $at): void {
		$this->userRow($uid);
		$this->set('scim_users', 'uid', $uid, 'pending_delete_at', $at);
	}

	/** @return list<string> uids whose purge date has passed */
	public function duePurges(int $now): array {
		$q = $this->db->getQueryBuilder();
		$q->select('uid')->from('scim_users')->where($q->expr()->isNotNull('pending_delete_at'))
			->andWhere($q->expr()->lte('pending_delete_at', $q->createNamedParameter($now)));
		return array_map('strval', $q->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}

	public function deleteUserRow(string $uid): void {
		$this->remove('scim_users', 'uid', $uid);
	}

	// ---- groups ----

	/** @return array{scim_id:string,external_id:?string,created_at:int} */
	public function groupRow(string $gid): array {
		return $this->row('scim_groups', 'gid', $gid);
	}

	public function gidByScimId(string $scimId): ?string {
		return $this->find('scim_groups', 'gid', 'scim_id', $scimId);
	}

	public function gidByExternalId(string $externalId): ?string {
		return $this->find('scim_groups', 'gid', 'external_id', $externalId);
	}

	public function setGroupExternalId(string $gid, ?string $externalId): void {
		$this->groupRow($gid);
		$this->set('scim_groups', 'gid', $gid, 'external_id', $externalId);
	}

	public function deleteGroupRow(string $gid): void {
		$this->remove('scim_groups', 'gid', $gid);
	}

	// ---- helpers ----

	private function row(string $table, string $key, string $id): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from($table)->where($q->expr()->eq($key, $q->createNamedParameter($id)));
		$row = $q->executeQuery()->fetch();
		if (!$row) {
			$row = ['scim_id' => $this->uuid(), 'external_id' => null, 'created_at' => $this->time->getTime(), 'pending_delete_at' => null];
			$i = $this->db->getQueryBuilder();
			$values = [
				$key => $i->createNamedParameter($id),
				'scim_id' => $i->createNamedParameter($row['scim_id']),
				'created_at' => $i->createNamedParameter($row['created_at']),
			];
			$i->insert($table)->values($values)->executeStatement();
		}
		$row['created_at'] = (int)$row['created_at'];
		$row['pending_delete_at'] = isset($row['pending_delete_at']) ? (int)$row['pending_delete_at'] : null;
		return $row;
	}

	private function find(string $table, string $key, string $column, string $value): ?string {
		$q = $this->db->getQueryBuilder();
		$q->select($key)->from($table)->where($q->expr()->eq($column, $q->createNamedParameter($value)))->setMaxResults(1);
		$found = $q->executeQuery()->fetchOne();
		return $found === false ? null : (string)$found;
	}

	private function set(string $table, string $key, string $id, string $column, string|int|null $value): void {
		$q = $this->db->getQueryBuilder();
		$q->update($table)->set($column, $q->createNamedParameter($value))
			->where($q->expr()->eq($key, $q->createNamedParameter($id)))->executeStatement();
	}

	private function remove(string $table, string $key, string $id): void {
		$q = $this->db->getQueryBuilder();
		$q->delete($table)->where($q->expr()->eq($key, $q->createNamedParameter($id)))->executeStatement();
	}

	private function uuid(): string {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}
}
