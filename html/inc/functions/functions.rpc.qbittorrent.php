<?php

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
 * qBittorrent helpers, mirroring functions.rpc.transmission.php.
 * User<->torrent ownership is tracked in tf_qbittorrent_user.
 */

require_once('inc/classes/Qbittorrent.class.php');

function qbt_error($errorstr, $response = "") {
	global $cfg;
	AuditAction($cfg["constants"]["error"], "qBittorrent : $errorstr - $response");
	addGrowlMessage('qbittorrent', $errorstr.' '.$response);
}

/**
 * make sure the ownership table exists (idempotent)
 */
function qbtEnsureUserTable() {
	global $db;
	static $done = false;
	if ($done) return;
	$db->Execute("CREATE TABLE IF NOT EXISTS tf_qbittorrent_user ( uid INTEGER NOT NULL default 0, tid VARCHAR(40) NOT NULL default '' )");
	$done = true;
}

/**
 * compute the v1 info-hash of a .torrent file without shelling out.
 * scans the bencoded top-level dictionary for the raw byte range of the
 * "info" value and sha1's it.
 *
 * @return lowercase 40-char hex hash, or "" on failure
 */
function qbt_torrentInfoHash($file) {
	$data = @file_get_contents($file);
	if ($data === false || $data === '' || $data[0] != 'd')
		return "";
	$pos = 1;
	$len = strlen($data);
	$skipValue = function () use (&$pos, $data, $len, &$skipValue) {
		if ($pos >= $len) throw new Exception('truncated');
		$c = $data[$pos];
		if ($c == 'i') {
			$end = strpos($data, 'e', $pos);
			if ($end === false) throw new Exception('bad int');
			$pos = $end + 1;
		} elseif ($c == 'l' || $c == 'd') {
			$pos++;
			while ($pos < $len && $data[$pos] != 'e')
				$skipValue();
			$pos++; // consume 'e'
		} elseif ($c >= '0' && $c <= '9') {
			$colon = strpos($data, ':', $pos);
			if ($colon === false) throw new Exception('bad string');
			$slen = intval(substr($data, $pos, $colon - $pos));
			$pos = $colon + 1 + $slen;
		} else {
			throw new Exception('bad token '.$c);
		}
	};
	try {
		while ($pos < $len && $data[$pos] != 'e') {
			// key (string)
			$colon = strpos($data, ':', $pos);
			if ($colon === false) return "";
			$klen = intval(substr($data, $pos, $colon - $pos));
			$key = substr($data, $colon + 1, $klen);
			$pos = $colon + 1 + $klen;
			// value
			$start = $pos;
			$skipValue();
			if ($key == 'info')
				return strtolower(sha1(substr($data, $start, $pos - $start)));
		}
	} catch (Exception $e) {
		return "";
	}
	return "";
}

/**
 * get one transfer, transmission-style array (or false)
 */
