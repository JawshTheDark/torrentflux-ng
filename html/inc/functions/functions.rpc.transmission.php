<?php
/*******************************************************************************
 $Id$
 @package Transmission
 @licence http://www.gnu.org/copyleft/gpl.html

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License (GPL)
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

*******************************************************************************/

function rpc_error($errorstr,$dummy1="",$dummy2="",$response="") {
	global $cfg;
	AuditAction($cfg["constants"]["error"], "Transmission RPC : $errorstr - $response");
	@error($errorstr."\n".$response, "", "", $response, $response);
	addGrowlMessage('transmission-rpc',$errorstr.$response);
	//dbError($errorstr);
}

/**
 * get one Transmission transfer data array
 *
 * @param $transfer hash of the transfer
 * @param $fields array of fields needed
 * @return array or false
 */
function getTransmissionTransfer($transfer, $fields=array() ) {
	//$fields = array("id", "name", "eta", "downloadedEver", "hashString", "fileStats", "totalSize", "percentDone",
	//			"metadataPercentComplete", "rateDownload", "rateUpload", "status", "files", "trackerStats", "uploadedEver" )
	$required = array('hashString');
	$afields = array_merge($required, $fields);

	require_once('inc/classes/Transmission.class.php');
	$rpc = Transmission::getInstance();
	$response = $rpc->get(array(), $afields);
	$torrentlist = $response['arguments']['torrents'];

	if (!empty($torrentlist)) {
		foreach ($torrentlist as $aTorrent) {
			if ( $aTorrent['hashString'] == $transfer )
				return $aTorrent;
		}
	}
	return false;
}

/**
 * set a property for a Transmission transfer identified by hash
 *
 * @param $transfer hash of the transfer
 * @param array of properties to set
 **/
function setTransmissionTransferProperties($transfer, $fields=array()) {
	require_once('inc/classes/Transmission.class.php');
	$rpc = Transmission::getInstance();
	$transferId = getTransmissionTransferIdByHash($transfer);

	$response = $rpc->set($transferId, $fields);
	if ( $response['result'] !== 'success' )
		rpc_error("Setting transfer properties failed", "", "", $response['result']);
}


/**
 * checks if transfer is running
 *
 * @param $transfer hash of the transfer
 * @return boolean
 */
function isTransmissionTransferRunning($transfer) {
	$aTorrent = getTransmissionTransfer($transfer, array('status'));
	if (is_array($aTorrent)) {
		return ( $aTorrent['status'] != 16 );
	}
	return false;
}

/**
 * checks if transfer is Transmission
 *
 * @param $transfer hash of the transfer
 * @return boolean
 */
function isTransmissionTransfer($transfer) {
	$aTorrent = getTransmissionTransfer($transfer);
	return is_array($aTorrent);
}

/**
 * getRunningTransmissionTransferCount
 *
 * @return int with number of running transfers for transmission daemon
 * TODO: make it return a correct value
 */
function getRunningTransmissionTransferCount() {
	$result = getUserTransmissionTransfers(0);
	$count = 0;

	// Note that this also counts the downloads that are not added through torrentflux
	foreach ($result as $aTorrent) {
		if ( $aTorrent['status']==4 || $aTorrent['status']==8 ) $count++;
	}
	return $count;
}

/**
 * This method gets Transmission transfers from a certain user from database in an array
 *
 * @return array with uid and transmission transfer hash
 */
function getUserTransmissionTransferArrayFromDB($uid = 0) {
	global $cfg,$db;

	$retVal = array();

	if ($cfg["transmission_rpc_enable"] == 2) {
		$sql = "SELECT tid FROM tf_transmission_user" . ($uid!=0 ? ' WHERE uid=' . $uid : '' );
		$recordset = $db->Execute($sql);
		if ($db->ErrorNo() != 0) dbError($sql);
		while (($__row = $recordset->FetchRow()) !== false) { list($transfer) = $__row;
			$retVal[$transfer]=$transfer;
		}
	}

	if ($cfg["transmission_rpc_enable"] == 1) {
		$sql = "SELECT T.hash, T.transfer FROM tf_transfers T"
		      ." LEFT JOIN tf_transfer_totals TT ON (TT.tid = T.hash)"
		      ." WHERE T.type='torrent' AND T.client='transmissionrpc'"
		      . ($uid!=0 ? ' AND TT.uid=' . $uid : '' );
		$recordset = $db->Execute($sql);
		if ($db->ErrorNo() != 0) dbError($sql);
		while (($__row = $recordset->FetchRow()) !== false) { list($hash, $transfer) = $__row;
			$retVal[$hash]=$transfer;
		}
	}

	return $retVal;
}

