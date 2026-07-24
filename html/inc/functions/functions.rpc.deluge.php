<?php

/*******************************************************************************

 LICENSE

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License (GPL)
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 To read the license please visit http://www.gnu.org/copyleft/gpl.html

*******************************************************************************/

require_once("inc/classes/Deluge.class.php");
// reuse the pure-PHP bencode info-hash helper from the qBittorrent layer
require_once("inc/functions/functions.rpc.qbittorrent.php");

function del_error($errorstr, $response = "") {
	global $cfg;
	AuditAction($cfg["constants"]["error"], "Deluge : $errorstr - $response");
	addGrowlMessage('deluge', $errorstr.' '.$response);
}

// -----------------------------------------------------------------------------
// ownership table (uid <-> torrent hash), created lazily so existing installs
// need no schema migration
// -----------------------------------------------------------------------------

function delEnsureUserTable() {
	global $db;
	static $done = false;
	if ($done) return;
	$db->Execute("CREATE TABLE IF NOT EXISTS tf_deluge_user ( uid INTEGER NOT NULL default 0, tid VARCHAR(40) NOT NULL default '' )");
	$done = true;
}

function addDelugeTransferToDB($uid, $tid) {
	global $db;
	delEnsureUserTable();
	$uid = (int)$uid;
	$tid = strtolower($tid);
	$db->Execute("DELETE FROM tf_deluge_user WHERE uid=$uid AND tid=".$db->qstr($tid));
	$sql = "INSERT INTO tf_deluge_user (uid,tid) VALUES ($uid,".$db->qstr($tid).")";
	$db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
}

function deleteDelugeTransferFromDB($uid, $tid) {
	global $db;
	delEnsureUserTable();
	$uid = (int)$uid;
	$sql = "DELETE FROM tf_deluge_user WHERE uid=$uid AND tid=".$db->qstr(strtolower($tid));
	$db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
}

function getUserDelugeTransferArrayFromDB($uid = 0) {
	global $db;
	delEnsureUserTable();
	$retVal = array();
	$uid = (int)$uid;
	$sql = "SELECT tid FROM tf_deluge_user WHERE uid=$uid";
	$recordset = $db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	while (($row = $recordset->FetchRow()) !== false)
		$retVal[] = strtolower($row[0]);
	return $retVal;
}

function isDelugeTransfer($hash) {
	if (empty($hash) || !Deluge::isRunning())
		return false;
	return (Deluge::getInstance()->torrent($hash) !== false);
}

function isValidDelugeTransfer($uid, $hash) {
	return isDelugeTransfer($hash);
}

// -----------------------------------------------------------------------------
// add / start / stop / delete
// -----------------------------------------------------------------------------

/**
 * Resolve an info-hash from a magnet URI or a local .torrent file.
 */
function delResolveHash($fileOrUrl) {
	if (preg_match('/urn:btih:([a-fA-F0-9]{40})/', $fileOrUrl, $m))
		return strtolower($m[1]);
	if (!preg_match('#^(https?|magnet):#i', $fileOrUrl) && function_exists('qbt_torrentInfoHash'))
		return qbt_torrentInfoHash($fileOrUrl);
	return "";
}

function addDelugeTransfer($uid, $fileOrUrl, $path, $paused = false) {
	$d = Deluge::getInstance();

	// adopt if it already exists
	$known = delResolveHash($fileOrUrl);
	if ($known != "" && $d->torrent($known) !== false) {
		addDelugeTransferToDB($uid, $known);
		return array("result" => "duplicate torrent");
	}

	$hash = $d->add($fileOrUrl, $path, $paused);
	if ($hash === false) {
		// duplicate adds return no hash + no error; fall back to the resolved hash
		if ($d->lastError == "" && $known != "" && $d->torrent($known) !== false) {
			addDelugeTransferToDB($uid, $known);
			return $known;
		}
		return array("result" => $d->lastError != "" ? $d->lastError : "add failed");
	}

	addDelugeTransferToDB($uid, $hash);
	return $hash;
}

function startDelugeTransfer($hash, $startPaused = false, $params = array()) {
	global $cfg;
	$d = Deluge::getInstance();
	if (!isValidDelugeTransfer($cfg['uid'], $hash)) {
		del_error("startDelugeTransfer : unknown transfer hash=$hash");
		return false;
	}
	if (isset($params['uploadLimit']))
		$d->setUploadLimit($hash, $params['uploadLimit']);
	if (isset($params['downloadLimit']))
		$d->setDownloadLimit($hash, $params['downloadLimit']);
	if (isset($params['seedRatioLimit']))
		$d->setShareLimits($hash, (float)$params['seedRatioLimit']);
	if ($startPaused)
		return true;
	if (!$d->start($hash)) {
		del_error("Start failed", $d->lastError);
		return false;
	}
	return true;
}

