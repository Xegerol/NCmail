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
 * Add local_only column to mail_mailboxes table to support local folders for POP3 accounts
 */
class Version5300Date20260207000001 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return ISchemaWrapper
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('mail_mailboxes')) {
			$table = $schema->getTable('mail_mailboxes');
			
			if (!$table->hasColumn('local_only')) {
				$table->addColumn('local_only', Types::BOOLEAN, [
					'notnull' => true,
					'default' => false,
				]);
			}
		}

		return $schema;
	}
}