/**
 * This method checks if a certain transfer is existing and from the same user
 *
 * @return array with uid and transmission transfer hash
 * TODO: check if $tid is filled in and return error
 * TODO: check that uid being zero cannot lead to security breach (information disclosure)
 */
function isValidTransmissionTransfer($uid = 0,$tid) {
	global $db;
	$retVal = array();
	$sql = "SELECT tid FROM tf_transmission_user WHERE tid='$tid' AND uid='$uid' "
	//." UNION "
	//." SELECT tid FROM tf_transfer_totals WHERE tid=".$db->qstr($tid)." AND uid=".$db->qstr($uid)
	;
	$recordset = $db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	return ($recordset && !$recordset->EOF);
}

/**
 * This method returns the owner name of a certain transmission transfer
 *
 * @return string with owner of transmission transfer
 */
function getTransmissionTransferOwner($transfer) {
	global $db;
	$retVal = array();
	$sql = "SELECT user_id FROM tf_users u join tf_transmission_user t on (t.uid = u.uid) WHERE t.tid = '$transfer';";
	$recordset = $db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	if ( $recordset && !$recordset->EOF ) {
		$row = $recordset->FetchRow();
		return $row['user_id'];
	}
	else return "Unknown";
}

/**
 * This method starts the Transmission transfer with the matching hash
 *
 * @return void
 */
function startTransmissionTransfer($hash,$startPaused=false,$params=array()) {
	global $cfg;
	require_once('inc/classes/Transmission.class.php');
	$rpc = Transmission::getInstance();

	if ( isValidTransmissionTransfer($cfg['uid'],$hash) ) {
		$transmissionId = getTransmissionTransferIdByHash($hash);
		$response = $rpc->set($transmissionId, array_merge(array("seedRatioMode" => 1), $params) );
		$response = $rpc->start($transmissionId);
		if ( $response['result'] != "success" ) {
			rpc_error("Start failed", "", "", $response['result']);
			return false;
		}
		return true;
	} else {
		rpc_error("startTransmissionTransfer : Not ValidTransmissionTransfer hash=$hash ");
		return false;
	}
}

/**
 * This method stops the Transmission transfer with the matching hash
 *
 * @return boolean
 */
function stopTransmissionTransfer($hash) {
	global $cfg;
	require_once('inc/classes/Transmission.class.php');
	$rpc = Transmission::getInstance();

	if ( isValidTransmissionTransfer($cfg['uid'],$hash) ) {
		$transmissionId = getTransmissionTransferIdByHash($hash);
		$response = $rpc->stop($transmissionId);
		if ( $response['result'] != "success" ) rpc_error("Stop failed", "", "", $response['result']);
		return true;
	}
	return false;
}

/**
 * This method stops the Transmission transfer with the matching hash
 *
 * @return boolean
 */
function stopTransmissionTransferCron($hash) {
	require_once('inc/classes/Transmission.class.php');
	$rpc = Transmission::getInstance();

	$transmissionId = getTransmissionTransferIdByHash($hash);
	$response = $rpc->stop($transmissionId);
	if ( $response['result'] != "success" ) {
		echo("Stop failed :". $response['result']);
		return false;
	}
	return true;
}

/**
 * This method deletes the Transmission transfer with the matching hash, without removing the data
 *
 * @return void
 * TODO: test delete :)
 */
