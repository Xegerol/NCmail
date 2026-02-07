<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\POP3;

use Horde_Mime_Part;
use OCA\Mail\Account;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Exception\ServiceException;
use Psr\Log\LoggerInterface;

/**
 * Handles fetching and parsing messages from POP3 servers
 */
class Pop3MessageMapper {
	private Pop3ClientFactory $clientFactory;
	private LoggerInterface $logger;

	public function __construct(
		Pop3ClientFactory $clientFactory,
		LoggerInterface $logger
	) {
		$this->clientFactory = $clientFactory;
		$this->logger = $logger;
	}

	/**
	 * Get all message unique IDs from POP3 server
	 *
	 * @param Account $account
	 * @return array<string, int> UIDL => message number
	 * @throws ServiceException
	 */
	public function getUniqueIds(Account $account): array {
		$client = $this->clientFactory->getClient($account);
		
		try {
			$uidlList = $client->getUniqueIds();
			$client->disconnect();
			
			// Flip array to get UIDL => message number mapping
			$result = [];
			foreach ($uidlList as $msgNum => $uidl) {
				$result[$uidl] = $msgNum;
			}
			
			return $result;
		} catch (Pop3Exception $e) {
			$client->disconnect();
			throw new ServiceException('Failed to get POP3 message list: ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Fetch a message by its message number
	 *
	 * @param Account $account
	 * @param int $messageNumber
	 * @return string Raw message content
	 * @throws ServiceException
	 */
	public function fetchMessage(Account $account, int $messageNumber): string {
		$client = $this->clientFactory->getClient($account);
		
		try {
			$rawMessage = $client->retrieveMessage($messageNumber);
			$client->disconnect();
			
			return $rawMessage;
		} catch (Pop3Exception $e) {
			$client->disconnect();
			throw new ServiceException("Failed to fetch POP3 message #$messageNumber: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Parse a raw message into a Horde_Mime_Part structure
	 *
	 * @param string $rawMessage
	 * @return Horde_Mime_Part
	 */
	public function parseMessage(string $rawMessage): Horde_Mime_Part {
		return Horde_Mime_Part::parseMessage($rawMessage);
	}

	/**
	 * Extract message metadata (subject, from, to, date, etc.)
	 *
	 * @param Horde_Mime_Part $mimePart
	 * @return array
	 */
	public function extractHeaders(Horde_Mime_Part $mimePart): array {
		$headers = $mimePart->getHeaderOb();
		
		return [
			'subject' => $headers->getValue('subject') ?? '',
			'from' => $headers->getValue('from') ?? '',
			'to' => $headers->getValue('to') ?? '',
			'cc' => $headers->getValue('cc') ?? '',
			'date' => $headers->getValue('date') ?? '',
			'message_id' => $headers->getValue('message-id') ?? '',
			'in_reply_to' => $headers->getValue('in-reply-to') ?? '',
			'references' => $headers->getValue('references') ?? '',
		];
	}

	/**
	 * Get mailbox stats
	 *
	 * @param Account $account
	 * @return array{count: int, size: int}
	 * @throws ServiceException
	 */
	public function getStats(Account $account): array {
		$client = $this->clientFactory->getClient($account);
		
		try {
			$stats = $client->stat();
			$client->disconnect();
			
			return $stats;
		} catch (Pop3Exception $e) {
			$client->disconnect();
			throw new ServiceException('Failed to get POP3 stats: ' . $e->getMessage(), 0, $e);
		}
	}
}
