<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\InboundProtocol;

use OCA\Mail\Account;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\IMAP\IMAPClientFactory;

/**
 * IMAP protocol implementation
 */
class ImapProtocolService implements IInboundProtocolService {
	private IMAPClientFactory $clientFactory;

	public function __construct(IMAPClientFactory $clientFactory) {
		$this->clientFactory = $clientFactory;
	}

	public function testConnection(Account $account): void {
		$client = $this->clientFactory->getClient($account);
		try {
			$client->login();
		} catch (\Horde_Imap_Client_Exception $e) {
			throw new ServiceException('IMAP connection test failed: ' . $e->getMessage(), 0, $e);
		} finally {
			$client->logout();
		}
	}

	public function getProtocolName(): string {
		return 'imap';
	}

	public function supportsFolders(): bool {
		return true;
	}

	public function supportsFlags(): bool {
		return true;
	}

	public function supportsSearch(): bool {
		return true;
	}
}