function deleteTransmissionTransfer($uid, $hash, $deleteData = false) {
	require_once('inc/classes/Transmission.class.php');
	$rpc = Transmission::getInstance();

	if ( isValidTransmissionTransfer($uid, $hash) ) {
		$transmissionId = getTransmissionTransferIdByHash($hash);
		$response = $rpc->remove($transmissionId,$deleteData);
		if ( $response['result'] != "success" )
			rpc_error("Delete failed", "", "", $response['result']);
	}

	deleteTransmissionTransferFromDB($uid, $hash);
}

/**
 * This method deletes the Transmission transfer with the matching hash, and its data
 *
 * @return void
 * TODO: test delete :)
 */
function deleteTransmissionTransferWithData($uid, $hash) {
	deleteTransmissionTransfer($uid, $hash, true);
}

/**
 * This method retrieves the current ID in transmission for the transfer that matches the $hash hash
 *
 * @return transmissionTransferId
 */
function getTransmissionTransferIdByHash($hash) {
	require_once('inc/classes/Transmission.class.php');
	$transmissionTransferId = false;
	$rpc = Transmission::getInstance();
	$response = $rpc->get(array(), array('id','hashString'));
	if ( $response['result'] != "success" ) rpc_error("Getting ID for Hash failed: ".$response['result']);
	$torrentlist = $response['arguments']['torrents'];
	foreach ($torrentlist as $aTorrent) {
		if ( $aTorrent['hashString'] == $hash ) {
			$transmissionTransferId = $aTorrent['id'];
			break;
		}
	}
	return $transmissionTransferId;
}

/**
 * This method deletes a Transmission transfer for a certain user from the database
 *
 * @return void
 * TODO: return error if deletion from db does fail
 */
function deleteTransmissionTransferFromDB($uid = 0,$tid) {
	global $db;
	$retVal = array();
	$sql = "DELETE FROM tf_transmission_user WHERE uid='$uid' AND tid='$tid'";
	$recordset = $db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	/*return $retVal;*/
}

/**
 * This method adds a Transmission transfer for a certain user in database
 *
 * @return array with uid and transmission transfer hash
 * TODO: check if $tid is filled in and return error
 */
function addTransmissionTransferToDB($uid = 0,$tid) {
	global $db;
	$retVal = array();
	$uid = (int) $uid;
	$sql = "DELETE FROM tf_transmission_user WHERE uid=$uid AND tid='$tid'";
	$recordset = $db->Execute($sql);
	$sql = "INSERT INTO tf_transmission_user (uid,tid) VALUES ($uid,'$tid')";
	$recordset = $db->Execute($sql);
	if ($db->ErrorNo() != 0) dbError($sql);
	/*return $retVal;*/
}

/**
 * This method adds a Transmission transfer to transmission-daemon
 *
 * @return array with uid and transmission transfer hash
 * TODO: generate an error when adding does fail
 */
function addTransmissionTransfer($uid = 0, $url, $path, $paused=true) {
	// $path holds the download path

	require_once('inc/classes/Transmission.class.php');
	$rpc = Transmission::getInstance();

	$result = $rpc->add( $url, $path, array ('paused' => $paused)  );
	if($result["result"]!=="success") {
		//rpc_error("addTransmissionTransfer","","",$result['result']. " url=$url");
		return $result;
	}

	// Transmission answers a fresh add with "torrent-added" and an add of an
	// already-present torrent with "torrent-duplicate"; both carry the hash.
	$args = isset($result['arguments']) ? $result['arguments'] : array();
	if (isset($args['torrent-added']['hashString']))
		$hash = $args['torrent-added']['hashString'];
	else if (isset($args['torrent-duplicate']['hashString']))
		$hash = $args['torrent-duplicate']['hashString'];
	else
		return $result;

	if (isHash($hash))
		addTransmissionTransferToDB($uid, $hash);

	return $hash;
}

/**
 * This method adds a Transmission transfer for a certain user in database
 *
 * @return array with uid and transmission transfer hash
 */
