<?php

/*************************************************************
 *  FluxTorrent - Prowlarr meta-search engine
 *
 *  Replaces the legacy site-scraper engines with a single
 *  engine backed by a local Prowlarr instance
 *  (https://prowlarr.com), which aggregates the user's own
 *  configured indexers through a stable JSON API.
 *
 *  Configuration (Admin -> Search Settings):
 *    $cfg["prowlarr_url"]    e.g. http://localhost:9696
 *    $cfg["prowlarr_apikey"] Prowlarr Settings -> General -> API Key
 *************************************************************/
/*
	This file is part of TorrentFlux.

	TorrentFlux is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	TorrentFlux is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

class SearchEngine extends SearchEngineBase
{
	var $engineName = 'Prowlarr';
	var $mainTitle = 'Prowlarr';
	var $author = 'FluxTorrent';
	var $version = '1.0';

	var $baseURL = '';
	var $apiKey = '';
	var $pageSize = 50;

	public function __construct($cfg) {
		$this->mainURL = 'localhost';
		$this->Initialize($cfg);
	}

	//----------------------------------------------------------------
	// Torznab top-level categories.
	function populateMainCategories() {
		$this->mainCatalog = array(
			'2000' => 'Movies',
			'5000' => 'TV',
			'3000' => 'Audio',
			'4000' => 'PC',
			'1000' => 'Console',
			'7000' => 'Books',
			'8000' => 'Other',
		);
	}

	//----------------------------------------------------------------
	// Connection test: ping the configured Prowlarr instance.
	function getConnection() {
		$this->baseURL = isset($this->cfg['prowlarr_url']) ? rtrim(trim($this->cfg['prowlarr_url']), '/') : '';
		$this->apiKey = isset($this->cfg['prowlarr_apikey']) ? trim($this->cfg['prowlarr_apikey']) : '';
		if ($this->baseURL == '' || $this->apiKey == '') {
			$this->msg = 'Prowlarr is not configured. Set the Prowlarr URL and API key under Admin &gt; Search Settings.';
			return false;
		}
		$status = $this->apiCall('/api/v1/system/status');
		if ($status === false) {
			$this->msg = 'Error connecting to Prowlarr at '.htmlspecialchars($this->baseURL).' : '.htmlspecialchars($this->apiError);
			return false;
		}
		return true;
	}

	function closeConnection() {
		// no persistent connection
	}

	//----------------------------------------------------------------
	// Latest = empty query (indexers that support it return recent items)
	function getLatest() {
		return $this->doSearch('');
	}

	function performSearch($searchTerm) {
		$this->searchTerm = $searchTerm;
		return $this->doSearch($searchTerm);
	}

	// =================================================================
	// internals
	// =================================================================

	var $apiError = '';

	function apiCall($path, $params = array()) {
		$url = $this->baseURL.$path;
		if (!empty($params))
			$url .= '?'.http_build_query($params);
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTPHEADER => array(
				'X-Api-Key: '.$this->apiKey,
				'Accept: application/json',
			),
		));
		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		$err = curl_error($ch);
		$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		if ($errno !== 0) {
			$this->apiError = $err;
			return false;
		}
		if ($code < 200 || $code >= 300) {
			$this->apiError = 'HTTP '.$code.($code == 401 ? ' (check API key)' : '');
			return false;
		}
		$data = json_decode($body, true);
		if ($data === null && $body !== 'null') {
			$this->apiError = 'invalid JSON response';
			return false;
		}
		return $data;
	}

	/**
	 * live list of enabled indexers configured in Prowlarr, sorted by name.
	 * Result is cached in the session for a short while so the dropdown stays
	 * in sync with Prowlarr without an API call on every keystroke/page.
	 *
	 * @return array of array('id' => int, 'name' => string)
	 */
	function getIndexers() {
		$now = @time();
		if (isset($_SESSION['prowlarr_indexers'], $_SESSION['prowlarr_indexers_ts'])
			&& is_array($_SESSION['prowlarr_indexers'])
			&& ($now - $_SESSION['prowlarr_indexers_ts']) < 120) {
			return $_SESSION['prowlarr_indexers'];
		}
		$list = $this->apiCall('/api/v1/indexer');
		if ($list === false || !is_array($list))
			return isset($_SESSION['prowlarr_indexers']) ? $_SESSION['prowlarr_indexers'] : array();
		$out = array();
		foreach ($list as $ix) {
			if (isset($ix['enable']) && !$ix['enable'])
				continue;
			if (!isset($ix['id'], $ix['name']))
				continue;
			$out[] = array('id' => (int)$ix['id'], 'name' => (string)$ix['name']);
		}
		usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
		$_SESSION['prowlarr_indexers'] = $out;
		$_SESSION['prowlarr_indexers_ts'] = $now;
		return $out;
	}

	function doSearch($query) {
		$mainGenre = tfb_getRequestVar('mainGenre');
		$page = intval($this->pg);
		if ($page < 0) $page = 0;

		$params = array(
			'query' => $query,
			'type' => 'search',
			'limit' => $this->pageSize,
			'offset' => $page * $this->pageSize,
		);
		if (!empty($mainGenre) && ctype_digit((string)$mainGenre))
			$params['categories'] = $mainGenre;

		// restrict to a single indexer when one is picked in the dropdown
		$indexer = tfb_getRequestVar('indexer');
		if (!empty($indexer) && $indexer !== 'all' && ctype_digit((string)$indexer))
			$params['indexerIds'] = $indexer;

		$results = $this->apiCall('/api/v1/search', $params);
		if ($results === false)
			return '<b>Prowlarr search failed:</b> '.htmlspecialchars($this->apiError);
		if (!is_array($results))
			$results = array();

		// torrents only, optionally hide seedless, best-seeded first
		$rows = array();
		foreach ($results as $r) {
			if (isset($r['protocol']) && $r['protocol'] != 'torrent')
				continue;
			$seeds = isset($r['seeders']) ? intval($r['seeders']) : 0;
			// hideSeedless holds the 'yes'/'no' session string, not a boolean
			if ($this->hideSeedless === 'yes' && $seeds <= 0)
				continue;
			$rows[] = $r;
		}
		usort($rows, function ($a, $b) {
			return (isset($b['seeders']) ? intval($b['seeders']) : 0) - (isset($a['seeders']) ? intval($a['seeders']) : 0);
		});

		$output = $this->tableHeader();
		$bg = $this->cfg['bgLight'];
		foreach ($rows as $r) {
			$output .= $this->buildRow($r, $bg);
			$bg = ($bg == $this->cfg['bgLight']) ? $this->cfg['bgDark'] : $this->cfg['bgLight'];
		}

		if (empty($rows))
			$output .= '<tr><td colspan="7">No results.</td></tr>';

		$output .= '</table>';
		$output .= $this->pager(count($rows));
		return $output;
	}

	function tableHeader() {
		// Show/Hide seedless toggle (persists via the hideSeedless session var)
		$tmpURI = str_replace(
			array("?hideSeedless=yes","&hideSeedless=yes","?hideSeedless=no","&hideSeedless=no"),
			"", $_SERVER["REQUEST_URI"]);
		$tmpURI .= (strpos($tmpURI, '?')) ? "&" : "?";
		$toggle = ($this->hideSeedless === "yes")
			? "<a href=\"".htmlspecialchars($tmpURI)."hideSeedless=no\">Show Seedless</a>"
			: "<a href=\"".htmlspecialchars($tmpURI)."hideSeedless=yes\">Hide Seedless</a>";

		$output = "<div style=\"margin:4px 0\">(".$toggle.")</div>\n";
		$output .= "<table width=\"100%\" cellpadding=\"3\" cellspacing=\"0\" border=\"0\">\n";
		$output .= "<tr bgcolor=\"".$this->cfg["table_header_bg"]."\">\n";
		$output .= "	<td colspan=\"2\"><strong>Name</strong></td>\n";
		$output .= "	<td><strong>Indexer</strong></td>\n";
		$output .= "	<td><strong>Category</strong></td>\n";
		$output .= "	<td align=\"right\"><strong>Size</strong></td>\n";
		$output .= "	<td align=\"center\"><strong>Added</strong></td>\n";
		$output .= "	<td align=\"center\"><strong>S</strong></td>\n";
		$output .= "	<td align=\"center\"><strong>L</strong></td>\n";
		$output .= "</tr>\n";
		return $output;
	}

	function buildRow($r, $bg) {
		$title = isset($r['title']) ? $r['title'] : '(no title)';
		$displayTitle = (strlen($title) > $this->maxDisplayLength)
			? substr($title, 0, $this->maxDisplayLength - 3).'...'
			: $title;
		$titleAttr = htmlspecialchars($title, ENT_QUOTES);
		$displayTitle = htmlspecialchars($displayTitle);

		// prefer the proxied .torrent download; fall back to magnet
		$dl = '';
		if (!empty($r['downloadUrl']))
			$dl = $this->rebaseUrl($r['downloadUrl']);
		elseif (!empty($r['magnetUrl']))
			$dl = $r['magnetUrl'];
		elseif (!empty($r['guid']) && preg_match('#^(https?|magnet):#', $r['guid']))
			$dl = $r['guid'];
		$addUrl = 'dispatcher.php?action=urlUpload&type=torrent&url='.urlencode($dl);

		$indexer = isset($r['indexer']) ? htmlspecialchars($r['indexer']) : '';
		$cat = '';
		if (!empty($r['categories']) && is_array($r['categories'])) {
			$first = $r['categories'][0];
			$cat = isset($first['name']) ? htmlspecialchars($first['name']) : '';
		}
		$size = isset($r['size']) ? $this->formatBytes($r['size']) : '';
		$date = '';
		if (!empty($r['publishDate'])) {
			$ts = strtotime($r['publishDate']);
			if ($ts !== false)
				$date = date('Y-m-d', $ts);
		}
		$seeds = isset($r['seeders']) ? intval($r['seeders']) : 0;
		$peers = isset($r['leechers']) ? intval($r['leechers']) : 0;
		$info = !empty($r['infoUrl']) ? htmlspecialchars($r['infoUrl']) : '';

		$output = "<tr>\n";
		if ($dl != '') {
			$output .= "	<td width=\"16\" bgcolor=\"".$bg."\"><a href=\"".$addUrl."\"><img src=\"".getImagesPath()."download_owner.gif\" width=\"16\" height=\"16\" title=\"Download ".$titleAttr."\" border=\"0\"></a></td>\n";
			$output .= "	<td bgcolor=\"".$bg."\"><a href=\"".$addUrl."\" title=\"".$titleAttr."\">".$displayTitle."</a>";
		} else {
			$output .= "	<td width=\"16\" bgcolor=\"".$bg."\"></td>\n";
			$output .= "	<td bgcolor=\"".$bg."\">".$displayTitle;
		}
		if ($info != '')
			$output .= " <a href=\"".$info."\" target=\"_blank\" title=\"Details\">&#8599;</a>";
		$output .= "</td>\n";
		$output .= "	<td bgcolor=\"".$bg."\">".$indexer."</td>\n";
		$output .= "	<td bgcolor=\"".$bg."\">".$cat."</td>\n";
		$output .= "	<td bgcolor=\"".$bg."\" align=\"right\" nowrap>".$size."</td>\n";
		$output .= "	<td bgcolor=\"".$bg."\" align=\"center\">".$date."</td>\n";
		$output .= "	<td bgcolor=\"".$bg."\" align=\"center\">".$seeds."</td>\n";
		$output .= "	<td bgcolor=\"".$bg."\" align=\"center\">".$peers."</td>\n";
		$output .= "</tr>\n";
		return $output;
	}

	function pager($resultCount) {
		$page = intval($this->pg);
		$base = $this->searchURL()
			.'&searchterm='.urlencode($this->searchTerm)
			.(($g = tfb_getRequestVar('mainGenre')) != '' ? '&mainGenre='.urlencode($g) : '')
			.(($ix = tfb_getRequestVar('indexer')) != '' ? '&indexer='.urlencode($ix) : '');
		$output = '<div style="margin:6px 0">';
		if ($page > 0)
			$output .= '<a href="'.$base.'&pg='.($page - 1).'">&laquo; Prev</a> ';
		$output .= 'Page '.($page + 1);
		if ($resultCount >= $this->pageSize)
			$output .= ' <a href="'.$base.'&pg='.($page + 1).'">Next &raquo;</a>';
		$output .= '</div>';
		return $output;
	}

	// Prowlarr builds download links from its own advertised host, which may
	// differ from the URL we reach it at (containers, reverse proxies).
	// Re-anchor proxied download links onto our configured base URL.
	function rebaseUrl($url) {
		$path = parse_url($url, PHP_URL_PATH);
		if ($path !== null && preg_match('#/download$#', (string)$path)) {
			$query = parse_url($url, PHP_URL_QUERY);
			return $this->baseURL.$path.($query ? '?'.$query : '');
		}
		return $url;
	}

	function formatBytes($bytes) {
		$bytes = floatval($bytes);
		if ($bytes <= 0) return '';
		$units = array('B', 'KiB', 'MiB', 'GiB', 'TiB');
		$i = min(intval(floor(log($bytes, 1024))), count($units) - 1);
		return round($bytes / pow(1024, $i), ($i > 1 ? 2 : 0)).' '.$units[$i];
	}
}

?>
