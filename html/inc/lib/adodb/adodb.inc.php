<?php

/*******************************************************************************
 * Legacy include-path shim.
 *
 * The bundled 2008-era ADOdb was removed; this loads the maintained release
 * installed via Composer (vendor/adodb/adodb-php) and maps the legacy
 * driver names used in inc/config/config.db.php to drivers that still
 * exist on PHP 8.
 ******************************************************************************/

require_once dirname(__DIR__, 4) . '/vendor/adodb/adodb-php/adodb.inc.php';

/**
 * Map a legacy TorrentFlux db_type to a modern ADOdb driver name.
 *
 * @param string $type legacy driver name (mysql/sqlite/postgres)
 * @return string modern ADOdb driver name
 */
function tf_adodb_driver($type) {
	switch (strtolower((string)$type)) {
		case 'mysql':
		case 'mysqlt':
		case 'maxsql':
			return 'mysqli';
		case 'sqlite':
			return 'sqlite3';
		case 'postgres':
		case 'postgres7':
		case 'postgres8':
			return 'postgres9';
		default:
			return $type;
	}
}
