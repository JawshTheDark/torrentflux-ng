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
 * qBittorrent Web API (v2) client.
 *
 * Configured via tf_settings:
 *   qbittorrent_url   e.g. http://localhost:8081
 *   qbittorrent_user  Web UI username
 *   qbittorrent_pass  Web UI password
 *
 * Torrent arrays returned by torrents()/torrent() are translated to the
 * transmission-rpc field names the rest of TorrentFlux already understands
 * (hashString, percentDone, rateDownload, status codes 4/8/9/16, ...).
 */
class Qbittorrent
{
	public $lastError = '';
	public $version = '';

	protected $base = '';
	protected $username = '';
	protected $password = '';
	protected $cookie = '';
	protected $loggedIn = false;
	// qBittorrent 5.x renamed pause/resume to stop/start; probed at runtime
	protected $stopVerb = null;

	protected static $instance = null;

	public static function getInstance() {
		global $cfg;
		if (self::$instance === null) {
			self::$instance = new Qbittorrent();
			self::$instance->base = isset($cfg['qbittorrent_url']) ? rtrim(trim($cfg['qbittorrent_url']), '/') : 'http://localhost:8081';
			self::$instance->username = isset($cfg['qbittorrent_user']) ? $cfg['qbittorrent_user'] : 'admin';
			self::$instance->password = isset($cfg['qbittorrent_pass']) ? $cfg['qbittorrent_pass'] : '';
		}
		return self::$instance;
	}

	public static function isRunning() {
		$qbt = self::getInstance();
		return $qbt->login();
	}

	// =========================================================================
	// http layer
	// =========================================================================

