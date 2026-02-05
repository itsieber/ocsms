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
use OCP\IURLGenerator;
use OCP\Contacts\IManager as IContactsManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;

use OCA\Ocsms\Db\ConfigMapper;
use OCA\Ocsms\Db\SmsMapper;
use OCA\Ocsms\Db\ConversationStateMapper;
use OCA\Ocsms\Lib\ContactCache;
use OCA\Ocsms\Lib\PhoneNumberFormatter;

class SmsController extends Controller {
	private ?string $userId;
	private ConfigMapper $configMapper;
	private SmsMapper $smsMapper;
	private ConversationStateMapper $convStateMapper;
	private IURLGenerator $urlGenerator;
	private ContactCache $contactCache;

	public function __construct(
		string $appName,
		IRequest $request,
		?string $userId,
		SmsMapper $mapper,
		ConversationStateMapper $cmapper,
		ConfigMapper $cfgMapper,
		IContactsManager $contactsManager,
		IURLGenerator $urlGenerator
	) {
		parent::__construct($appName, $request);
		$this->userId = $userId;
		$this->smsMapper = $mapper;
		$this->convStateMapper = $cmapper;
		$this->configMapper = $cfgMapper;
		$this->urlGenerator = $urlGenerator;
		$this->contactCache = new ContactCache($cfgMapper, $contactsManager);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): TemplateResponse {
		$mboxes = [
			'PNLConversations' => [
				'label' => 'Conversations',
				'phoneNumbers' => $this->smsMapper->getAllPhoneNumbers($this->userId ?? ''),
				'url' => $this->urlGenerator->linkToRoute('ocsms.sms.index', ['feed' => 'conversations'])
			],
			'PNLDrafts' => [
				'label' => 'Drafts',
				'phoneNumbers' => [],
				'url' => $this->urlGenerator->linkToRoute('ocsms.sms.index', ['feed' => 'drafts'])
			]
		];

		$params = [
			'user' => $this->userId,
			'mailboxes' => $mboxes
		];
		$response = new TemplateResponse($this->appName, 'main', $params);
		$this->addContentSecurityToResponse($response);
		return $response;
	}

