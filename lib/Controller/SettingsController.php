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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;

use OCA\Ocsms\Db\ConfigMapper;
use OCA\Ocsms\Lib\CountryCodes;

class SettingsController extends Controller {
	private ConfigMapper $configMapper;

	public function __construct(string $appName, IRequest $request, ConfigMapper $cfgMapper) {
		parent::__construct($appName, $request);
		$this->configMapper = $cfgMapper;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getSettings(): JSONResponse {
		$country = $this->configMapper->getKey('country');
		if ($country === false) {
			return new JSONResponse(['status' => false]);
		}

		return new JSONResponse([
			'status' => true,
			'country' => $country,
			'message_limit' => $this->configMapper->getMessageLimit(),
			'notification_state' => $this->configMapper->getNotificationState(),
			'contact_order' => $this->configMapper->getContactOrder(),
			'contact_order_reverse' => $this->configMapper->getContactOrderReverse(),
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function setCountry(string $country): JSONResponse {
		if (!array_key_exists($country, CountryCodes::$codes)) {
			return new JSONResponse(['status' => false, 'msg' => 'Invalid country'], Http::STATUS_BAD_REQUEST);
		}
		$this->configMapper->set('country', $country);
		return new JSONResponse(['status' => true, 'msg' => 'OK']);
	}

	/**
	 * @NoAdminRequired
	 */
	public function setMessageLimit(int $limit): JSONResponse {
		$this->configMapper->set('message_limit', (string)$limit);
		return new JSONResponse(['status' => true, 'msg' => 'OK']);
	}

	/**
	 * @NoAdminRequired
	 */
	public function setNotificationState(int $notification): JSONResponse {
		if ($notification < 0 || $notification > 2) {
			return new JSONResponse(['status' => false, 'msg' => 'Invalid notification state'], Http::STATUS_BAD_REQUEST);
		}
		$this->configMapper->set('notification_state', (string)$notification);
		return new JSONResponse(['status' => true, 'msg' => 'OK']);
	}

	/**
	 * @NoAdminRequired
	 */
	public function setContactOrder(string $attribute, string $reverse): JSONResponse {
		if (!in_array($reverse, ['true', 'false']) || !in_array($attribute, ['lastmsg', 'label'])) {
			return new JSONResponse(['status' => false, 'msg' => 'Invalid contact ordering'], Http::STATUS_BAD_REQUEST);
		}
		$this->configMapper->set('contact_order', $attribute);
		$this->configMapper->set('contact_order_reverse', $reverse);
		return new JSONResponse(['status' => true, 'msg' => 'OK']);
	}
}