function stopDelugeTransfer($hash) {
	global $cfg;
	$d = Deluge::getInstance();
	if (!isValidDelugeTransfer($cfg['uid'], $hash))
		return false;
	if (!$d->stop($hash)) {
		del_error("Stop failed", $d->lastError);
		return false;
	}
	return true;
}

function deleteDelugeTransfer($uid, $hash, $deleteData = false) {
	$d = Deluge::getInstance();
	if (isDelugeTransfer($hash)) {
		if (!$d->remove($hash, $deleteData))
			del_error("Delete failed", $d->lastError);
	}
	deleteDelugeTransferFromDB($uid, $hash);
}

// -----------------------------------------------------------------------------
// adoption + refresh (mirrors the qBittorrent / Transmission paths)
// -----------------------------------------------------------------------------

/**
 * Minimal valid-bencode placeholder metafile for adopted torrents (Deluge's
 * RPC does not expose the original .torrent contents).
 */
function delBuildPlaceholderMeta($name, $size, $hash) {
	$name = (string)$name;
	if ($name === '') $name = $hash;
	$size = (int)$size;
	$comment = "FluxTorrent adopted Deluge transfer";
	return 'd7:comment'.strlen($comment).':'.$comment
		.'4:infod6:lengthi'.$size.'e4:name'.strlen($name).':'.$name.'ee';
}

function delAdoptForeignTransfers($uid, $maxAdopt = 25) {
	global $cfg, $db;
	$d = Deluge::getInstance();
	$all = $d->torrents();
	if (!is_array($all) || empty($all))
		return;
	$known = array();
	$recordset = $db->Execute("SELECT hash FROM tf_transfers WHERE hash != ''");
	while (($row = $recordset->FetchRow()) !== false)
		$known[] = strtolower($row[0]);
	$adopted = 0;
	foreach ($all as $hash => $t) {
		$hash = strtolower($hash);
		if (in_array($hash, $known))
			continue;
		if ($adopted >= $maxAdopt)
			break;
		if (!isset($t['totalSize']) || $t['totalSize'] <= 0)
			continue;
		$name = isset($t['name']) ? $t['name'] : $hash;
		$base = preg_replace("/[^0-9a-zA-Z\.\-]+/", '_', function_exists('tfb_clean_accents') ? tfb_clean_accents($name) : $name);
		$base = trim($base, '_');
		if ($base == "")
			$base = $hash;
		$transfer = $base.".torrent";
		$num = 1;
		while (is_file($cfg['transfer_file_path'].$transfer))
			$transfer = $base.'-'.(++$num).".torrent";
		$meta = delBuildPlaceholderMeta($name, $t['totalSize'], $hash);
		if (@file_put_contents($cfg['transfer_file_path'].$transfer, $meta) === false)
			continue;
		$running = $t['running'] ? 1 : 0;
		$savepath = ($cfg["enable_home_dirs"] != 0)
			? $cfg['path'].$cfg['user']."/"
			: $cfg['path'].$cfg["path_incoming"]."/";
		$db->Execute("INSERT INTO tf_transfers (transfer,type,client,hash,savepath,running) VALUES ("
			.$db->qstr($transfer).",'torrent','deluge',".$db->qstr($hash).","
			.$db->qstr($savepath).",".($running ? "'1'" : "'0'").")");
		addDelugeTransferToDB($uid, $hash);
		$sf = new StatFile($transfer, $cfg['user']);
		$sf->size = $t['totalSize'];
		$sf->running = $running;
		$sf->percent_done = round($t['percentDone'] * 100, 2);
		if ($t['status'] == 8 || $t['status'] == 9)
			$sf->time_left = 'Seeding';
		$sf->write();
		AuditAction($cfg["constants"]["fm_download"], "deluge-adopt : ".$transfer." (".$hash.")");
		$adopted++;
	}
}

/**
 * delRefreshAll — sync live state from the Deluge daemon into the stat-files
 * (and adopt foreign transfers). Mirrors qbtRefreshAll(). Throttled 5s/session.
 */
function delRefreshAll() {
	global $cfg;
	if (empty($cfg["deluge_enable"]))
		return;
	$now = time();
	if (isset($_SESSION['del_last_refresh']) && ($now - $_SESSION['del_last_refresh']) < 5)
		return;
	$_SESSION['del_last_refresh'] = $now;
	if (!Deluge::isRunning())
		return;
	require_once('inc/classes/ClientHandler.php');
	$ch = ClientHandler::getInstance('deluge');
	delAdoptForeignTransfers($cfg['uid']);
	$ch->updateStatFiles();
}

?>
