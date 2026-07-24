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
 * build a mktorrent command line (modern replacement for the Python 2
 * btmakemetafile.py / maketorrent-console.py tools).
 *
 * @param $source path to the file/dir to make a torrent of
 * @param $target output .torrent path
 * @param $announce primary tracker announce URL
 * @param $extraTrackers comma-separated backup trackers ("" if none)
 * @param $comment optional comment
 * @param $piecePow piece length as power of two (0 = auto)
 * @param $private make the torrent private
 * @return string shell command
 */
function tfMakeTorrentCmd($source, $target, $announce, $extraTrackers, $comment, $piecePow, $private) {
	global $cfg;
	$bin = (!empty($cfg['bin_mktorrent'])) ? $cfg['bin_mktorrent'] : 'mktorrent';
	$cmd = tfb_shellencode($bin)." -o ".tfb_shellencode($target);
	if (!empty($announce) && $announce != 'http://')
		$cmd .= " -a ".tfb_shellencode($announce);
	if (!empty($extraTrackers)) {
		foreach (explode(',', $extraTrackers) as $t) {
			$t = trim($t);
			if ($t != '' && $t != $announce)
				$cmd .= " -a ".tfb_shellencode($t);
		}
	}
	if (!empty($comment))
		$cmd .= " -c ".tfb_shellencode($comment);
	if (!empty($piecePow) && (int)$piecePow >= 15 && (int)$piecePow <= 28)
		$cmd .= " -l ".(int)$piecePow;
	if ($private)
		$cmd .= " -p";
	$cmd .= " ".tfb_shellencode($source)." 2>&1";
	return $cmd;
}

/**
 * run mktorrent and return the standard completed()/failed() JS callback.
 */
function tfRunMakeTorrent($cmd, $tfile, $alert) {
	global $cfg;
	@set_time_limit(0);
	$time_start = microtime(true);
	exec($cmd);
	$success = false;
	$raw = @file_get_contents($cfg["transfer_file_path"].$tfile);
	if ($raw !== false && preg_match("/6:pieces([^:]+):/i", $raw)) {
		$success = true;
		AuditAction($cfg["constants"]["file_upload"], $tfile);
	} else {
		if (@file_exists($cfg["transfer_file_path"].$tfile))
			@unlink($cfg["transfer_file_path"].$tfile);
	}
	$diff = duration(microtime(true) - $time_start);
	$downpath = urlencode($tfile);
	return ($success)
		? "completed('".$downpath."',".$alert.",'".$diff."');"
		: "failed('".$downpath."',".$alert.");";
}

/**
 * create torrent (mktorrent), "tornado"-style form fields
 *
 * @return string $onLoad
 */
function createTorrentTornado() {
	global $cfg, $path, $tfile, $announce, $ancelist, $comment, $piece, $alert, $private, $dht;
	// sanity-check
	if ((empty($announce)) || ($announce == "http://"))
		return;
	if (@file_exists($cfg["transfer_file_path"].$tfile))
		@unlink($cfg["transfer_file_path"].$tfile);
	$cmd = tfMakeTorrentCmd($cfg["path"].$path, $cfg["transfer_file_path"].$tfile,
		$announce, $ancelist, $comment, $piece, $private);
	return tfRunMakeTorrent($cmd, $tfile, $alert);
}

/**
 * create torrent (mktorrent), "mainline"-style form fields
 *
 * @return string $onLoad
 */
function createTorrentMainline() {
	global $cfg, $path, $tfile, $comment, $piece, $use_tracker, $tracker_name, $alert;
	if (@file_exists($cfg["transfer_file_path"].$tfile))
		@unlink($cfg["transfer_file_path"].$tfile);
	$cmd = tfMakeTorrentCmd($cfg["path"].$path, $cfg["transfer_file_path"].$tfile,
		$tracker_name, "", $comment, $piece, false);
	return tfRunMakeTorrent($cmd, $tfile, $alert);
}

/**
 * Strip the folders from the path
 *
 * @param $path
 * @return string
 */
function StripFolders($path) {
	$pos = strrpos($path, "/");
	$pos = ($pos === false) ? 0 : $pos + 1;
	$path = substr($path, $pos);
	return $path;
}

/**
 * Convert a timestamp to a duration string
 *
 * @param $timestamp
 * @return string
 */
function duration($timestamp) {
	$years = floor($timestamp / (60 * 60 * 24 * 365));
	$timestamp %= 60 * 60 * 24 * 365;
	$weeks = floor($timestamp / (60 * 60 * 24 * 7));
	$timestamp %= 60 * 60 * 24 * 7;
	$days = floor($timestamp / (60 * 60 * 24));
	$timestamp %= 60 * 60 * 24;
	$hrs = floor($timestamp / (60 * 60));
	$timestamp %= 60 * 60;
	$mins = floor($timestamp / 60);
	$secs = $timestamp % 60;
	$str = "";
	if ($years >= 1)
		$str .= "{$years} years ";
	if ($weeks >= 1)
		$str .= "{$weeks} weeks ";
	if ($days >= 1)
		$str .= "{$days} days ";
	if ($hrs >= 1)
		$str .= "{$hrs} hours ";
	if ($mins >= 1)
		$str .= "{$mins} minutes ";
	if ($secs >= 1)
		$str.="{$secs} seconds ";
	return $str;
}

?>