<?php

declare(strict_types=1);

/**
 * Nextcloud - Phone Sync
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Loic Blot <loic.blot@unix-experience.fr>
 * @copyright Loic Blot 2014-2017
 */

namespace OCA\OcSms\Db;

use OCP\IDBConnection;
use OCP\Security\ICrypto;

class ConfigMapper {
	private IDBConnection $db;
	private ?string $user;
	private ICrypto $crypto;

	public function __construct(IDBConnection $db, ?string $userId, ICrypto $crypto) {
		$this->db = $db;
		$this->user = $userId;
		$this->crypto = $crypto;
	}

	public function set(string $key, string $value): void {
		$encryptedValue = $this->crypto->encrypt($value);
		if ($this->hasKey($key)) {
			$sql = 'UPDATE `*PREFIX*ocsms_config` SET `value` = ? WHERE `user` = ? AND `key` = ?';
			$stmt = $this->db->prepare($sql);
			$stmt->execute([$encryptedValue, $this->user, $key]);
		} else {
			$sql = 'INSERT INTO `*PREFIX*ocsms_config` (`user`, `key`, `value`) VALUES (?, ?, ?)';
			$stmt = $this->db->prepare($sql);
			$stmt->execute([$this->user, $key, $encryptedValue]);
		}
	}

	public function hasKey(string $key): bool {
		$sql = 'SELECT `key` FROM `*PREFIX*ocsms_config` WHERE `key` = ? AND `user` = ?';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([$key, $this->user]);
		$row = $stmt->fetch();
		return $row !== false;
	}

	public function getKey(string $key): string|false {
		try {
			$sql = 'SELECT `value` FROM `*PREFIX*ocsms_config` WHERE `key` = ? AND `user` = ?';
			$stmt = $this->db->prepare($sql);
			$stmt->execute([$key, $this->user]);
			$row = $stmt->fetch();

			if ($row) {
				return $this->crypto->decrypt($row['value']);
			}
			return false;
		} catch (\Exception $e) {
			return false;
		}
	}

	public function getCountry(): string|false {
		return $this->getKey('country');
	}

	public function getMessageLimit(): int {
		$limit = $this->getKey('message_limit');
		if ($limit === false) {
			return 500;
		}
		return (int)$limit;
	}

	public function getNotificationState(): int {
		$st = $this->getKey('notification_state');
		if ($st === false) {
			return 1;
		}
		return (int)$st;
	}

	public function getContactOrder(): string {
		$order = $this->getKey('contact_order');
		if ($order === false) {
			return 'lastmsg';
		}
		return $order;
	}

	public function getContactOrderReverse(): string {
		$rev = $this->getKey('contact_order_reverse');
		if ($rev === false) {
			return 'true';
		}
		return $rev;
	}
}
