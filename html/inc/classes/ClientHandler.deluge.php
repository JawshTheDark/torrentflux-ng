<?php

/*******************************************************************************

 LICENSE

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License (GPL)
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 To read the license please visit http://www.gnu.org/copyleft/gpl.html

*******************************************************************************/

require_once("inc/functions/functions.core.php");
require_once("inc/classes/Deluge.class.php");
require_once("inc/functions/functions.rpc.deluge.php");

/**
 * ClientHandler for the Deluge Web JSON-RPC (modeled on the qBittorrent handler).
 */
class ClientHandlerDeluge extends ClientHandler
{
	// when true, delete() tells Deluge to remove the payload too
	var $deleteData = false;

	public function __construct() {
		$this->type = "torrent";
		$this->client = "deluge";
		$this->binSocket = "deluged";
		$this->binSystem = "deluged";
		$this->binClient = "deluged";
		$this->useRPC = true;
	}

	// =========================================================================
	// start / stop / delete
	// =========================================================================

	function start($transfer, $interactive = false, $enqueue = false) {
		global $cfg, $db;

		$this->_setVarsForTransfer($transfer);
		addGrowlMessage($this->client."-start", $transfer);

		if (!Deluge::isRunning()) {
			$d = Deluge::getInstance();
			$msg = "Deluge not reachable, cannot start transfer ".$transfer." : ".$d->lastError;
			$this->logMessage($this->client."-start : ".$msg."\n", true);
			AuditAction($cfg["constants"]["error"], $msg);
			addGrowlMessage($this->client."-start", $msg);
			$sf = new StatFile($this->transfer, $this->owner);
			$sf->time_left = 'Error: API down';
			$sf->write();
			return false;
		}

		$this->_init($interactive, $enqueue, true, false);

		if (!is_dir($cfg['path'].$cfg['user']))
			mkdir($cfg['path'].$cfg['user'], 0777);

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

		$this->state = CLIENTHANDLER_STATE_READY;
		$this->_start();

		$hash = getTransferHash($transfer);
		if (empty($hash) || !isDelugeTransfer($hash)) {
			$res = addDelugeTransfer($cfg['uid'], $cfg['transfer_file_path'].$transfer, $this->savepath, false);
			if (is_array($res)) {
				// duplicate or error
				$this->command .= "\n".'torrent-add : '.(isset($res['result']) ? $res['result'] : 'failed');
				$hash = getTransferHash($transfer);
			} else {
				$hash = $res;
			}
			$this->command .= "\n".'torrent-add '.$transfer.' '.$hash;
		} else {
			$this->command .= "\n".'torrent-start '.$transfer.' '.$hash;
		}

		$res = 0;
		if (!empty($hash) && is_string($hash)) {
			// persist the daemon hash so updateStatFiles() can match this transfer
			if (isHash($hash)) {
				$db->Execute("UPDATE tf_transfers SET hash=".$db->qstr(strtolower($hash))." WHERE transfer=".$db->qstr($transfer));
			}
			// sharekill is a percentage of the share ratio (100 = 1:1); Deluge wants a ratio.
			if ($this->sharekill > 0)
				$this->sharekill = round((float)$this->sharekill / 100.0, 2);
			$params = array(
				'downloadLimit'  => intval($this->drate) * 1024, // KiB/s -> bytes/s
				'uploadLimit'    => intval($this->rate) * 1024,
				'seedRatioLimit' => (float)$this->sharekill,
			);
			$res = (int)startDelugeTransfer($hash, $enqueue, $params);
		}

		$this->updateStatFiles($transfer);
		$this->logMessage($this->client."-start : hash=$hash\ndownload rate=".$this->drate.", res=$res\n", true);
	}

	function stop($transfer, $kill = false, $transferPid = 0) {
		global $cfg;
		$this->_setVarsForTransfer($transfer);
		$this->logMessage($this->client."-stop : ".$transfer."\n", true);

		if (!Deluge::isRunning()) {
			array_push($this->messages, "Deluge not reachable, cannot stop transfer ".$transfer);
			return false;
		}

		$hash = getTransferHash($transfer);
		if (empty($hash)) {
			@unlink($this->transferFilePath.".pid");
			AuditAction($cfg["constants"]["debug"], $this->client."-stop : $transfer not in db, cleaning...");
			$this->delete($transfer);
			return true;
		}

		$this->updateStatFiles($transfer);

		if (!stopDelugeTransfer($hash)) {
			$d = Deluge::getInstance();
			$this->logMessage($transfer." : ".$d->lastError."\n", true);
		}

		$this->_stop($kill, $transferPid);
		$this->cleanStoppedStatFile($transfer);
	}

	function delete($transfer) {
		global $cfg;
		$this->_setVarsForTransfer($transfer);
		$this->logMessage($this->client."-delete : ".$transfer."\n", true);

		if (!Deluge::isRunning()) {
			array_push($this->messages, "Deluge not reachable, cannot delete transfer ".$transfer);
			return false;
		}

		$hash = getTransferHash($transfer);
		deleteDelugeTransfer($cfg['uid'], $hash, !empty($this->deleteData));
		return $this->_delete();
	}

	// =========================================================================
	// transfer totals (read from stat-files)
	// =========================================================================

	function getTransferCurrent($transfer) {
		$this->_setVarsForTransfer($transfer);
		$sf = new StatFile($transfer);
		return array("uptotal" => $sf->uptotal, "downtotal" => $sf->downtotal);
	}