	private function addContentSecurityToResponse(TemplateResponse $response): void {
		$csp = new Http\ContentSecurityPolicy();
		$csp->allowEvalScript(true);
		$response->setContentSecurityPolicy($csp);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function retrieveAllPeers(): JSONResponse {
		$phoneList = $this->smsMapper->getLastMessageTimestampForAllPhonesNumbers($this->userId ?? '');
		$contactsSrc = $this->contactCache->getContacts();
		$contacts = [];
		$photos = $this->contactCache->getContactPhotos();
		$uids = $this->contactCache->getContactUids();

		$configuredCountry = $this->configMapper->getCountry();

		foreach ($phoneList as $number => $ts) {
			$fmtPN = PhoneNumberFormatter::format($configuredCountry, $number);
			if (isset($contactsSrc[$number])) {
				$contacts[$number] = $contactsSrc[$number];
			} elseif (isset($contactsSrc[$fmtPN])) {
				$contacts[$number] = $contactsSrc[$fmtPN];
			} elseif (isset($contacts[$fmtPN])) {
				$contacts[$number] = $fmtPN;
			} else {
				$contacts[$number] = $fmtPN;
			}
		}
		$lastRead = $this->convStateMapper->getLast($this->userId ?? '');
		$lastMessage = $this->smsMapper->getLastTimestamp($this->userId ?? '');

		return new JSONResponse([
			'phonelist' => $phoneList,
			'contacts' => $contacts,
			'lastRead' => $lastRead,
			'lastMessage' => $lastMessage,
			'photos' => $photos,
			'uids' => $uids,
			'photo_version' => 2
		]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getConversation(string $phoneNumber, int $lastDate = 0): JSONResponse {
		$contacts = $this->contactCache->getContacts();
		$iContacts = $this->contactCache->getInvertedContacts();
		$contactName = '';

		$configuredCountry = $this->configMapper->getCountry();

		$fmtPN = PhoneNumberFormatter::format($configuredCountry, $phoneNumber);
		if (isset($contacts[$fmtPN])) {
			$contactName = $contacts[$fmtPN];
		}

		$messages = [];
		$phoneNumbers = [];
		$msgCount = 0;

		if ($contactName !== '' && isset($iContacts[$contactName])) {
			foreach ($iContacts[$contactName] as $cnumber) {
				$messages = $messages + $this->smsMapper->getAllMessagesForPhoneNumber($this->userId ?? '', $cnumber, $configuredCountry, $lastDate);
				$msgCount += $this->smsMapper->countMessagesForPhoneNumber($this->userId ?? '', $cnumber, $configuredCountry);
				$phoneNumbers[] = PhoneNumberFormatter::format($configuredCountry, $cnumber);
			}
		} else {
			$messages = $this->smsMapper->getAllMessagesForPhoneNumber($this->userId ?? '', $phoneNumber, $configuredCountry, $lastDate);
			$msgCount = $this->smsMapper->countMessagesForPhoneNumber($this->userId ?? '', $phoneNumber, $configuredCountry);
			$phoneNumbers[] = PhoneNumberFormatter::format($configuredCountry, $phoneNumber);
		}

		ksort($messages);
		$msgLimit = $this->configMapper->getMessageLimit();
		$messages = array_slice($messages, -$msgLimit, $msgLimit, true);

		if (count($messages) > 0) {
			$maxDate = max(array_keys($messages));
			for ($i = 0; $i < count($phoneNumbers); $i++) {
				$this->convStateMapper->setLast($this->userId ?? '', $phoneNumbers[$i], (int)$maxDate);
			}
		}

		return new JSONResponse([
			'conversation' => $messages,
			'contactName' => $contactName,
			'phoneNumbers' => $phoneNumbers,
			'msgCount' => $msgCount
		]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function deleteConversation(string $contact): JSONResponse {
		$contacts = $this->contactCache->getContacts();
		$iContacts = $this->contactCache->getInvertedContacts();
		$contactName = '';

		$configuredCountry = $this->configMapper->getCountry();

		$fmtPN = PhoneNumberFormatter::format($configuredCountry, $contact);
		if (isset($contacts[$fmtPN])) {
			$contactName = $contacts[$fmtPN];
		}

		if ($contactName !== '' && isset($iContacts[$contactName])) {
			foreach ($iContacts[$contactName] as $cnumber) {
				$this->smsMapper->removeMessagesForPhoneNumber($this->userId ?? '', $cnumber);
			}
		} else {
			$phlist = $this->smsMapper->getAllPhoneNumbersForFPN($this->userId ?? '', $contact, $configuredCountry);
			foreach ($phlist as $phnumber => $value) {
				$this->smsMapper->removeMessagesForPhoneNumber($this->userId ?? '', $phnumber);
			}
		}
		return new JSONResponse(['status' => 'ok']);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function checkNewMessages(string $lastDate): JSONResponse {
		$phoneList = $this->smsMapper->getNewMessagesCountForAllPhonesNumbers($this->userId ?? '', $lastDate);
		$formatedPhoneList = [];
		$contactsSrc = $this->contactCache->getContacts();
		$photosSrc = $this->contactCache->getContactPhotos();
		$uidsSrc = $this->contactCache->getContactUids();
		$contacts = [];
		$photos = [];
		$uids = [];

		$configuredCountry = $this->configMapper->getCountry();

		foreach ($phoneList as $number => $ts) {
			$fmtPN = PhoneNumberFormatter::format($configuredCountry, $number);
			$formatedPhoneList[$number] = $ts;
			if (isset($contactsSrc[$fmtPN])) {
				$contacts[$fmtPN] = $contactsSrc[$fmtPN];
				if (isset($uidsSrc[$fmtPN])) {
					$uids[$fmtPN] = $uidsSrc[$fmtPN];
				}

				if (isset($photosSrc[$contacts[$fmtPN]])) {
					$photos[$contacts[$fmtPN]] = $photosSrc[$contacts[$fmtPN]];
				}
			}
		}

		return new JSONResponse([
			'phonelist' => $phoneList,
			'contacts' => $contacts,
			'photos' => $photos,
			'uids' => $uids
		]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function deleteMessage(string $messageId, string $phoneNumber): JSONResponse {
		if (!preg_match('#^[0-9]+$#', $messageId)) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$this->smsMapper->removeMessage($this->userId ?? '', $phoneNumber, (int)$messageId);
		return new JSONResponse(['status' => 'ok']);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function wipeAllUserMessages(): JSONResponse {
		$this->smsMapper->removeAllMessagesForUser($this->userId ?? '');
		return new JSONResponse(['status' => 'ok']);
	}
}
