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

class ConversationStateMapper {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	public function getLast(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('MAX(int_date)'), 'mx')
			->from('ocsms_conv_r_states')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();

		$row = $result->fetch();
		$result->closeCursor();

		if ($row && $row['mx'] !== null) {
			return (int)$row['mx'];
		}

		return 0;
	}

	public function getLastForPhoneNumber(string $userId, string $phoneNumber): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('MAX(int_date)'), 'mx')
			->from('ocsms_conv_r_states')
			->where($qb->expr()->andX(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->eq('phone_number', $qb->createNamedParameter($phoneNumber))
			));
		$result = $qb->executeQuery();

		$row = $result->fetch();
		$result->closeCursor();

		if ($row && $row['mx'] !== null) {
			return (int)$row['mx'];
		}

		return 0;
	}

	public function setLast(string $userId, string $phoneNumber, int $lastDate): void {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('ocsms_conv_r_states')
				->where($qb->expr()->andX(
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
					$qb->expr()->eq('phone_number', $qb->createNamedParameter($phoneNumber))
				));
			$qb->executeStatement();

			$qb = $this->db->getQueryBuilder();
			$qb->insert('ocsms_conv_r_states')
				->values([
					'user_id' => $qb->createNamedParameter($userId),
					'phone_number' => $qb->createNamedParameter($phoneNumber),
					'int_date' => $qb->createNamedParameter($lastDate)
				]);
			$qb->executeStatement();

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function migrate(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'datakey', 'datavalue')
			->from('ocsms_user_datas')
			->where($qb->expr()->like('datakey', $qb->createNamedParameter('lastReadDate-%')));

		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$pn = preg_replace('#lastReadDate[-]#', '', $row['datakey']);
			$this->setLast($row['user_id'], $pn, (int)$row['datavalue']);
		}
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('ocsms_user_datas')
			->where($qb->expr()->like('datakey', $qb->createNamedParameter('lastReadDate-%')));
		$qb->executeStatement();
	}
}