function getQbittorrentTransfer($hash, $options = array()) {
	$qbt = Qbittorrent::getInstance();
	$t = $qbt->torrent($hash);
	if ($t === false)
		return false;
	if (in_array('trackerStats', $options)) {
		$t['trackerStats'] = array();
		$trackers = $qbt->trackers($hash);
		if (is_array($trackers)) {
			foreach ($trackers as $tr) {
				if (isset($tr['status']) && $tr['status'] == 0)
					continue; // tracker-list pseudo entries (DHT/PeX)
				$t['trackerStats'][] = array(
					'host' => isset($tr['url']) ? $tr['url'] : '',
					'announce' => isset($tr['url']) ? $tr['url'] : '',
					'seederCount' => isset($tr['num_seeds']) ? $tr['num_seeds'] : 0,
					'leecherCount' => isset($tr['num_leechs']) ? $tr['num_leechs'] : 0,
					'lastAnnounceResult' => isset($tr['msg']) ? $tr['msg'] : '',
				);
			}
		}
	}
	if (in_array('peers', $options)) {
		$t['peers'] = array();
		foreach ($qbt->peers($hash) as $addr => $p) {
			$t['peers'][] = array(
				'address' => isset($p['ip']) ? $p['ip'] : $addr,
				'port' => isset($p['port']) ? $p['port'] : 0,
				'clientName' => isset($p['client']) ? $p['client'] : '',
				'progress' => isset($p['progress']) ? $p['progress'] : 0,
				'rateToClient' => isset($p['dl_speed']) ? $p['dl_speed'] : 0,
				'rateToPeer' => isset($p['up_speed']) ? $p['up_speed'] : 0,
				'flagStr' => isset($p['flags']) ? $p['flags'] : '',
				'isEncrypted' => false,
				'country' => isset($p['country']) ? $p['country'] : '',
			);
		}
	}
	if (in_array('files', $options)) {
		$t['files'] = array();
		$t['fileStats'] = array();
		$files = $qbt->files($hash);
		if (is_array($files)) {
			foreach ($files as $f) {
				$t['files'][] = array(
					'name' => isset($f['name']) ? $f['name'] : '',
					'length' => isset($f['size']) ? $f['size'] : 0,
					'bytesCompleted' => isset($f['size'], $f['progress']) ? intval($f['size'] * $f['progress']) : 0,
				);
				$t['fileStats'][] = array(
					'wanted' => (isset($f['priority']) && $f['priority'] != 0),
					'priority' => isset($f['priority']) ? $f['priority'] : 1,
					'bytesCompleted' => isset($f['size'], $f['progress']) ? intval($f['size'] * $f['progress']) : 0,
				);
			}
		}
	}
	return $t;
}

/**
 * is this hash known to qBittorrent?
 */
function isQbittorrentTransfer($hash) {
	if (empty($hash) || !Qbittorrent::isRunning())
		return false;
	return (Qbittorrent::getInstance()->torrent($hash) !== false);
}

function isQbittorrentTransferRunning($hash) {
	$t = Qbittorrent::getInstance()->torrent($hash);
	return (is_array($t) && $t['running'] == 1);
}

function getRunningQbittorrentTransferCount() {
	$list = Qbittorrent::getInstance()->torrents();
	if ($list === false)
		return 0;
	$count = 0;
	foreach ($list as $t)
		if ($t['running'] == 1) $count++;
	return $count;
}

/**
 * hashes owned by a user
 */
function getUserQbittorrentTransferArrayFromDB($uid = 0) {
	global $db;
	qbtEnsureUserTable();
	$retVal = array();
	$uid = (int)$uid;
	$sql = "SELECT tid FROM tf_qbittorrent_user WHERE uid=$uid";
	$recordset = $db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	while (($row = $recordset->FetchRow()) !== false)
		$retVal[] = strtolower($row[0]);
	return $retVal;
}

function isValidQbittorrentTransfer($uid, $hash) {
	return isQbittorrentTransfer($hash);
}

function getQbittorrentTransferOwner($hash) {
	global $db;
	qbtEnsureUserTable();
	$uid = (int)$db->GetOne("SELECT uid FROM tf_qbittorrent_user WHERE tid = ".$db->qstr(strtolower($hash)));
	return GetUsername($uid);
}

/**
 * start (resume) a transfer, applying optional limits
 *
 * @param $params array: uploadLimit/downloadLimit (bytes/s), seedRatioLimit
 */
function startQbittorrentTransfer($hash, $startPaused = false, $params = array()) {
	global $cfg;
	$qbt = Qbittorrent::getInstance();
	if (!isValidQbittorrentTransfer($cfg['uid'], $hash)) {
		qbt_error("startQbittorrentTransfer : unknown transfer hash=$hash");
		return false;
	}
	if (isset($params['uploadLimit']))
		$qbt->setUploadLimit($hash, $params['uploadLimit']);
	if (isset($params['downloadLimit']))
		$qbt->setDownloadLimit($hash, $params['downloadLimit']);
	if (isset($params['seedRatioLimit']) && $params['seedRatioLimit'] > 0)
		$qbt->setShareLimits($hash, (float)$params['seedRatioLimit']);
	if ($startPaused)
		return true;
	if (!$qbt->start($hash)) {
		qbt_error("Start failed", $qbt->lastError);
		return false;
	}
	return true;
}

