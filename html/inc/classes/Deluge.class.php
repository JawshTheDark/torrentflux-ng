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
 * Deluge Web JSON-RPC client (deluge-web, default port 8112).
 *
 * Configured via tf_settings:
 *   deluge_url       e.g. http://localhost:8112
 *   deluge_password  Web UI password (default "deluge")
 *
 * Torrent arrays returned by torrents()/torrent() are translated to the
 * transmission-rpc field names the rest of TorrentFlux already understands
 * (hashString, percentDone, rateDownload, status codes 4/8/9/16, ...).
 */
class Deluge
{
	public $lastError = '';
	public $version = '';

	protected $base = '';
	protected $password = '';
	protected $cookie = '';
	protected $loggedIn = false;
	protected $rpcId = 1;

	protected static $instance = null;

	public static function getInstance() {
		global $cfg;
		if (self::$instance === null) {
			self::$instance = new Deluge();
			self::$instance->base = isset($cfg['deluge_url']) ? rtrim(trim($cfg['deluge_url']), '/') : 'http://localhost:8112';
			self::$instance->password = isset($cfg['deluge_password']) ? $cfg['deluge_password'] : 'deluge';
		}
		return self::$instance;
	}

	public static function isRunning() {
		$d = self::getInstance();
		return $d->login();
	}

	// =========================================================================
	// JSON-RPC layer
	// =========================================================================