	protected function request($path, $post = null, $isMultipart = false) {
		$ch = curl_init($this->base.$path);
		// qBittorrent's CSRF protection requires a matching Referer/Origin
		$headers = array(
			'Referer: '.$this->base.'/',
			'Origin: '.$this->base,
		);
		if ($this->cookie != '')
			$headers[] = 'Cookie: '.$this->cookie;
		$opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_HEADER => true,
			CURLOPT_HTTPHEADER => $headers,
		);
		if ($post !== null) {
			$opts[CURLOPT_POST] = true;
			$opts[CURLOPT_POSTFIELDS] = $isMultipart ? $post : http_build_query($post);
		}
		curl_setopt_array($ch, $opts);
		$resp = curl_exec($ch);
		if ($resp === false) {
			$this->lastError = curl_error($ch);
			return array(0, '');
		}
		$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$headerBlock = substr($resp, 0, $headerSize);
		$body = substr($resp, $headerSize);
		// session cookie is usually SID= but proxies may rename it (e.g. QBT_SID_8090=)
		if (preg_match('/^set-cookie:\s*([A-Za-z0-9_]*SID[A-Za-z0-9_]*=[^;\r\n]+)/mi', $headerBlock, $m))
			$this->cookie = $m[1];
		return array($code, $body);
	}

	public function login() {
		if ($this->loggedIn)
			return true;
		// reuse a session-cached cookie to skip a login round-trip per page
		if ($this->cookie == '' && isset($_SESSION['qbt_cookie']) && is_string($_SESSION['qbt_cookie'])) {
			$this->cookie = $_SESSION['qbt_cookie'];
			list($code, $body) = $this->request('/api/v2/app/version');
			if ($code == 200) {
				$this->version = trim($body);
				$this->loggedIn = true;
				return true;
			}
			$this->cookie = '';
		}
		list($code, $body) = $this->request('/api/v2/auth/login', array(
			'username' => $this->username,
			'password' => $this->password,
		));
		// vanilla qBittorrent answers 200 "Ok."/"Fails."; proxies may strip the
		// body (204), so verify auth with an authenticated call instead.
		if ($code < 200 || $code >= 300 || trim($body) == 'Fails.') {
			$this->lastError = ($code == 0)
				? 'qBittorrent unreachable at '.$this->base.' : '.$this->lastError
				: 'qBittorrent login failed (HTTP '.$code.') - check qbittorrent_user/qbittorrent_pass';
			return false;
		}
		list($code, $body) = $this->request('/api/v2/app/version');
		if ($code != 200) {
			$this->lastError = 'qBittorrent login not accepted (HTTP '.$code.' on version probe) - check qbittorrent_user/qbittorrent_pass';
			return false;
		}
		$this->version = trim($body);
		$this->loggedIn = true;
		if (isset($_SESSION))
			$_SESSION['qbt_cookie'] = $this->cookie;
		return true;
	}

	protected function apiJson($path, $post = null) {
		if (!$this->login())
			return false;
		list($code, $body) = $this->request($path, $post);
		if ($code != 200) {
			$this->lastError = 'HTTP '.$code.' from '.$path.($body != '' ? ' : '.substr($body, 0, 200) : '');
			return false;
		}
		$data = json_decode($body, true);
		return ($data === null) ? array() : $data;
	}

	protected function apiOk($path, $post = null, $isMultipart = false) {
		if (!$this->login())
			return false;
		list($code, $body) = $this->request($path, $post, $isMultipart);
		if ($code != 200) {
			$this->lastError = 'HTTP '.$code.' from '.$path.($body != '' ? ' : '.substr($body, 0, 200) : '');
			return false;
		}
		return true;
	}

	// =========================================================================
	// torrent operations (transmission-compatible return shapes)
	// =========================================================================

	/**
	 * list torrents, keyed by lowercase hash, transmission-style fields.
	 *
	 * @param $hashes optional array of hashes to restrict to
	 * @return array or false on connection error
	 */
	public function torrents($hashes = null) {
		$params = null;
		if (is_array($hashes) && !empty($hashes))
			$params = '?hashes='.strtolower(implode('|', $hashes));
		$list = $this->apiJson('/api/v2/torrents/info'.($params === null ? '' : $params));
		if ($list === false)
			return false;
		$out = array();
		foreach ($list as $t) {
			$tf = $this->qbt_to_tf($t);
			$out[$tf['hashString']] = $tf;
		}
		return $out;
	}

	/**
	 * single torrent (or false)
	 */
	public function torrent($hash) {
		$list = $this->torrents(array($hash));
		if ($list === false)
			return false;
		$hash = strtolower($hash);
		return isset($list[$hash]) ? $list[$hash] : false;
	}

	/**
	 * add a torrent by local file path or URL/magnet.
	 *
	 * @return boolean
	 */
	public function add($fileOrUrl, $savepath, $paused = false, $params = array()) {
		$post = array_merge(array(
			'savepath' => $savepath,
			'paused' => $paused ? 'true' : 'false',   // <= 4.x
			'stopped' => $paused ? 'true' : 'false',  // >= 5.x
		), $params);
		if (preg_match('#^(https?|magnet):#i', $fileOrUrl)) {
			$post['urls'] = $fileOrUrl;
			return $this->apiOk('/api/v2/torrents/add', $post, true);
		}
		if (!is_file($fileOrUrl)) {
			$this->lastError = 'torrent file not found: '.$fileOrUrl;
			return false;
		}
		$post['torrents'] = new CURLFile($fileOrUrl, 'application/x-bittorrent', basename($fileOrUrl));
		return $this->apiOk('/api/v2/torrents/add', $post, true);
	}

	public function start($hash) {
		return $this->startStop($hash, 'start');
	}

	public function stop($hash) {
		return $this->startStop($hash, 'stop');
	}

	protected function startStop($hash, $action) {
		$hash = strtolower($hash);
		// 5.x verbs, 4.x fallbacks
		$verbs = ($action == 'stop')
			? array('stop', 'pause')
			: array('start', 'resume');
		if ($this->stopVerb == 'legacy')
			$verbs = array_reverse($verbs);
		foreach ($verbs as $verb) {
			if ($this->apiOk('/api/v2/torrents/'.$verb, array('hashes' => $hash))) {
				$this->stopVerb = in_array($verb, array('pause', 'resume')) ? 'legacy' : 'current';
				return true;
			}
		}
		return false;
	}

	public function remove($hash, $deleteData = false) {
		return $this->apiOk('/api/v2/torrents/delete', array(
			'hashes' => strtolower($hash),
			'deleteFiles' => $deleteData ? 'true' : 'false',
		));
	}

	public function setUploadLimit($hash, $bytesPerSec) {
		return $this->apiOk('/api/v2/torrents/setUploadLimit', array(
			'hashes' => strtolower($hash), 'limit' => intval($bytesPerSec)));
	}

	public function setDownloadLimit($hash, $bytesPerSec) {
		return $this->apiOk('/api/v2/torrents/setDownloadLimit', array(
			'hashes' => strtolower($hash), 'limit' => intval($bytesPerSec)));
	}

	/**
	 * @param $ratio float seed-ratio limit, -2 = use global, -1 = unlimited
	 */
	public function setShareLimits($hash, $ratio) {
		return $this->apiOk('/api/v2/torrents/setShareLimits', array(
			'hashes' => strtolower($hash),
			'ratioLimit' => $ratio,
			'seedingTimeLimit' => -2,
			'inactiveSeedingTimeLimit' => -2,
		));
	}

	public function properties($hash) {
		return $this->apiJson('/api/v2/torrents/properties?hash='.strtolower($hash));
	}

	/**
	 * replace a tracker URL on a torrent (used by the announce-rewrite control).
	 */
	public function editTracker($hash, $origUrl, $newUrl) {
		return $this->apiOk('/api/v2/torrents/editTracker', array(
			'hash' => strtolower($hash),
			'origUrl' => $origUrl,
			'newUrl' => $newUrl,
		));
	}

	public function trackers($hash) {
		return $this->apiJson('/api/v2/torrents/trackers?hash='.strtolower($hash));
	}

	public function peers($hash) {
		$data = $this->apiJson('/api/v2/sync/torrentPeers?hash='.strtolower($hash));
		if ($data === false || !isset($data['peers']))
			return array();
		return $data['peers'];
	}

	public function files($hash) {
		return $this->apiJson('/api/v2/torrents/files?hash='.strtolower($hash));
	}

	/**
	 * set per-file download priority.
	 *
	 * @param $hash torrent hash
	 * @param $ids array of file ids (or a single id)
	 * @param $priority 0 = do not download, 1 = normal, 6 = high, 7 = maximal
	 * @return boolean
	 */
	public function setFilePriority($hash, $ids, $priority) {
		if (!is_array($ids))
			$ids = array($ids);
		if (empty($ids))
			return true;
		return $this->apiOk('/api/v2/torrents/filePrio', array(
			'hash' => strtolower($hash),
			'id' => implode('|', array_map('intval', $ids)),
			'priority' => intval($priority),
		));
	}

	public function transferInfo() {
		return $this->apiJson('/api/v2/transfer/info');
	}

	/**
	 * raw .torrent file contents for a hash (false if unavailable, e.g.
	 * metadata not yet fetched for a magnet add)
	 */
	public function export($hash) {
		if (!$this->login())
			return false;
		list($code, $body) = $this->request('/api/v2/torrents/export?hash='.strtolower($hash));
		if ($code != 200 || $body == '' || $body[0] != 'd')
			return false;
		return $body;
	}

	// =========================================================================
	// field translation
	// =========================================================================

	/**
	 * map a qBittorrent state string to the legacy transmission status codes
	 * used throughout TorrentFlux:
	 *   4 downloading, 5 queued-down, 8 seeding, 9 queued-seed,
	 *   1/2 checking, 16 stopped
	 */
	public static function status_compat($state) {
		switch ($state) {
			case 'downloading':
			case 'forcedDL':
			case 'metaDL':
			case 'forcedMetaDL':
			case 'stalledDL':
			case 'allocating':
				return 4;
			case 'queuedDL':
				return 5;
			case 'uploading':
			case 'forcedUP':
			case 'stalledUP':
				return 8;
			case 'queuedUP':
				return 9;
			case 'checkingDL':
			case 'checkingUP':
			case 'checkingResumeData':
				return 2;
			case 'pausedDL':
			case 'stoppedDL':
			case 'pausedUP':
			case 'stoppedUP':
			case 'missingFiles':
			case 'error':
			default:
				return 16;
		}
	}

	/**
	 * translate one /torrents/info entry into transmission-rpc style fields
	 */
	public function qbt_to_tf($t) {
		$state = isset($t['state']) ? $t['state'] : 'unknown';
		$status = self::status_compat($state);
		$size = isset($t['size']) ? $t['size'] : 0;
		return array(
			'hashString' => strtolower(isset($t['hash']) ? $t['hash'] : ''),
			'name' => isset($t['name']) ? $t['name'] : '',
			'status' => $status,
			'state' => $state,
			'running' => ($status != 16) ? 1 : 0,
			'totalSize' => $size,
			'percentDone' => isset($t['progress']) ? (float)$t['progress'] : 0.0,
			'metadataPercentComplete' => 1,
			'rateDownload' => isset($t['dlspeed']) ? $t['dlspeed'] : 0,
			'rateUpload' => isset($t['upspeed']) ? $t['upspeed'] : 0,
			'downloadedEver' => isset($t['downloaded']) ? $t['downloaded'] : 0,
			'uploadedEver' => isset($t['uploaded']) ? $t['uploaded'] : 0,
			'uploadRatio' => isset($t['ratio']) ? (float)$t['ratio'] : 0.0,
			'seedRatioLimit' => isset($t['ratio_limit']) ? (float)$t['ratio_limit'] : -2.0,
			'eta' => isset($t['eta']) ? $t['eta'] : -1,
			'peersConnected' => (isset($t['num_leechs']) ? $t['num_leechs'] : 0) + (isset($t['num_seeds']) ? $t['num_seeds'] : 0),
			'peersSendingToUs' => isset($t['num_seeds']) ? $t['num_seeds'] : 0,
			'peersGettingFromUs' => isset($t['num_leechs']) ? $t['num_leechs'] : 0,
			'seederCount' => isset($t['num_complete']) ? $t['num_complete'] : 0,
			'leecherCount' => isset($t['num_incomplete']) ? $t['num_incomplete'] : 0,
			'downloadDir' => isset($t['save_path']) ? $t['save_path'] : '',
			'downloadLimit' => isset($t['dl_limit']) ? $t['dl_limit'] : 0,
			'uploadLimit' => isset($t['up_limit']) ? $t['up_limit'] : 0,
			'error' => ($state == 'error' || $state == 'missingFiles') ? 1 : 0,
			'errorString' => ($state == 'missingFiles') ? 'missing files' : (($state == 'error') ? 'qBittorrent error state' : ''),
		);
	}
}

?>
