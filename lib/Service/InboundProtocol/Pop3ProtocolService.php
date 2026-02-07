<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\InboundProtocol;

use OCA\Mail\Account;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\POP3\Pop3ClientFactory;
use OCA\Mail\POP3\Pop3Exception;

/**
 * POP3 protocol implementation
 */
class Pop3ProtocolService implements IInboundProtocolService {
	private Pop3ClientFactory $clientFactory;

	public function __construct(Pop3ClientFactory $clientFactory) {
		$this->clientFactory = $clientFactory;
	}

	public function testConnection(Account $account): void {
		try {
			$client = $this->clientFactory->getClient($account);
			// getClient() already performs login, so just disconnect
			$client->disconnect();
		} catch (Pop3Exception | ServiceException $e) {
			throw new ServiceException('POP3 connection test failed: ' . $e->getMessage(), 0, $e);
		}
	}

	public function getProtocolName(): string {
		return 'pop3';
	}

	public function supportsFolders(): bool {
		return false;
	}

	public function supportsFlags(): bool {
		return false;
	}

	public function supportsSearch(): bool {
		return false;
	}
}
