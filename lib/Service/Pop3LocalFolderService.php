<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service;

use OCA\Mail\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Exception\ServiceException;
use Psr\Log\LoggerInterface;

/**
 * Service to manage local-only mailboxes for POP3 accounts
 * POP3 doesn't support server-side folders, so we create local folders
 */
class Pop3LocalFolderService {
	private MailboxMapper $mailboxMapper;
	private LoggerInterface $logger;

	/** Default local folders for POP3 accounts */
	private const LOCAL_FOLDERS = [
		['name' => 'INBOX', 'specialUse' => ['inbox']],
		['name' => 'Sent', 'specialUse' => ['sent']],
		['name' => 'Drafts', 'specialUse' => ['drafts']],
		['name' => 'Trash', 'specialUse' => ['trash']],
		['name' => 'Archive', 'specialUse' => ['archive']],
		['name' => 'Junk', 'specialUse' => ['junk']],
	];

	public function __construct(
		MailboxMapper $mailboxMapper,
		LoggerInterface $logger
	) {
		$this->mailboxMapper = $mailboxMapper;
		$this->logger = $logger;
	}

	/**
	 * Create default local folders for a POP3 account
	 *
	 * @param Account $account
	 * @throws ServiceException
	 */
	public function createDefaultFolders(Account $account): void {
		$accountId = $account->getId();
		$this->logger->info("Creating local folders for POP3 account $accountId");

		foreach (self::LOCAL_FOLDERS as $folderConfig) {
			try {
				// Check if folder already exists
				$existing = $this->mailboxMapper->findByName($account, $folderConfig['name']);
				if ($existing) {
					$this->logger->debug("Folder {$folderConfig['name']} already exists");
					continue;
				}
			} catch (\Exception $e) {
				// Folder doesn't exist, create it
			}

			$mailbox = new Mailbox();
			$mailbox->setAccountId($accountId);
			$mailbox->setName($folderConfig['name']);
			$mailbox->setLocalOnly(true);
			$mailbox->setSelectable(true);
			$mailbox->setMessages(0);
			$mailbox->setUnseen(0);
			$mailbox->setSyncInBackground(false);
			$mailbox->setShared(false);
			
			// Set special use
			$specialUse = json_encode($folderConfig['specialUse']);
			$mailbox->setSpecialUse($specialUse);
			
			// Set attributes
			$mailbox->setAttributes('[]');
			
			// Generate name hash
			$mailbox->setNameHash(hash('sha256', $folderConfig['name']));

			$this->mailboxMapper->insert($mailbox);
			$this->logger->debug("Created local folder: {$folderConfig['name']}");
		}

		$this->logger->info("Finished creating local folders for POP3 account $accountId");
	}

	/**
	 * Get the INBOX mailbox for a POP3 account (the only "real" mailbox)
	 *
	 * @param Account $account
	 * @return Mailbox|null
	 */
	public function getInbox(Account $account): ?Mailbox {
		try {
			return $this->mailboxMapper->findByName($account, 'INBOX');
		} catch (\Exception $e) {
			$this->logger->error("Failed to find INBOX for POP3 account: " . $e->getMessage());
			return null;
		}
	}
}
