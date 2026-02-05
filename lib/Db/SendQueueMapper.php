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

namespace OCA\Ocsms\Db;

use OCP\IDBConnection;

class SendQueueMapper {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	public function addMessage(string $userId, string $phoneNumber, string $message): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('ocsms_sendmess_queue')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'sms_address' => $qb->createNamedParameter($phoneNumber),
				'sms_msg' => $qb->createNamedParameter($message)
			]);
		$qb->executeStatement();
		return (int)$this->db->lastInsertId('ocsms_sendmess_queue');
	}

	public function getMessagesForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'sms_address', 'sms_msg')
			->from('ocsms_sendmess_queue')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();

		$messages = [];
		while ($row = $result->fetch()) {
			$messages[] = [
				'id' => (int)$row['id'],
				'address' => $row['sms_address'],
				'msg' => $row['sms_msg']
			];
		}
		$result->closeCursor();
		return $messages;
	}

	public function deleteMessage(string $userId, int $messageId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('ocsms_sendmess_queue')
			->where($qb->expr()->andX(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->eq('id', $qb->createNamedParameter($messageId))
			));
		$qb->executeStatement();
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('ocsms_sendmess_queue')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
