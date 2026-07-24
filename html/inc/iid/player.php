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

// prevent direct invocation
if ((!isset($cfg['user'])) || (isset($_REQUEST['cfg']))) {
	@ob_end_clean();
	@header("location: ../../index.php");
	exit();
}

require_once('inc/functions/functions.dir.php');

$dir = UrlHTMLSlashesDecode(tfb_getRequestVar('dir'));
$file = UrlHTMLSlashesDecode(tfb_getRequestVar('file'));
$rel = ($dir != "" && $dir != "/") ? rtrim($dir, '/')."/".$file : $file;

// validate access
if (($cfg["enable_file_download"] != 1)
	|| (!isValidEntry(basename($rel)))
	|| (!hasPermission($rel, $cfg["user"], 'r'))
	|| (!tfb_isValidPath($rel))) {
	AuditAction($cfg["constants"]["error"], "ILLEGAL PLAYER: ".$cfg["user"]." tried to play ".$rel);
	@error("Cannot play this file.", "index.php?iid=index", "");
}

$mime = streamMimeType(getExtension($file));
$isAudio = (strpos($mime, 'audio/') === 0);
$playable = ($mime != '');
$streamUrl = "index.php?iid=dir&stream=".rawurlencode($rel);
$downloadUrl = "index.php?iid=dir&down=".rawurlencode(UrlHTMLSlashesEncode($rel));
$title = htmlspecialchars($file, ENT_QUOTES);

@ob_end_clean();
header("Content-Type: text/html; charset=utf-8");
?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $title; ?></title>
<style>
	html,body { margin:0; height:100%; background:#141417; color:#d6d6d9;
		font-family:Verdana,Arial,sans-serif; font-size:13px; }
	.wrap { box-sizing:border-box; min-height:100%; display:flex; flex-direction:column;
		align-items:center; justify-content:center; padding:16px; }
	.name { margin:0 0 12px; color:#e2e2e6; word-break:break-all; text-align:center; max-width:900px; }
	video { max-width:100%; max-height:82vh; background:#000; border-radius:6px;
		box-shadow:0 4px 24px rgba(0,0,0,.6); }
	audio { width:min(520px,90vw); }
	.msg { max-width:520px; text-align:center; line-height:1.6; }
	a.btn { display:inline-block; margin-top:12px; padding:8px 16px; background:#8e1b2d;
		color:#fff; text-decoration:none; border-radius:4px; }
	a.btn:hover { background:#a92238; }
	a { color:#e66478; }
</style>
</head>
<body>
<div class="wrap">
	<p class="name"><?php echo $title; ?></p>
<?php if ($playable && $isAudio): ?>
	<audio controls autoplay preload="metadata" src="<?php echo htmlspecialchars($streamUrl, ENT_QUOTES); ?>">
		Your browser cannot play this audio.
	</audio>
<?php elseif ($playable): ?>
	<video controls autoplay preload="metadata" src="<?php echo htmlspecialchars($streamUrl, ENT_QUOTES); ?>">
		Your browser cannot play this video.
	</video>
<?php else: ?>
	<div class="msg">
		<p>This format can't be played directly in the browser.<br>
		Browsers only play <strong>mp4/webm</strong> video and <strong>mp3/ogg/flac</strong> audio natively &mdash;
		for other formats (e.g. mkv) use your media server (Emby/Jellyfin) or download the file.</p>
		<a class="btn" href="<?php echo htmlspecialchars($downloadUrl, ENT_QUOTES); ?>">Download</a>
	</div>
<?php endif; ?>
</div>
</body>
</html>
<?php
exit();
?>
