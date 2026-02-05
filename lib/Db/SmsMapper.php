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
use OCA\Ocsms\Lib\PhoneNumberFormatter;

class SmsMapper {
	private static array $mailboxNames = [0 => 'inbox', 1 => 'sent', 2 => 'drafts'];
	private static array $messageTypes = [
		0 => 'all', 1 => 'inbox',
		2 => 'sent', 3 => 'drafts',
		4 => 'outbox', 5 => 'failed',
		6 => 'queued'
	];

	private IDBConnection $db;
	private ConversationStateMapper $convStateMapper;

	public function __construct(IDBConnection $db, ConversationStateMapper $cmapper) {
		$this->db = $db;
		$this->convStateMapper = $cmapper;
	}

	public function getAllIds(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('sms_id', 'sms_mailbox')
			->from('ocsms_smsdatas')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();

		$smsList = [];
		while ($row = $result->fetch()) {
			if (!array_key_exists((int)$row['sms_mailbox'], self::$mailboxNames)) {
				continue;
			}
			$mbox = self::$mailboxNames[(int)$row['sms_mailbox']];
			if (!isset($smsList[$mbox])) {
				$smsList[$mbox] = [];
			}

			if (!in_array($row['sms_id'], $smsList[$mbox])) {
				$smsList[$mbox][] = $row['sms_id'];
			}
		}
		$result->closeCursor();
		return $smsList;
	}

	public function getLastTimestamp(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('MAX(sms_date)'), 'mx')
			->from('ocsms_smsdatas')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();

		$row = $result->fetch();
		$result->closeCursor();

		if ($row && $row['mx'] !== null) {
			return (int)$row['mx'];
		}

