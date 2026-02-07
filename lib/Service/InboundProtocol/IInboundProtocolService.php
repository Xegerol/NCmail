<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\InboundProtocol;

use OCA\Mail\Account;
use OCA\Mail\Exception\ServiceException;

/**
 * Protocol-agnostic interface for inbound mail operations
 * Abstracts IMAP and POP3 differences
 */
interface IInboundProtocolService {
	/**
	 * Test connectivity to the mail server
	 *
	 * @param Account $account
	 * @throws ServiceException if connection fails
	 */
	public function testConnection(Account $account): void;

	/**
	 * Get the protocol name (imap or pop3)
	 *
	 * @return string
	 */
	public function getProtocolName(): string;

	/**
	 * Check if the protocol supports folders/mailboxes
	 *
	 * @return bool
	 */
	public function supportsFolders(): bool;

	/**
	 * Check if the protocol supports server-side flags
	 *
	 * @return bool
	 */
	public function supportsFlags(): bool;

	/**
	 * Check if the protocol supports server-side search
	 *
	 * @return bool
	 */
	public function supportsSearch(): bool;
}