function getUserTransmissionTransfers($uid = 0) {
	$retVal = array();
	if ( $uid!=0 ) {
		$userTransferHashes = getUserTransmissionTransferArrayFromDB($uid);
		if ( empty($userTransferHashes) ) return $retVal;
	}

	require_once('inc/classes/Transmission.class.php');
	$rpc = Transmission::getInstance();

	// https://trac.transmissionbt.com/browser/trunk/extras/rpc-spec.txt
	$fields = array (
	"name", "id", "hashString", "eta", "totalSize", "percentDone", "metadataPercentComplete",
	"peersConnected", 'peersGettingFromUs', 'peersSendingToUs', "rateDownload", "rateUpload", "status", 
	"uploadLimit", "uploadRatio", "seedRatioLimit", "seedRatioMode", 
	"downloadedEver", "uploadedEver", "error", "errorString",
//	"trackerStats", "files", "fileStats" slow down
	);

	$result = $rpc->get( array(), $fields );

	if ($result['result']!=="success") rpc_error("Transmission RPC could not get transfers : ".$result['result']);
	foreach ( $result['arguments']['torrents'] as $transfer ) {
		if ( $uid==0 || in_array($transfer['hashString'], $userTransferHashes) ) {
			$rpcStatus = $transfer['status'];
			$transfer['status'] = Transmission::status_compat($rpcStatus);
			//set array keys as hashes
			$retVal[$transfer['hashString']] = $transfer;
		}
	}
	return $retVal;
}

//used in iid/index
function getTransmissionStatusImage($running, $seederCount, $uploadRate){
	$statusImage = "black.gif";
	if ($running) {
		// running
		if ($seederCount < 2)
				$statusImage = "yellow.gif";
		if ($seederCount == 0)
				$statusImage = "red.gif";
		if ($seederCount >= 2)
				$statusImage = "green.gif";
	}
	if ( floor($aTorrent[percentDone]*100) >= 100 ) {
		$statusImage = ( $uploadRate != 0 && $running )
						? "green.gif" /* seeding */
						: "black.gif"; /* finished */
	}
	return $statusImage;
}

function getTransmissionSeederCount($transfer) {
	$options = array('trackerStats');
	$transfer = getTransmissionTransfer($transfer, $options);
	foreach ( $transfer['trackerStats'] as $tracker ) {
		$seeds += ($tracker['seederCount']==-1 ? 0 : $tracker['seederCount']);
		//$announceResult = $tracker['lastAnnounceResult'];
	}
	return $seeds;
}

function getTransmissionTrackerStats($transfer) {
	$options = array('trackerStats');
	$transfer = getTransmissionTransfer($transfer, $options);
	if (is_array($transfer))
		return $transfer['trackerStats'][0];
	else
		return array();
}

/**
 * get Default ShareKill value
 *
 * @return int
 */
function getTransmissionShareKill($usecache=false) {
	require_once('inc/classes/Transmission.class.php');
	$rpc = Transmission::getInstance();

	$req = $rpc->session_get('seedRatioLimit');
	if (is_array($req) && isset($req['arguments']['seedRatioLimit'])) {
		return round($req['arguments']['seedRatioLimit'] * 100.0);
	}

	return 0;
}

/**
 * get Global Speed Limit Upload
 *
 * @return int
 */
function getTransmissionSpeedLimitUpload($usecache=false) {
	require_once('inc/classes/Transmission.class.php');
	$rpc = Transmission::getInstance();

	$key = 'speed-limit-up'; //"speed-limit-up-enabled"

	$req = $rpc->session_get($key);
	if (is_array($req) && isset($req['arguments'][$key])) {
		return (int) $req['arguments'][$key];
	}

	return 0;
}

/**
 * get Vuze Global Speed Limit Download
 *
 * @return int
 */
function getTransmissionSpeedLimitDownload($usecache=false) {
	require_once('inc/classes/Transmission.class.php');
	$rpc = Transmission::getInstance();

	$key = 'speed-limit-down'; //"speed-limit-down-enabled"

	$req = $rpc->session_get($key);
	if (is_array($req) && isset($req['arguments'][$key])) {
		return (int) $req['arguments'][$key];
	}

	return 0;
}

/**
 * trmBuildPlaceholderMeta — build a minimal, valid bencoded .torrent metafile
 * for an adopted Transmission torrent. Transmission's RPC does not expose the
 * original .torrent contents, so we cannot reproduce the real metainfo (piece
 * hashes are unavailable). This placeholder carries the name and length so the
 * file-driven transfer list has an entry; live details come from the daemon.
 */