function stopQbittorrentTransfer($hash) {
	global $cfg;
	$qbt = Qbittorrent::getInstance();
	if (!isValidQbittorrentTransfer($cfg['uid'], $hash))
		return false;
	if (!$qbt->stop($hash)) {
		qbt_error("Stop failed", $qbt->lastError);
		return false;
	}
	return true;
}

function stopQbittorrentTransferCron($hash) {
	$qbt = Qbittorrent::getInstance();
	if (!$qbt->stop($hash)) {
		echo("Stop failed : ".$qbt->lastError);
		return false;
	}
	return true;
}

function deleteQbittorrentTransfer($uid, $hash, $deleteData = false) {
	$qbt = Qbittorrent::getInstance();
	if (isQbittorrentTransfer($hash)) {
		if (!$qbt->remove($hash, $deleteData))
			qbt_error("Delete failed", $qbt->lastError);
	}
	deleteQbittorrentTransferFromDB($uid, $hash);
}

function deleteQbittorrentTransferWithData($uid, $hash) {
	deleteQbittorrentTransfer($uid, $hash, true);
}

function deleteQbittorrentTransferFromDB($uid, $tid) {
	global $db;
	qbtEnsureUserTable();
	$uid = (int)$uid;
	$sql = "DELETE FROM tf_qbittorrent_user WHERE uid=$uid AND tid=".$db->qstr(strtolower($tid));
	$db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
}

function addQbittorrentTransferToDB($uid, $tid) {
	global $db;
	qbtEnsureUserTable();
	$uid = (int)$uid;
	$tid = strtolower($tid);
	$db->Execute("DELETE FROM tf_qbittorrent_user WHERE uid=$uid AND tid=".$db->qstr($tid));
	$sql = "INSERT INTO tf_qbittorrent_user (uid,tid) VALUES ($uid,".$db->qstr($tid).")";
	$db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
}

/**
 * add a transfer (local .torrent path, magnet or http url) to qBittorrent.
 *
 * @return lowercase hash on success, or array("result" => <error>) like the
 *         transmission counterpart on failure
 */
function addQbittorrentTransfer($uid, $fileOrUrl, $path, $paused = false) {
	$qbt = Qbittorrent::getInstance();

	// figure out the hash up-front where possible
	$hash = "";
	if (preg_match('/urn:btih:([a-fA-F0-9]{40})/', $fileOrUrl, $m))
		$hash = strtolower($m[1]);
	elseif (!preg_match('#^(https?|magnet):#i', $fileOrUrl))
		$hash = qbt_torrentInfoHash($fileOrUrl);

	if ($hash != "" && $qbt->torrent($hash) !== false) {
		addQbittorrentTransferToDB($uid, $hash); // adopt
		return array("result" => "duplicate torrent");
	}

	if (!$qbt->add($fileOrUrl, $path, $paused))
		return array("result" => $qbt->lastError);

	if ($hash == "") {
		// hash unknown (magnet without btih / URL): find the newest torrent
		for ($i = 0; $i < 10 && $hash == ""; $i++) {
			usleep(500000);
			$list = $qbt->torrents();
			if (is_array($list)) {
				$known = getUserQbittorrentTransferArrayFromDB($uid);
				$newest = null;
				foreach ($list as $h => $t) {
					if (!in_array($h, $known))
						$newest = $h;
				}
				if ($newest !== null)
					$hash = $newest;
			}
		}
	} else {
		// wait for qBittorrent to register it
		for ($i = 0; $i < 10; $i++) {
			if ($qbt->torrent($hash) !== false)
				break;
			usleep(500000);
		}
	}

	if (isHash($hash))
		addQbittorrentTransferToDB($uid, $hash);

	return $hash;
}

/**
 * all (or one user's) transfers, keyed by hash, transmission-style
 */
