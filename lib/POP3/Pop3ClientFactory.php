<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\POP3;

use OCA\Mail\Account;
use OCA\Mail\Exception\ServiceException;
use OCP\IConfig;
use OCP\Security\ICrypto;

/**
 * Factory for creating POP3 client connections
 */
class Pop3ClientFactory {
	private ICrypto $crypto;
	private IConfig $config;

	public function __construct(
		ICrypto $crypto,
		IConfig $config
	) {
		$this->crypto = $crypto;
		$this->config = $config;
	}

	/**
	 * Get a POP3 client for the given account
	 *
	 * @param Account $account
	 * @return Pop3Client
	 * @throws ServiceException
	 */
	public function getClient(Account $account): Pop3Client {
		$mailAccount = $account->getMailAccount();
		
		$host = $mailAccount->getInboundHost();
		$port = $mailAccount->getInboundPort();
		$sslMode = $mailAccount->getInboundSslMode();
		
		// Normalize SSL mode
		if ($sslMode === 'none') {
			$sslMode = 'none';
		}

		$client = new Pop3Client($host, $port, $sslMode);

		// Authenticate
		$user = $mailAccount->getInboundUser();
		$password = null;
		
		if ($mailAccount->getInboundPassword() !== null) {
			try {
				$password = $this->crypto->decrypt($mailAccount->getInboundPassword());
			} catch (\Exception $e) {
				throw new ServiceException('Could not decrypt POP3 password: ' . $e->getMessage(), 0, $e);
			}
		}

		if ($password === null) {
			throw new ServiceException('POP3 requires a password (OAuth not supported)');
		}

		try {
			$client->login($user, $password);
		} catch (Pop3Exception $e) {
			throw new ServiceException('POP3 authentication failed: ' . $e->getMessage(), 0, $e);
		}

		return $client;
	}
}
