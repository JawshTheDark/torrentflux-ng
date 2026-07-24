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

require_once("inc/functions/functions.core.php");
require_once("inc/classes/Qbittorrent.class.php");
require_once("inc/functions/functions.rpc.qbittorrent.php");

/**
 * ClientHandler for the qBittorrent Web API (modeled on the
 * transmission-daemon RPC handler).
 */
class ClientHandlerQbittorrent extends ClientHandler
{

	// =========================================================================
	// constructor
	// =========================================================================

	public function __construct() {
		$this->type = "torrent";
		$this->client = "qbittorrent";

		$this->binSocket = "qbittorrent-nox"; //for ps grep
		$this->binSystem = "qbittorrent-nox";
		$this->binClient = "qbittorrent-nox";

		$this->useRPC = true;
	}

	// =========================================================================
	// public methods
	// =========================================================================

	/**
	 * starts a transfer
	 *
	 * @param $transfer name of the transfer
	 * @param $interactive (boolean) : is this a interactive startup with dialog ?
	 * @param $enqueue (boolean) : enqueue ?
	 */
	function start($transfer, $interactive = false, $enqueue = false) {
		global $cfg, $db;

		// set vars
		$this->_setVarsForTransfer($transfer);
		addGrowlMessage($this->client."-start", $transfer);

		if (!Qbittorrent::isRunning()) {
			$qbt = Qbittorrent::getInstance();
			$msg = "qBittorrent not reachable, cannot start transfer ".$transfer." : ".$qbt->lastError;
			$this->logMessage($this->client."-start : ".$msg."\n", true);
			AuditAction($cfg["constants"]["error"], $msg);
			addGrowlMessage($this->client."-start", $msg);

			// write error to stat
			$sf = new StatFile($this->transfer, $this->owner);
			$sf->time_left = 'Error: API down';
			$sf->write();

			return false;
		}

		// init properties
		$this->_init($interactive, $enqueue, true, false);

		if (!is_dir($cfg['path'].$cfg['user']))
			mkdir($cfg['path'].$cfg['user'], 0777);

		$this->command = "";
		if (getOwner($transfer) != $cfg['user']) {
			changeOwner($transfer, $cfg['user']);
			$this->owner = $cfg['user'];
			$this->savepath = ($cfg["enable_home_dirs"] != 0)
				? $cfg['path'].$this->owner."/"
				: $cfg['path'].$cfg["path_incoming"]."/";
			$this->command = "re-downloading to ".$this->savepath;
		} else {
			$this->savepath = ($cfg["enable_home_dirs"] != 0)
				? $cfg['path'].$cfg['user']."/"
				: $cfg['path'].$cfg["path_incoming"]."/";
			$this->command = "downloading to ".$this->savepath;
		}

		// no client process needed
		$this->state = CLIENTHANDLER_STATE_READY;

		// ClientHandler _start()
		$this->_start();

		$hash = getTransferHash($transfer);

		if (empty($hash) || !isQbittorrentTransfer($hash)) {
			$hash = addQbittorrentTransfer($cfg['uid'], $cfg['transfer_file_path'].$transfer, $this->savepath);
			if (is_array($hash) && $hash["result"] == "duplicate torrent") {
				$this->command = 'torrent-add skipped, already exists '.$transfer;
				$hash = "";
				$sql = "SELECT hash FROM tf_transfers WHERE transfer = ".$db->qstr($transfer);
				$result = $db->Execute($sql);
				$row = $result->FetchRow();
				if (!empty($row))
					$hash = $row['hash'];
			} elseif (is_array($hash)) {
				// add failed
				$msg = "qbittorrent-add failed for ".$transfer." : ".$hash["result"];
				AuditAction($cfg["constants"]["error"], $msg);
				$this->logMessage($msg."\n", true);
				addGrowlMessage($this->client."-start", $msg);
				$hash = "";
			} else {
				$this->command .= "\n".'torrent-add '.$transfer.' '.$hash;
			}
		} else {
			$this->command .= "\n".'torrent-start '.$transfer.' '.$hash;
		}

		$res = 0;
		if (!empty($hash)) {
			if ($this->sharekill > 100) {
				// legacy percent notation: 250 means ratio 2.5
				$this->sharekill = round((float)$this->sharekill / 100.0, 2);
			}
			$params = array(
				'downloadLimit' => intval($this->drate) * 1024, // KiB/s -> bytes/s
				'uploadLimit'   => intval($this->rate) * 1024,
				'seedRatioLimit'=> (float)$this->sharekill,
			);
			$res = (int)startQbittorrentTransfer($hash, $enqueue, $params);
			// persist hash for this transfer
			$sql = "UPDATE tf_transfers SET hash=".$db->qstr(strtolower($hash))." WHERE transfer=".$db->qstr($transfer);
			$db->Execute($sql);
		}
		if (!$res) {
			$qbt = Qbittorrent::getInstance();
			$this->command .= "\n".$qbt->lastError;
		}

		$this->updateStatFiles($transfer);

		// log (for the torrent stats window)
		$this->logMessage($this->client."-start : hash=$hash\ndownload rate=".$this->drate.", res=$res\n", true);
	}