function getUserQbittorrentTransfers($uid = 0) {
	$retVal = array();
	if ($uid != 0) {
		$userTransferHashes = getUserQbittorrentTransferArrayFromDB($uid);
		if (empty($userTransferHashes)) return $retVal;
	}
	$qbt = Qbittorrent::getInstance();
	$list = $qbt->torrents();
	if ($list === false) {
		qbt_error("qBittorrent could not get transfers", $qbt->lastError);
		return $retVal;
	}
	foreach ($list as $hash => $t) {
		if ($uid == 0 || in_array($hash, $userTransferHashes))
			$retVal[$hash] = $t;
	}
	return $retVal;
}

/**
 * adopt daemon transfers that have no TorrentFlux transfer entry yet
 * (typically magnet adds): once qBittorrent has the metadata, export the
 * .torrent into transfer_file_path and register it in tf_transfers so the
 * normal file-based UI picks it up.
 */
function qbtAdoptForeignTransfers($uid) {
	global $cfg, $db;
	$hashes = getUserQbittorrentTransferArrayFromDB($uid);
	if (empty($hashes))
		return;
	// hashes already known to tf_transfers
	$known = array();
	$recordset = $db->Execute("SELECT hash FROM tf_transfers WHERE hash != ''");
	while (($row = $recordset->FetchRow()) !== false)
		$known[] = strtolower($row[0]);
	$qbt = Qbittorrent::getInstance();
	foreach ($hashes as $hash) {
		if (in_array($hash, $known))
			continue;
		$t = $qbt->torrent($hash);
		if ($t === false || $t['totalSize'] <= 0 || $t['state'] == 'metaDL')
			continue; // still fetching metadata
		$raw = $qbt->export($hash);
		if ($raw === false)
			continue;
		// build a unique transfer file name
		$base = tfb_cleanFileName($t['name']);
		if ($base == "" || $base === false)
			$base = $hash;
		$transfer = $base.".torrent";
		$num = 1;
		while (is_file($cfg['transfer_file_path'].$transfer))
			$transfer = $base.'-'.(++$num).".torrent";
		if (@file_put_contents($cfg['transfer_file_path'].$transfer, $raw) === false)
			continue;
		// register
		$savepath = ($cfg["enable_home_dirs"] != 0)
			? $cfg['path'].$cfg['user']."/"
			: $cfg['path'].$cfg["path_incoming"]."/";
		$db->Execute("INSERT INTO tf_transfers (transfer,type,client,hash,savepath,running) VALUES ("
			.$db->qstr($transfer).",'torrent','qbittorrent',".$db->qstr($hash).","
			.$db->qstr($savepath).",".($t['running'] ? "'1'" : "'0'").")");
		// seed the stat file so the list shows something sensible
		$sf = new StatFile($transfer, $cfg['user']);
		$sf->size = $t['totalSize'];
		$sf->running = $t['running'];
		$sf->percent_done = round($t['percentDone'] * 100, 2);
		$sf->write();
		AuditAction($cfg["constants"]["fm_download"], "qbittorrent-adopt : ".$transfer." (".$hash.")");
	}
}

/**
 * refresh all qbittorrent stat-files + adopt magnet adds; throttled per
 * session so rapid page loads don't hammer the API.
 */
function qbtRefreshAll() {
	global $cfg;
	if (empty($cfg["qbittorrent_enable"]))
		return;
	$now = time();
	if (isset($_SESSION['qbt_last_refresh']) && ($now - $_SESSION['qbt_last_refresh']) < 5)
		return;
	$_SESSION['qbt_last_refresh'] = $now;
	if (!Qbittorrent::isRunning())
		return;
	require_once('inc/classes/ClientHandler.php');
	$ch = ClientHandler::getInstance('qbittorrent');
	qbtAdoptForeignTransfers($cfg['uid']);
	$ch->updateStatFiles();
}

//used in iid/index
function getQbittorrentStatusImage($t) {
	if ($t['running'] == 1) {
		if ($t['status'] == 8 || $t['status'] == 9)
			return "yellow.gif"; // seeding
		if ($t['peersSendingToUs'] > 0)
			return "green.gif";  // downloading with seeds
		return "black.gif";
	}
	return ($t['percentDone'] >= 1.0) ? "black.gif" : "red.gif";
}

?>
