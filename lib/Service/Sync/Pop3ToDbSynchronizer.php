<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\Sync;

use Horde_Mime_Part;
use OCA\Mail\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\MessageMapper as DatabaseMessageMapper;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\POP3\Pop3ClientFactory;
use OCA\Mail\POP3\Pop3Exception;
use OCA\Mail\POP3\Pop3MessageMapper;
use Psr\Log\LoggerInterface;

/**
 * Synchronizes messages from POP3 server to local database
 * Uses UIDL-based tracking to identify new messages
 */
class Pop3ToDbSynchronizer {
	private const SYNC_BATCH_SIZE = 50;

	private Pop3ClientFactory $clientFactory;
	private Pop3MessageMapper $messageMapper;
	private DatabaseMessageMapper $dbMapper;
	private MailboxMapper $mailboxMapper;
	private LoggerInterface $logger;

	public function __construct(
		Pop3ClientFactory $clientFactory,
		Pop3MessageMapper $messageMapper,
		DatabaseMessageMapper $dbMapper,
		MailboxMapper $mailboxMapper,
		LoggerInterface $logger
	) {
		$this->clientFactory = $clientFactory;
		$this->messageMapper = $messageMapper;
		$this->dbMapper = $dbMapper;
		$this->mailboxMapper = $mailboxMapper;
		$this->logger = $logger;
	}

	/**
	 * Synchronize messages for a POP3 account
	 *
	 * @param Account $account
	 * @param Mailbox $mailbox The INBOX mailbox (POP3 only has one mailbox)
	 * @return int Number of new messages synchronized
	 * @throws ServiceException
	 */
	public function sync(Account $account, Mailbox $mailbox): int {
		$this->logger->info("Starting POP3 sync for account {$account->getId()}");

		try {
			// Get all message UIDs from server (UIDL)
			$serverUidls = $this->messageMapper->getUniqueIds($account);
			
			if (empty($serverUidls)) {
				$this->logger->debug("No messages on POP3 server");
				return 0;
			}

			// Get existing UIDs from database (stored in the 'uid' field, but for POP3 it's UIDL string as int hash)
			$existingMessages = $this->dbMapper->findAllUids($mailbox);
			$existingUidls = [];
			
			// For POP3, we store a hash of the UIDL in the uid field since uid is int
			// We need to track UIDL separately - using message_id field as a workaround for now
			foreach ($existingMessages as $msgData) {
				// We'll store the UIDL in the message_id field for now
				// This is a temporary solution - ideally we'd have a separate uidl column
				$existingUidls[] = $msgData['messageId'] ?? null;
			}

			// Find new messages (on server but not in DB)
			$newUidls = array_diff(array_keys($serverUidls), $existingUidls);
			
			if (empty($newUidls)) {
				$this->logger->debug("No new messages to sync");
				return 0;
			}

			$this->logger->info("Found " . count($newUidls) . " new messages to sync");

			// Fetch and store new messages in batches
			$newMessageCount = 0;
			$batches = array_chunk($newUidls, self::SYNC_BATCH_SIZE);

			foreach ($batches as $batchUidls) {
				foreach ($batchUidls as $uidl) {
					try {
						$messageNumber = $serverUidls[$uidl];
						$this->fetchAndStoreMessage($account, $mailbox, $uidl, $messageNumber);
						$newMessageCount++;
					} catch (\Exception $e) {
						$this->logger->error("Failed to fetch POP3 message: " . $e->getMessage(), [
							'exception' => $e,
							'uidl' => $uidl,
						]);
						// Continue with next message
					}
				}
			}

			$this->logger->info("POP3 sync completed: $newMessageCount new messages");
			return $newMessageCount;

		} catch (Pop3Exception | ServiceException $e) {
			$this->logger->error("POP3 sync failed: " . $e->getMessage(), ['exception' => $e]);
			throw new ServiceException("POP3 synchronization failed: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Fetch a single message and store it in the database
	 */
	private function fetchAndStoreMessage(Account $account, Mailbox $mailbox, string $uidl, int $messageNumber): void {
		// Fetch raw message
		$rawMessage = $this->messageMapper->fetchMessage($account, $messageNumber);
		
		// Parse message
		$mimePart = $this->messageMapper->parseMessage($rawMessage);
		$headers = $this->messageMapper->extractHeaders($mimePart);

		// Create database message entity
		$message = new Message();
		$message->setMailboxId($mailbox->getId());
		
		// Use CRC32 of UIDL as UID (since uid column is integer)
		// This is not perfect but works for tracking
		$message->setUid(crc32($uidl));
		
		// Store UIDL in message_id field (temporary workaround)
		$message->setMessageId($uidl);
		
		// Store thread root ID (from actual Message-ID header if available)
		if (!empty($headers['message_id'])) {
			$message->setThreadRootId($headers['message_id']);
		}
		
		// Set basic headers
		$message->setSubject($headers['subject'] ?? '(No subject)');
		$message->setFrom($this->parseAddressList($headers['from'] ?? ''));
		$message->setTo($this->parseAddressList($headers['to'] ?? ''));
		$message->setCc($this->parseAddressList($headers['cc'] ?? ''));
		
		// Parse date
		$sentAt = $this->parseDate($headers['date'] ?? '');
		$message->setSentAt($sentAt);
		
		// Set references for threading
		$message->setInReplyTo($headers['in_reply_to'] ?? '');
		$message->setReferences($headers['references'] ?? '');

		// All flags are local-only for POP3
		$message->setFlagSeen(false);
		$message->setFlagAnswered(false);
		$message->setFlagDeleted(false);
		$message->setFlagDraft(false);
		$message->setFlagFlagged(false);

		// Store the message
		$this->dbMapper->insert($message);
		
		$this->logger->debug("Stored POP3 message: UIDL=$uidl, Subject={$message->getSubject()}");
	}

	/**
	 * Parse email address list
	 */
	private function parseAddressList(string $addressString): string {
		// Simple parsing - Horde libraries can do better but this works for basic cases
		return trim($addressString);
	}

	/**
	 * Parse date string to timestamp
	 */
	private function parseDate(string $dateString): int {
		if (empty($dateString)) {
			return time();
		}

		$timestamp = strtotime($dateString);
		return $timestamp !== false ? $timestamp : time();
	}
}
