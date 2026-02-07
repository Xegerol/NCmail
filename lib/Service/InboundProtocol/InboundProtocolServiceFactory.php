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
use OCA\Mail\POP3\Pop3ClientFactory;

/**
 * Factory to get the appropriate protocol service based on account configuration
 */
class InboundProtocolServiceFactory {
	private IMAPClientFactory $imapClientFactory;
	private Pop3ClientFactory $pop3ClientFactory;

	public function __construct(
		IMAPClientFactory $imapClientFactory,
		Pop3ClientFactory $pop3ClientFactory
	) {
		$this->imapClientFactory = $imapClientFactory;
		$this->pop3ClientFactory = $pop3ClientFactory;
	}

	/**
	 * Get protocol service for the given account
	 *
	 * @param Account $account
	 * @return IInboundProtocolService
	 * @throws ServiceException
	 */
	public function getService(Account $account): IInboundProtocolService {
		$protocol = $account->getMailAccount()->getInboundProtocol() ?? 'imap';

		return match ($protocol) {
			'imap' => new ImapProtocolService($this->imapClientFactory),
			'pop3' => new Pop3ProtocolService($this->pop3ClientFactory),
			default => throw new ServiceException("Unknown inbound protocol: $protocol"),
		};
	}
}
