<?php

declare(strict_types=1);

/**
 * Nextcloud - Phone Sync
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Loic Blot <loic.blot@unix-experience.fr>
 * @copyright Loic Blot 2014-2016
 */

namespace OCA\OcSms\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use Psr\Container\ContainerInterface;

use OCA\OcSms\Db\ConfigMapper;
use OCA\OcSms\Db\ConversationStateMapper;
use OCA\OcSms\Db\SmsMapper;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ocsms';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerService(ConversationStateMapper::class, function (ContainerInterface $c) {
			return new ConversationStateMapper(
				$c->get(IDBConnection::class)
			);
		});

		$context->registerService(SmsMapper::class, function (ContainerInterface $c) {
			return new SmsMapper(
				$c->get(IDBConnection::class),
				$c->get(ConversationStateMapper::class)
			);
		});

		$context->registerService(ConfigMapper::class, function (ContainerInterface $c) {
			$user = null;
			$userSession = $c->get(\OCP\IUserSession::class);
			if ($userSession->getUser() !== null) {
				$user = $userSession->getUser()->getUID();
			}
			return new ConfigMapper(
				$c->get(IDBConnection::class),
				$user,
				$c->get(ICrypto::class)
			);
		});
	}

	public function boot(IBootContext $context): void {
		// Boot logic if needed
	}
}