function trmBuildPlaceholderMeta($name, $size, $hash) {
	$name = (string)$name;
	if ($name === '') $name = $hash;
	$size = (int)$size;
	$comment = "FluxTorrent adopted Transmission transfer";
	// bencode: d 7:comment <c> 4:info d 6:length i<size>e 4:name <name> e e
	return 'd7:comment'.strlen($comment).':'.$comment
		.'4:infod6:lengthi'.$size.'e4:name'.strlen($name).':'.$name.'ee';
}

/**
 * trmAdoptForeignTransfers — import Transmission daemon torrents that are not
 * yet tracked by FluxTorrent (e.g. magnet adds, or torrents added directly in
 * Transmission) so they appear in the transfer list and can be controlled.
 * Mirrors qbtAdoptForeignTransfers().
 */
function trmAdoptForeignTransfers($uid, $maxAdopt = 25) {
	global $cfg, $db;
	$all = getUserTransmissionTransfers(); // all daemon torrents, keyed by hashString
	if (!is_array($all) || empty($all))
		return;
	// hashes already known to tf_transfers
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
		// skip torrents still fetching metadata (no size yet)
		if (!isset($t['totalSize']) || $t['totalSize'] <= 0)
			continue;
		$name = isset($t['name']) ? $t['name'] : $hash;
		// build a unique transfer file name from the torrent name
		$base = preg_replace("/[^0-9a-zA-Z\.\-]+/", '_', function_exists('tfb_clean_accents') ? tfb_clean_accents($name) : $name);
		$base = trim($base, '_');
		if ($base == "")
			$base = $hash;
		$transfer = $base.".torrent";
		$num = 1;
		while (is_file($cfg['transfer_file_path'].$transfer))
			$transfer = $base.'-'.(++$num).".torrent";
		$meta = trmBuildPlaceholderMeta($name, $t['totalSize'], $hash);
		if (@file_put_contents($cfg['transfer_file_path'].$transfer, $meta) === false)
			continue;
		$running = ($t['status'] == 4 || $t['status'] == 8 || $t['status'] == 9) ? 1 : 0;
		$savepath = ($cfg["enable_home_dirs"] != 0)
			? $cfg['path'].$cfg['user']."/"
			: $cfg['path'].$cfg["path_incoming"]."/";
		$db->Execute("INSERT INTO tf_transfers (transfer,type,client,hash,savepath,running) VALUES ("
			.$db->qstr($transfer).",'torrent','transmissionrpc',".$db->qstr($hash).","
			.$db->qstr($savepath).",".($running ? "'1'" : "'0'").")");
		// claim ownership for the adopting user
		addTransmissionTransferToDB($uid, $hash);
		// seed the stat file so the list shows something sensible
		$sf = new StatFile($transfer, $cfg['user']);
		$sf->size = $t['totalSize'];
		$sf->running = $running;
		$sf->percent_done = round($t['percentDone'] * 100, 2);
		if ($t['status'] == 8 || $t['status'] == 9)
			$sf->time_left = 'Seeding';
		$sf->write();
		AuditAction($cfg["constants"]["fm_download"], "transmissionrpc-adopt : ".$transfer." (".$hash.")");
		$adopted++;
	}
}

/**
 * trmRefreshAll — sync live state from the Transmission daemon into the
 * FluxTorrent stat-files (and enforce sharekill). Mirrors qbtRefreshAll().
 * Throttled to once every 5s per session.
 */
function trmRefreshAll() {
	global $cfg;
	if (empty($cfg["transmission_rpc_enable"]))
		return;
	$now = time();
	if (isset($_SESSION['trm_last_refresh']) && ($now - $_SESSION['trm_last_refresh']) < 5)
		return;
	$_SESSION['trm_last_refresh'] = $now;
	require_once('inc/classes/Transmission.class.php');
	if (!Transmission::isRunning())
		return;
	require_once('inc/classes/ClientHandler.php');
	$ch = ClientHandler::getInstance('transmissionrpc');
	trmAdoptForeignTransfers($cfg['uid']);
	$ch->updateStatFiles();
}

?>
