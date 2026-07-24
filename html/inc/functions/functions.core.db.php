<?php

/* $Id$ */

/*******************************************************************************

 LICENSE

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License (GPL)
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

 To read the license please visit http://www.gnu.org/copyleft/gpl.html

*******************************************************************************/

/**
 * ADOdb
 */
require_once('inc/lib/adodb/adodb.inc.php');

/**
 * initialize ADOdb-connection
 */
function dbInitialize() {
	global $cfg, $db;
	// create ado-object
    $db = ADONewConnection(tf_adodb_driver($cfg["db_type"]));
    // connect
    if ($cfg["db_pcon"])
    	$result = @ $db->PConnect($cfg["db_host"], $cfg["db_user"], $cfg["db_pass"], $cfg["db_name"]);
    else
    	$result = @ $db->Connect($cfg["db_host"], $cfg["db_user"], $cfg["db_pass"], $cfg["db_name"]);
    // register shutdown-function
    @register_shutdown_function("dbDispose");
    // check for error
    if ($db->ErrorNo() != 0 || !$result)
    	@error("Database Connection Problems", "", "", array("Check your database-config-file. (inc/config/config.db.php)"));
}

/**
 * dispose ADOdb-connection
 */
function dbDispose() {
	global $db;
	// close connection
	@ $db->Close();
}

/**
 * build a plain INSERT statement from a field=>value array.
 *
 * Replaces ADOdb's GetInsertSQL(), whose table-name code path is broken
 * with the sqlite3 driver (unguarded dummy query id).
 *
 * @param $table table name
 * @param $record assoc array of field => value
 * @return string SQL statement
 */
function tf_db_insert_sql($table, $record) {
	global $db;
	$fields = array();
	$values = array();
	foreach ($record as $field => $value) {
		$fields[] = $field;
		$values[] = is_null($value) ? "NULL" : $db->qstr($value);
	}
	return "INSERT INTO ".$table." (".implode(",", $fields).") VALUES (".implode(",", $values).")";
}

/**
 * db-error-function
 *
 * @param $sql
 */
function dbError($sql) {
	global $cfg, $db;
	$msgs = array();
	$dbErrMsg = $db->ErrorMsg();
	array_push($msgs, "ErrorMsg : ");
	array_push($msgs, $dbErrMsg);
	if ($cfg["debug_sql"] != 0) {
		array_push($msgs, "\nSQL : ");
		array_push($msgs, $sql);
	}
	array_push($msgs, "");
	if (preg_match('/.*Query.*empty.*/i', $dbErrMsg))
		array_push($msgs, "\nDatabase may be corrupted. Try to repair the tables.");
	else
		array_push($msgs, "\nAlways check your database settings in the config.db.php file.");
	@error("Database-Error", "", "", $msgs);
}

?>