	/**
	 * Low-level JSON-RPC call. Returns the decoded response array, or false on
	 * transport error (with lastError set).
	 */
	protected function rpc($method, $params = array()) {
		$payload = json_encode(array('method' => $method, 'params' => $params, 'id' => $this->rpcId++));
		$ch = curl_init($this->base.'/json');
		$headers = array('Content-Type: application/json', 'Accept: application/json');
		if ($this->cookie != '')
			$headers[] = 'Cookie: '.$this->cookie;
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_HEADER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_HTTPHEADER => $headers,
		));
		$resp = curl_exec($ch);
		if ($resp === false) {
			$this->lastError = curl_error($ch);
			return false;
		}
		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$headerBlock = substr($resp, 0, $headerSize);
		$body = substr($resp, $headerSize);
		if (preg_match('/^set-cookie:\s*(_session_id=[^;\r\n]+)/mi', $headerBlock, $m))
			$this->cookie = $m[1];
		$data = json_decode($body, true);
		if (!is_array($data)) {
			$this->lastError = 'Deluge: invalid JSON response';
			return false;
		}
		if (isset($data['error']) && $data['error'] !== null) {
			$this->lastError = is_array($data['error']) && isset($data['error']['message'])
				? $data['error']['message'] : json_encode($data['error']);
		}
		return $data;
	}

	/**
	 * Authenticate and ensure the web UI is connected to a daemon.
	 */
	public function login() {
		if ($this->loggedIn)
			return true;
		// reuse a session-cached cookie to skip the login round-trip per page
		if ($this->cookie == '' && isset($_SESSION['deluge_cookie']) && is_string($_SESSION['deluge_cookie']))
			$this->cookie = $_SESSION['deluge_cookie'];

		if ($this->cookie != '') {
			$chk = $this->rpc('auth.check_session');
			if (is_array($chk) && isset($chk['result']) && $chk['result'] === true) {
				$this->loggedIn = $this->ensureConnected();
				if ($this->loggedIn) return true;
			}
			$this->cookie = '';
		}

		$res = $this->rpc('auth.login', array($this->password));
		if (!is_array($res) || empty($res['result'])) {
			if ($this->lastError == '') $this->lastError = 'Deluge: authentication failed';
			return false;
		}
		$_SESSION['deluge_cookie'] = $this->cookie;
		$this->loggedIn = $this->ensureConnected();
		return $this->loggedIn;
	}

	/**
	 * Make sure the web UI is connected to a deluge daemon (deluge-web starts
	 * disconnected; connect to the first available host).
	 */
	protected function ensureConnected() {
		$c = $this->rpc('web.connected');
		if (is_array($c) && isset($c['result']) && $c['result'] === true)
			return true;
		$hosts = $this->rpc('web.get_hosts');
		if (!is_array($hosts) || empty($hosts['result']) || !is_array($hosts['result'])) {
			$this->lastError = 'Deluge: no daemon hosts available';
			return false;
		}
		$hostId = $hosts['result'][0][0];
		$conn = $this->rpc('web.connect', array($hostId));
		return (is_array($conn) && $conn['error'] === null);
	}

	// =========================================================================
	// torrent queries
	// =========================================================================

	protected function statusKeys() {
		return array('name','hash','state','progress','total_size','total_done',
			'total_payload_download','total_payload_upload','ratio',
			'download_payload_rate','upload_payload_rate','num_seeds','num_peers',
			'eta','paused','is_finished');
	}

	/**
	 * All torrents, keyed by lowercase hash, normalised to the tf field shape.
	 */
	public function torrents() {
		if (!$this->login())
			return false;
		$res = $this->rpc('core.get_torrents_status', array(new stdClass(), $this->statusKeys()));
		if (!is_array($res) || !isset($res['result']) || !is_array($res['result']))
			return false;
		$out = array();
		foreach ($res['result'] as $hash => $t) {
			$h = strtolower($hash);
			$out[$h] = $this->del_to_tf($h, $t);
		}
		return $out;
	}

	public function torrent($hash) {
		if (!$this->login())
			return false;
		$res = $this->rpc('core.get_torrent_status', array($hash, $this->statusKeys()));
		if (!is_array($res) || !isset($res['result']) || !is_array($res['result']) || empty($res['result']))
			return false;
		return $this->del_to_tf(strtolower($hash), $res['result']);
	}

	/**
	 * Map deluge state to the transmission-compat status codes TF uses.
	 * 4=downloading, 8=seeding, 9=queued-seed, 2=checking, 16=stopped.
	 */
	public function status_compat($state, $paused, $progress) {
		if ($paused)
			return 16;
		switch ($state) {
			case 'Downloading':  return 4;
			case 'Seeding':      return 8;
			case 'Queued':       return ($progress >= 100) ? 9 : 3;
			case 'Checking':
			case 'Allocating':   return 2;
			case 'Paused':       return 16;
			case 'Error':        return 16;
			default:             return ($progress >= 100) ? 8 : 4;
		}
	}

	protected function del_to_tf($hash, $t) {
		$progress = isset($t['progress']) ? (float)$t['progress'] : 0.0; // 0..100
		$paused   = !empty($t['paused']);
		$state    = isset($t['state']) ? $t['state'] : '';
		$status   = $this->status_compat($state, $paused, $progress);
		return array(
			'hashString'        => $hash,
			'name'              => isset($t['name']) ? $t['name'] : $hash,
			'status'            => $status,
			'running'           => ($status == 4 || $status == 8 || $status == 9) ? 1 : 0,
			'percentDone'       => $progress / 100.0,
			'totalSize'         => isset($t['total_size']) ? (float)$t['total_size'] : 0,
			'downloadedEver'    => isset($t['total_payload_download']) ? (float)$t['total_payload_download'] : 0,
			'uploadedEver'      => isset($t['total_payload_upload']) ? (float)$t['total_payload_upload'] : 0,
			'uploadRatio'       => isset($t['ratio']) ? (float)$t['ratio'] : 0,
			'rateDownload'      => isset($t['download_payload_rate']) ? (int)$t['download_payload_rate'] : 0,
			'rateUpload'        => isset($t['upload_payload_rate']) ? (int)$t['upload_payload_rate'] : 0,
			'peersSendingToUs'  => isset($t['num_seeds']) ? (int)$t['num_seeds'] : 0,
			'peersGettingFromUs'=> isset($t['num_peers']) ? (int)$t['num_peers'] : 0,
			'eta'               => isset($t['eta']) ? (int)$t['eta'] : 0,
		);
	}

	// =========================================================================
	// mutations
	// =========================================================================

	/**
	 * Add a torrent. $location is a magnet URI or a path to a local .torrent
	 * file. Returns the info-hash on success, or false.
	 */
	public function add($location, $savepath = '', $paused = false) {
		if (!$this->login())
			return false;
		$options = array();
		if ($savepath !== '')
			$options['download_location'] = $savepath;
		$options['add_paused'] = (bool)$paused;

		if (preg_match('#^magnet:#i', $location)) {
			$res = $this->rpc('core.add_torrent_magnet', array($location, $options));
		} else {
			$raw = @file_get_contents($location);
			if ($raw === false) {
				$this->lastError = 'Deluge: cannot read torrent file '.$location;
				return false;
			}
			$res = $this->rpc('core.add_torrent_file', array(basename($location), base64_encode($raw), $options));
		}
		if (!is_array($res)) return false;
		// success returns the torrent hash; a duplicate returns null with no error
		if (isset($res['result']) && is_string($res['result']) && $res['result'] !== '')
			return strtolower($res['result']);
		if ($this->lastError != '') return false;
		// duplicate (result null): resolve the hash from the file if we can
		return false;
	}

	public function start($hash) {
		if (!$this->login()) return false;
		$res = $this->rpc('core.resume_torrent', array($hash));
		return is_array($res) && $res['error'] === null;
	}

	public function stop($hash) {
		if (!$this->login()) return false;
		$res = $this->rpc('core.pause_torrent', array($hash));
		return is_array($res) && $res['error'] === null;
	}

	public function remove($hash, $removeData = false) {
		if (!$this->login()) return false;
		$res = $this->rpc('core.remove_torrent', array($hash, (bool)$removeData));
		return is_array($res) && $res['error'] === null;
	}

	/** limit in bytes/s; -1 = unlimited (deluge uses KiB/s, -1 unlimited) */
	public function setDownloadLimit($hash, $bytesPerSec) {
		if (!$this->login()) return false;
		$kib = ($bytesPerSec > 0) ? round($bytesPerSec / 1024.0, 1) : -1;
		$res = $this->rpc('core.set_torrent_max_download_speed', array($hash, $kib));
		return is_array($res) && $res['error'] === null;
	}

	public function setUploadLimit($hash, $bytesPerSec) {
		if (!$this->login()) return false;
		$kib = ($bytesPerSec > 0) ? round($bytesPerSec / 1024.0, 1) : -1;
		$res = $this->rpc('core.set_torrent_max_upload_speed', array($hash, $kib));
		return is_array($res) && $res['error'] === null;
	}

	/** stop seeding at the given ratio (0 = seed forever) */
	public function setShareLimits($hash, $ratio) {
		if (!$this->login()) return false;
		if ($ratio > 0) {
			$this->rpc('core.set_torrent_stop_ratio', array($hash, (float)$ratio));
			$res = $this->rpc('core.set_torrent_stop_at_ratio', array($hash, true));
		} else {
			$res = $this->rpc('core.set_torrent_stop_at_ratio', array($hash, false));
		}
		return is_array($res) && $res['error'] === null;
	}

	/** priorities: array of per-file priority ints in file order */
	public function setFilePriorities($hash, $priorities) {
		if (!$this->login()) return false;
		$res = $this->rpc('core.set_torrent_file_priorities', array($hash, array_values($priorities)));
		return is_array($res) && $res['error'] === null;
	}
}