		return 0;
	}

	public function getAllPhoneNumbers(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('sms_address')
			->from('ocsms_smsdatas')
			->where($qb->expr()->andX(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->in('sms_mailbox', $qb->createNamedParameter([0, 1, 3], \Doctrine\DBAL\Connection::PARAM_INT_ARRAY))
			));

		$result = $qb->executeQuery();

		$phoneList = [];
		while ($row = $result->fetch()) {
			$pn = $row['sms_address'];
			if (!in_array($pn, $phoneList)) {
				$phoneList[] = $pn;
			}
		}
		$result->closeCursor();
		return $phoneList;
	}

	public function getAllPhoneNumbersForFPN(string $userId, string $phoneNumber, string|false $country): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('sms_address')
			->from('ocsms_smsdatas')
			->where($qb->expr()->andX(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->in('sms_mailbox', $qb->createNamedParameter([0, 1, 3], \Doctrine\DBAL\Connection::PARAM_INT_ARRAY))
			));
		$result = $qb->executeQuery();

		$phoneList = [];
		while ($row = $result->fetch()) {
			$pn = $row['sms_address'];
			$fmtPN = PhoneNumberFormatter::format($country, $pn);
			if (!isset($phoneList[$fmtPN])) {
				$phoneList[$fmtPN] = [];
			}
			if (!isset($phoneList[$fmtPN][$pn])) {
				$phoneList[$fmtPN][$pn] = 0;
			}
			$phoneList[$fmtPN][$pn] += 1;
		}
		$result->closeCursor();

		$fpn = $phoneNumber;
		if (isset($phoneList[$fpn])) {
			return $phoneList[$fpn];
		}

		$fpn = PhoneNumberFormatter::format($country, $fpn);
		if (isset($phoneList[$fpn])) {
			return $phoneList[$fpn];
		}

		return [];
	}

	public function getAllMessagesForPhoneNumber(string $userId, string $phoneNumber, string|false $country, int $minDate = 0): array {
		$phlst = $this->getAllPhoneNumbersForFPN($userId, $phoneNumber, $country);
		$messageList = [];

		foreach ($phlst as $pn => $val) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('sms_date', 'sms_msg', 'sms_type')
				->from('ocsms_smsdatas')
				->where($qb->expr()->andX(
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
					$qb->expr()->eq('sms_address', $qb->createNamedParameter($pn)),
					$qb->expr()->in('sms_mailbox', $qb->createNamedParameter([0, 1, 3], \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)),
					$qb->expr()->gt('sms_date', $qb->createNamedParameter($minDate))
				));
			$result = $qb->executeQuery();

			while ($row = $result->fetch()) {
				$messageList[$row['sms_date']] = [
					'msg' => $row['sms_msg'],
					'type' => $row['sms_type']
				];
			}
			$result->closeCursor();
		}
		return $messageList;
	}

	public function getMessageCount(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from('ocsms_smsdatas')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();

		$row = $result->fetch();
		$result->closeCursor();

		if ($row) {
			return (int)$row['count'];
		}

		return 0;
	}

	public function getMessages(string $userId, int $start, int $limit): array {
		$messageList = [];

		$qb = $this->db->getQueryBuilder();
		$qb->select('sms_address', 'sms_date', 'sms_msg', 'sms_type', 'sms_mailbox')
			->from('ocsms_smsdatas')
			->where($qb->expr()->andX(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->gt('sms_date', $qb->createNamedParameter($start))
			))
			->orderBy('sms_date')
			->setMaxResults($limit);
		$result = $qb->executeQuery();

		while ($row = $result->fetch()) {
			$messageList[$row['sms_date']] = [
				'address' => $row['sms_address'],
				'mailbox' => (int)$row['sms_mailbox'],
				'msg' => $row['sms_msg'],
				'type' => (int)$row['sms_type']
			];
		}
		$result->closeCursor();
		return $messageList;
	}

	public function countMessagesForPhoneNumber(string $userId, string $phoneNumber, string|false $country): int {
		$cnt = 0;
		$phlst = $this->getAllPhoneNumbersForFPN($userId, $phoneNumber, $country);

		foreach ($phlst as $pn => $val) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectAlias($qb->createFunction('COUNT(*)'), 'ct')
				->from('ocsms_smsdatas')
				->where($qb->expr()->andX(
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
					$qb->expr()->eq('sms_address', $qb->createNamedParameter($pn)),
					$qb->expr()->in('sms_mailbox', $qb->createNamedParameter([0, 1, 3], \Doctrine\DBAL\Connection::PARAM_INT_ARRAY))
				));
			$result = $qb->executeQuery();

			$row = $result->fetch();
			$result->closeCursor();

			if ($row) {
				$cnt += (int)$row['ct'];
			}
		}
		return $cnt;
	}

	public function removeAllMessagesForUser(string $userId): void {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('ocsms_smsdatas')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function removeMessagesForPhoneNumber(string $userId, string $phoneNumber): void {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('ocsms_smsdatas')
				->where($qb->expr()->andX(
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
					$qb->expr()->eq('sms_address', $qb->createNamedParameter($phoneNumber))
				));
			$qb->executeStatement();
			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function removeMessage(string $userId, string $phoneNumber, int $messageId): void {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('ocsms_smsdatas')
				->where($qb->expr()->andX(
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
					$qb->expr()->eq('sms_address', $qb->createNamedParameter($phoneNumber)),
					$qb->expr()->eq('sms_date', $qb->createNamedParameter($messageId))
				));
			$qb->executeStatement();
			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function getLastMessageTimestampForAllPhonesNumbers(string $userId, bool $order = true): array {
		// Debug: Log total count for user without mailbox filter
		$qbDebug = $this->db->getQueryBuilder();
		$qbDebug->selectAlias($qbDebug->createFunction('COUNT(*)'), 'cnt')
			->from('ocsms_smsdatas')
			->where($qbDebug->expr()->eq('user_id', $qbDebug->createNamedParameter($userId)));
		$debugResult = $qbDebug->executeQuery();
		$debugRow = $debugResult->fetch();
		$debugResult->closeCursor();
		\OC::$server->getLogger()->warning("OCSMS Debug: Total SMS for user '$userId' = " . ($debugRow ? $debugRow['cnt'] : 'N/A'));
		
		// Debug: Check total SMS in DB
		$qbDebug2 = $this->db->getQueryBuilder();
		$qbDebug2->selectAlias($qbDebug2->createFunction('COUNT(*)'), 'cnt')->from('ocsms_smsdatas');
		$debugResult2 = $qbDebug2->executeQuery();
		$debugRow2 = $debugResult2->fetch();
		$debugResult2->closeCursor();
		\OC::$server->getLogger()->warning("OCSMS Debug: Total SMS in DB = " . ($debugRow2 ? $debugRow2['cnt'] : 'N/A'));
		
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('MAX(sms_date)'), 'mx')
			->addSelect('sms_address')
			->from('ocsms_smsdatas')
			->where($qb->expr()->andX(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->in('sms_mailbox', $qb->createNamedParameter([0, 1, 3], \Doctrine\DBAL\Connection::PARAM_INT_ARRAY))
			))
			->groupBy('sms_address');

		if ($order) {
			$qb->orderBy('mx', 'DESC');
		}

		$result = $qb->executeQuery();

		$phoneList = [];
		while ($row = $result->fetch()) {
			$phoneNumber = preg_replace('#[ ]#', '', $row['sms_address']);
			if (!array_key_exists($phoneNumber, $phoneList)) {
				$phoneList[$phoneNumber] = $row['mx'];
			} elseif ($phoneList[$phoneNumber] < $row['mx']) {
				$phoneList[$phoneNumber] = $row['mx'];
			}
		}
		$result->closeCursor();
		return $phoneList;
	}

	public function getNewMessagesCountForAllPhonesNumbers(string $userId, string $lastDate): array {
		$ld = ($lastDate === '') ? 0 : (int)$lastDate;

		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(sms_date)'), 'ct')
			->addSelect('sms_address')
			->from('ocsms_smsdatas')
			->where($qb->expr()->andX(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->in('sms_mailbox', $qb->createNamedParameter([0, 1, 3], \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)),
				$qb->expr()->gt('sms_date', $qb->createNamedParameter($ld))
			))
			->groupBy('sms_address');
		$result = $qb->executeQuery();

		$phoneList = [];
		while ($row = $result->fetch()) {
			$phoneNumber = preg_replace('#[ ]#', '', $row['sms_address']);
			if ($this->convStateMapper->getLastForPhoneNumber($userId, $phoneNumber) < $ld) {
				if (!array_key_exists($phoneNumber, $phoneList)) {
					$phoneList[$phoneNumber] = (int)$row['ct'];
				} else {
					$phoneList[$phoneNumber] += (int)$row['ct'];
				}
			}
		}
		$result->closeCursor();
		return $phoneList;
	}

	public function writeToDB(string $userId, array $smsList, bool $purgeAllSmsBeforeInsert = false): void {
		$this->db->beginTransaction();
		try {
			if ($purgeAllSmsBeforeInsert) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('ocsms_smsdatas')
					->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
				$qb->executeStatement();
			}

			foreach ($smsList as $sms) {
				$smsFlags = sprintf(
					'%s%s',
					$sms['read'] === 'true' ? '1' : '0',
					$sms['seen'] === 'true' ? '1' : '0'
				);

				if (!$purgeAllSmsBeforeInsert) {
					$qb = $this->db->getQueryBuilder();
					$qb->delete('ocsms_smsdatas')
						->where($qb->expr()->andX(
							$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
							$qb->expr()->eq('sms_id', $qb->createNamedParameter((int)$sms['_id']))
						));
					$qb->executeStatement();
				}

				$now = date('Y-m-d H:i:s');
				$qb = $this->db->getQueryBuilder();
				$qb->insert('ocsms_smsdatas')
					->values([
						'user_id' => $qb->createNamedParameter($userId),
						'added' => $qb->createNamedParameter($now),
						'lastmodified' => $qb->createNamedParameter($now),
						'sms_flags' => $qb->createNamedParameter($smsFlags),
						'sms_date' => $qb->createNamedParameter($sms['date']),
						'sms_id' => $qb->createNamedParameter((int)$sms['_id']),
						'sms_address' => $qb->createNamedParameter($sms['address']),
						'sms_msg' => $qb->createNamedParameter($sms['body']),
						'sms_mailbox' => $qb->createNamedParameter((int)$sms['mbox']),
						'sms_type' => $qb->createNamedParameter((int)$sms['type'])
					]);
				$qb->executeStatement();
			}

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
