<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add drafts_store_on_server column to mail_accounts table
 * Controls whether drafts should be synced to server (IMAP only) or kept local only
 */
class Version5300Date20260207000002 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return ISchemaWrapper
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('mail_accounts')) {
			$table = $schema->getTable('mail_accounts');
			
			if (!$table->hasColumn('drafts_store_on_server')) {
				$table->addColumn('drafts_store_on_server', Types::BOOLEAN, [
					'notnull' => true,
					'default' => false,
				]);
			}
		}

		return $schema;
	}
}