	function getTransferCurrentOP($transfer, $tid, $sfu, $sfd) {
		global $transfers;
		$retVal = array();
		$retVal["uptotal"] = (isset($transfers['totals'][$tid]['uptotal']))
			? abs($sfu - $transfers['totals'][$tid]['uptotal']) : $sfu;
		$retVal["downtotal"] = (isset($transfers['totals'][$tid]['downtotal']))
			? abs($sfd - $transfers['totals'][$tid]['downtotal']) : $sfd;
		return $retVal;
	}

	function getTransferTotal($transfer) {
		$sf = new StatFile($transfer);
		return array("uptotal" => $sf->uptotal, "downtotal" => $sf->downtotal);
	}

	function getTransferTotalOP($transfer, $tid, $sfu, $sfd) {
		return array("uptotal" => $sfu, "downtotal" => $sfd);
	}

	// =========================================================================
	// rate / sharekill
	// =========================================================================

	function setRateUpload($transfer, $uprate, $autosend = false) {
		$this->rate = $uprate;
		if ($autosend) {
			$hash = isHash($transfer) ? $transfer : getTransferHash($transfer);
			if (!empty($hash)) {
				$d = Deluge::getInstance();
				if (!$d->setUploadLimit($hash, intval($uprate) * 1024))
					$this->logMessage("setRateUpload : ".$d->lastError."\n", true);
			}
		}
		return true;
	}

	function setRateDownload($transfer, $downrate, $autosend = false) {
		$this->drate = $downrate;
		if ($autosend) {
			$hash = isHash($transfer) ? $transfer : getTransferHash($transfer);
			if (!empty($hash)) {
				$d = Deluge::getInstance();
				if (!$d->setDownloadLimit($hash, intval($downrate) * 1024))
					$this->logMessage("setRateDownload : ".$d->lastError."\n", true);
			}
		}
		return true;
	}

	function setSharekill($transfer, $sharekill, $autosend = false) {
		$this->sharekill = $sharekill;
		// sharekill is a percentage of the share ratio (100 = 1:1); Deluge wants a ratio.
		if ($this->sharekill > 0)
			$this->sharekill = round((float)$this->sharekill / 100.0, 2);
		$result = true;
		if ($autosend) {
			$hash = isHash($transfer) ? $transfer : getTransferHash($transfer);
			if (!empty($hash)) {
				$d = Deluge::getInstance();
				$result = $d->setShareLimits($hash, (float)$this->sharekill);
				if (!$result)
					$this->logMessage("setSharekill : ".$d->lastError."\n", true);
			}
		}
		return $result;
	}

	function setSettings($transfer, $rate, $drate, $sharekill, $runtime, $autosend = false) {
		$this->setRateUpload($transfer, $rate, $autosend);
		$this->setRateDownload($transfer, $drate, $autosend);
		$this->setSharekill($transfer, $sharekill, $autosend);
		return true;
	}

	// =========================================================================
	// monitoring
	// =========================================================================

	function monitorRunningTransfers() {
		$d = Deluge::getInstance();
		$all = $d->torrents();
		if (!is_array($all))
			return array();
		$stat = array();
		foreach ($all as $hash => $t) {
			if ($t['status'] == 4 || $t['status'] == 8 || $t['status'] == 9)
				$stat[strtolower($hash)] = $t;
		}
		return $stat;
	}

	/**
	 * update stat-files from live API data (and enforce sharekill).
	 */
	function updateStatFiles($transfer = "") {
		global $cfg, $db;

		$tfs = $this->monitorRunningTransfers();
		if (!is_array($tfs))
			return false;

		$sql = "SELECT hash, transfer, sharekill FROM tf_transfers WHERE type='torrent' AND client = 'deluge'";
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
			$status = $t['status'];
			$sf->running = ($status == 4 || $status == 8 || $status == 9) ? 1 : 0;
			$sf->percent_done = round($t['percentDone'] * 100, 2);
			if ($status == 8 || $status == 9)
				$sf->sharing = round($t['uploadRatio'] * 100, 2);
			$sf->downtotal = $t['downloadedEver'];
			$sf->uptotal = $t['uploadedEver'];
			$sf->down_speed = formatBytesTokBMBGBTB($t['rateDownload'])."/s";
			$sf->up_speed = formatBytesTokBMBGBTB($t['rateUpload'])."/s";
			$sf->seeds = $t['peersSendingToUs'];
			$sf->peers = $t['peersGettingFromUs'];
			if ($status == 8 || $status == 9)
				$sf->time_left = 'Seeding';
			elseif (isset($t['eta']) && $t['eta'] > 0 && $t['eta'] < 8640000)
				$sf->time_left = convertTime($t['eta']);
			else
				$sf->time_left = 'Downloading';
			$sf->write();
		}

		// sharekill enforcement
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
				if (stopDelugeTransfer($hash)) {
					AuditAction($cfg["constants"]["stop_transfer"], $this->client."-stat. : sharekill stopped $transfer");
					stopTransferSettings($transfer);
				}
			}
		}
		return true;
	}

	/**
	 * current status of one transfer (for the transferStat popup)
	 */
	function monitorTransfer($transfer, $format = "rpc") {
		$hash = getTransferHash($transfer);
		if (empty($hash))
			return array();
		$d = Deluge::getInstance();
		$t = $d->torrent($hash);
		return is_array($t) ? $t : array();
	}
}