	/**
	 * stops a transfer
	 *
	 * @param $transfer name of the transfer
	 * @param $kill kill-param (optional)
	 * @param $transferPid transfer Pid (optional)
	 */
	function stop($transfer, $kill = false, $transferPid = 0) {
		global $cfg;

		// set vars
		$this->_setVarsForTransfer($transfer);

		// log
		$this->logMessage($this->client."-stop : ".$transfer."\n", true);

		if (!Qbittorrent::isRunning()) {
			array_push($this->messages, "qBittorrent not reachable, cannot stop transfer ".$transfer);
			return false;
		}

		$hash = getTransferHash($transfer);
		if (empty($hash)) {
			//not in db, clean it
			@unlink($this->transferFilePath.".pid");
			AuditAction($cfg["constants"]["debug"], $this->client."-stop : $transfer not in db, cleaning...");
			$this->delete($transfer);
			return true;
		}

		$this->updateStatFiles($transfer); //update before stopping

		if (!stopQbittorrentTransfer($hash)) {
			$qbt = Qbittorrent::getInstance();
			$msg = $transfer." : ".$qbt->lastError;
			$this->logMessage($msg."\n", true);
			AuditAction($cfg["constants"]["debug"], $this->client."-stop : error $msg.");
		}

		// delete .pid
		$this->_stop($kill, $transferPid);

		// set .stat stopped
		$this->cleanStoppedStatFile($transfer);
	}

	/**
	 * deletes a transfer
	 *
	 * @param $transfer name of the transfer
	 * @return boolean of success
	 */
	function delete($transfer) {
		global $cfg;

		// set vars
		$this->_setVarsForTransfer($transfer);

		// log
		$this->logMessage($this->client."-delete : ".$transfer."\n", true);

		if (!Qbittorrent::isRunning()) {
			array_push($this->messages, "qBittorrent not reachable, cannot delete transfer ".$transfer);
			return false;
		}

		$hash = getTransferHash($transfer);
		deleteQbittorrentTransfer($cfg['uid'], $hash, false);

		// delete
		return $this->_delete();
	}

	/**
	 * gets current transfer-vals of a transfer
	 *
	 * @param $transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferCurrent($transfer) {
		global $db, $transfers;
		$this->_setVarsForTransfer($transfer);
		$retVal = array();
		$sf = new StatFile($transfer);
		$retVal["uptotal"] = $sf->uptotal;
		$retVal["downtotal"] = $sf->downtotal;
		return $retVal;
	}

	/**
	 * gets current transfer-vals of a transfer. optimized version
	 */
	function getTransferCurrentOP($transfer, $tid, $sfu, $sfd) {
		global $transfers;
		$retVal = array();
		$retVal["uptotal"] = (isset($transfers['totals'][$tid]['uptotal']))
			? abs($sfu - $transfers['totals'][$tid]['uptotal'])
			: $sfu;
		$retVal["downtotal"] = (isset($transfers['totals'][$tid]['downtotal']))
			? abs($sfd - $transfers['totals'][$tid]['downtotal'])
			: $sfd;
		return $retVal;
	}

	/**
	 * gets total transfer-vals of a transfer
	 */
	function getTransferTotal($transfer) {
		$sf = new StatFile($transfer);
		return array("uptotal" => $sf->uptotal, "downtotal" => $sf->downtotal);
	}

	/**
	 * gets total transfer-vals of a transfer. optimized version
	 */
	function getTransferTotalOP($transfer, $tid, $sfu, $sfd) {
		return array("uptotal" => $sfu, "downtotal" => $sfd);
	}

	/**
	 * set runtime of a transfer
	 */
	function setRuntime($transfer, $runtime, $autosend = false) {
		$this->runtime = $runtime;
		return true;
	}

	/**
	 * set upload rate of a transfer (KiB/s)
	 */
	function setRateUpload($transfer, $uprate, $autosend = false) {
		global $cfg;
		$this->rate = intval($uprate);
		$result = true;
		if ($autosend) {
			$hash = isHash($transfer) ? $transfer : getTransferHash($transfer);
			if (!empty($hash)) {
				$qbt = Qbittorrent::getInstance();
				$result = $qbt->setUploadLimit($hash, $this->rate * 1024);
				if (!$result)
					$this->logMessage("setRateUpload : ".$qbt->lastError."\n", true);
			}
		}
		return $result;
	}

	/**
	 * set download rate of a transfer (KiB/s)
	 */
	function setRateDownload($transfer, $downrate, $autosend = false) {
		global $cfg;
		$this->drate = intval($downrate);
		$result = true;
		if ($autosend) {
			$hash = isHash($transfer) ? $transfer : getTransferHash($transfer);
			if (!empty($hash)) {
				$qbt = Qbittorrent::getInstance();
				$result = $qbt->setDownloadLimit($hash, $this->drate * 1024);
				if (!$result)
					$this->logMessage("setRateDownload : ".$qbt->lastError."\n", true);
			}
		}
		return $result;
	}

