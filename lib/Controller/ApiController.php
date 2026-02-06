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

namespace OCA\Ocsms\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;

use OCA\Ocsms\Db\SmsMapper;
use OCA\Ocsms\Db\SendQueueMapper;

class ApiController extends Controller {
	private ?string $userId;
	private SmsMapper $smsMapper;
	private SendQueueMapper $sendQueueMapper;
	private string $errorMsg = '';

	public function __construct(string $appName, IRequest $request, ?string $userId, SmsMapper $mapper, SendQueueMapper $sendQueueMapper) {
		parent::__construct($appName, $request);
		$this->userId = $userId;
		$this->smsMapper = $mapper;
		$this->sendQueueMapper = $sendQueueMapper;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getApiVersion(): JSONResponse {
		return new JSONResponse(['version' => 1]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function retrieveAllIds(): JSONResponse {
		$smsList = $this->smsMapper->getAllIds($this->userId ?? '');
		return new JSONResponse(['smslist' => $smsList]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function retrieveLastTimestamp(): JSONResponse {
		$ts = $this->smsMapper->getLastTimestamp($this->userId ?? '');
		return new JSONResponse(['timestamp' => $ts]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function push(?int $smsCount, ?array $smsDatas): JSONResponse {
		if ($this->checkPushStructure($smsCount, $smsDatas) === false) {
			return new JSONResponse(
				['status' => false, 'msg' => $this->errorMsg],
				Http::STATUS_BAD_REQUEST
			);
		}

		$this->smsMapper->writeToDB($this->userId ?? '', $smsDatas);
		return new JSONResponse(['status' => true, 'msg' => 'OK']);
	}

	/**
	 * @NoAdminRequired
	 */
	public function replace(?int $smsCount, ?array $smsDatas): JSONResponse {
		if ($this->checkPushStructure($smsCount, $smsDatas) === false) {
			return new JSONResponse(
				['status' => false, 'msg' => $this->errorMsg],
				Http::STATUS_BAD_REQUEST
			);
		}

		$this->smsMapper->writeToDB($this->userId ?? '', $smsDatas, true);
		return new JSONResponse(['status' => true, 'msg' => 'OK']);
	}

	private function checkPushStructure(?int &$smsCount, ?array &$smsDatas): bool {
		if ($smsCount === null) {
			$this->errorMsg = 'Error: smsCount field is NULL';
			return false;
		}

		if ($smsDatas === null) {
			$this->errorMsg = 'Error: smsDatas field is NULL';
			return false;
		}

		if ($smsCount !== count($smsDatas)) {
			$this->errorMsg = 'Error: sms count invalid';
			return false;
		}

		foreach ($smsDatas as &$sms) {
			if (
				!array_key_exists('_id', $sms) || !array_key_exists('read', $sms) ||
				!array_key_exists('date', $sms) || !array_key_exists('seen', $sms) ||
				!array_key_exists('mbox', $sms) || !array_key_exists('type', $sms) ||
				!array_key_exists('body', $sms) || !array_key_exists('address', $sms)
			) {
				$this->errorMsg = 'Error: bad SMS entry';
				return false;
			}

			if (!is_numeric($sms['_id'])) {
				$this->errorMsg = sprintf("Error: Invalid SMS ID '%s'", $sms['_id']);
				return false;
			}

			if (!is_numeric($sms['type'])) {
				$this->errorMsg = sprintf("Error: Invalid SMS type '%s'", $sms['type']);
				return false;
			}

			if (!is_numeric($sms['mbox']) && $sms['mbox'] != 0 && $sms['mbox'] != 1 && $sms['mbox'] != 2) {
				$this->errorMsg = sprintf("Error: Invalid Mailbox ID '%s'", $sms['mbox']);
				return false;
			}

			if ($sms['read'] !== 'true' && $sms['read'] !== 'false') {
				$this->errorMsg = sprintf("Error: Invalid SMS Read state '%s'", $sms['read']);
				return false;
			}

			if ($sms['seen'] !== 'true' && $sms['seen'] !== 'false') {
				$this->errorMsg = 'Error: Invalid SMS Seen state';
				return false;
			}

			if (!is_numeric($sms['date']) && $sms['date'] != 0 && $sms['date'] != 1) {
				$this->errorMsg = 'Error: Invalid SMS date';
				return false;
			}
		}
		return true;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getAllStoredPhoneNumbers(): JSONResponse {
		$phoneList = $this->smsMapper->getAllPhoneNumbers($this->userId ?? '');
		return new JSONResponse(['phoneList' => $phoneList]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function fetchMessagesCount(): JSONResponse {
		return new JSONResponse(['count' => $this->smsMapper->getMessageCount($this->userId ?? '')]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function fetchMessages(int $start, int $limit): JSONResponse {
		if ($start < 0 || $limit <= 0) {
			return new JSONResponse(['msg' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
		}

		if ($limit > 500) {
			return new JSONResponse(['msg' => 'Too many messages requested'], 413);
		}

		$messages = $this->smsMapper->getMessages($this->userId ?? '', $start, $limit);
		$last_id = $start;
		if (count($messages) > 0) {
			$last_id = max(array_keys($messages));
		}

		return new JSONResponse(['messages' => $messages, 'last_id' => $last_id]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function fetchMessagesToSend(): JSONResponse {
		$messages = $this->sendQueueMapper->getMessagesForUser($this->userId ?? '');
		return new JSONResponse(['messages' => $messages]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function ackSentMessage(int $id): JSONResponse {
		$this->sendQueueMapper->deleteMessage($this->userId ?? '', $id);
		return new JSONResponse(['status' => 'ok']);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function generateSmsTestData(): JSONResponse {
		return $this->push(2, [
			['_id' => 702, 'type' => 1, 'mbox' => 2, 'read' => 'true', 'seen' => 'true', 'date' => 1654777747, 'address' => '+33123456789', 'body' => 'hello dude'],
			['_id' => 685, 'type' => 1, 'mbox' => 1, 'read' => 'true', 'seen' => 'true', 'date' => 1654777777, 'address' => '+33123456789', 'body' => '😀🌍⭐🌎🌔🌒🐕🍖🥂🍻🎮🤸‍♂️🚇🈲❕📘📚📈🇸🇨🇮🇲'],
		]);
	}
}