	/**
	 * set sharekill (seed-ratio limit) of a transfer
	 */
	function setSharekill($transfer, $sharekill, $autosend = false) {
		global $cfg;
		$this->sharekill = $sharekill;
		if ($this->sharekill > 100)
			$this->sharekill = round((float)$this->sharekill / 100.0, 2);
		$result = true;
		if ($autosend) {
			$hash = isHash($transfer) ? $transfer : getTransferHash($transfer);
			if (!empty($hash) && $this->sharekill > 0) {
				$qbt = Qbittorrent::getInstance();
				$result = $qbt->setShareLimits($hash, (float)$this->sharekill);
				if (!$result)
					$this->logMessage("setSharekill : ".$qbt->lastError."\n", true);
			}
		}
		return $result;
	}

	/**
	 * count of running transfers
	 */
	function runningTransfers() {
		return getRunningQbittorrentTransferCount();
	}

	/**
	 * set stat-file of a stopped transfer
	 */
	function cleanStoppedStatFile($transfer) {
		$sf = new StatFile($transfer);
		$sf->stop();
	}

	/**
	 * update stat-files from live API data (and enforce sharekill)
	 */
	function updateStatFiles($transfer = "") {
		global $cfg, $db;

		$tfs = $this->monitorRunningTransfers();
		if (!is_array($tfs))
			return false;

		$sql = "SELECT hash, transfer, sharekill FROM tf_transfers WHERE type='torrent' AND client = 'qbittorrent'";

		if ($transfer != "") {
			$sql .= " AND transfer=".$db->qstr($transfer);
		} else {
			$in = array("''");
			foreach ($tfs as $hash => $t)
				$in[] = "'".strtolower($hash)."'";
			$sql .= " AND hash IN (".implode(',', $in).")";
		}

		$hashes = array();
		$sharekills = array();
		$recordset = $db->Execute($sql);
		while (($__row = $recordset->FetchRow()) !== false) { list($hash, $transfer, $sharekill) = $__row;
			$hash = strtolower($hash);
			$hashes[$hash] = $transfer;
			$sharekills[$hash] = $sharekill;
		}

		foreach ($tfs as $hash => $t) {
			if (!isset($hashes[$hash]))
				continue;
			$transfer = $hashes[$hash];
			$sf = new StatFile($transfer);

			$sf->running = ArrayGet($t, 'running', 0);
			$sf->percent_done = round($t['percentDone'] * 100, 2);
			if ($t['status'] == 8 || $t['status'] == 9)
				$sf->sharing = round($t['uploadRatio'] * 100, 2);

			$sf->downtotal = $t['downloadedEver'];
			$sf->uptotal = $t['uploadedEver'];
			$sf->down_speed = formatBytesTokBMBGBTB($t['rateDownload'])."/s";
			$sf->up_speed = formatBytesTokBMBGBTB($t['rateUpload'])."/s";
			$sf->seeds = $t['peersSendingToUs'];
			$sf->peers = $t['peersGettingFromUs'];
			if ($t['status'] == 8 || $t['status'] == 9)
				$sf->time_left = 'Seeding';
			elseif ($t['eta'] > 0 && $t['eta'] < 8640000)
				$sf->time_left = convertTime($t['eta']);
			else
				$sf->time_left = 'Downloading';

			$sf->write();
		}

		//SHAREKILL checks
		foreach ($tfs as $hash => $t) {
			if (!isset($sharekills[$hash]))
				continue;
			$sk = $sharekills[$hash];
			if ($sk > 100) $sk = round((float)$sk / 100.0, 2) * 100;
			if (($t['status'] == 8 || $t['status'] == 9)
				&& $sk > 0
				&& ($t['uploadRatio'] * 100) > $sk)
			{
				$transfer = $hashes[$hash];
				if (stopQbittorrentTransfer($hash)) {
					AuditAction($cfg["constants"]["stop_transfer"], $this->client."-stat. : sharekill stopped $transfer");
					stopTransferSettings($transfer);
				}
			}
		}
		return true;
	}

	/**
	 * gets current status of one Transfer (realtime)
	 * for transferStat popup
	 *
	 * @return array (stat) or Error String
	 */
	function monitorTransfer($transfer, $format = "rpc") {
		$this->_setVarsForTransfer($transfer);

		$hash = isHash($transfer) ? $transfer : getTransferHash($transfer);
		if (empty($hash))
			return "Hash for $transfer was not found";

		$stat = getQbittorrentTransfer($hash);
		if (is_array($stat))
			return $stat;
		return Qbittorrent::getInstance()->lastError;
	}

	/**
	 * gets current status of all Transfers (realtime)
	 */
	function monitorAllTransfers() {
		return getUserQbittorrentTransfers();
	}

	/**
	 * gets current status of all running Transfers (realtime)
	 */
	function monitorRunningTransfers() {
		$aTorrent = getUserQbittorrentTransfers();
		$stat = array();
		foreach ($aTorrent as $t) {
			if ($t['running'] == 1)
				$stat[$t['hashString']] = $t;
		}
		return $stat;
	}
}

?>
