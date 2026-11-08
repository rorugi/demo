<?php
// RRnotes live sharing relay. Put this file next to notes.html.
//
// The notebook on the tablet sends its changes here; viewers fetch them. Everything stored here is
// encrypted in the browser (AES-GCM) with a key that never reaches this server: it stays after the #
// of the viewer link. This file only passes opaque data on and keeps it as short as possible.
//
// Security:
//  - Only the device that started a session can change it: it holds a secret write token (sent in a
//    header, so other web sites cannot send it either); only its SHA-256 hash is stored here.
//  - Viewers can only read, and say "I am watching" (counted, nothing else is stored).
//  - Optional password for starting a session ($PASSWORD below), so strangers cannot use the server.
//  - Ids are checked strictly, sizes and the number of sessions are limited, everything is removed
//    when sharing stops or after a day without activity.
//  - Data is kept in $DATA (default: rrlive-data next to this file, closed by .htaccess for Apache).
//    On nginx or when possible, better point $DATA to a folder outside the web root.

// ---- settings ----
$PASSWORD     = '';                          // e.g. 'my secret': needed once on the tablet to start sharing
$DATA         = __DIR__ . '/rrlive-data';    // where sessions are kept (must be writable by PHP)
$MAX_BODY     = 12 * 1024 * 1024;            // largest single request (a snapshot or a picture)
$MAX_LOG      = 16 * 1024 * 1024;            // changes kept per session before the tablet sends a new snapshot
$MAX_SESSION  = 200 * 1024 * 1024;           // all data of one session (pictures included)
$MAX_TOTAL    = 1024 * 1024 * 1024;          // all sessions together
$MAX_SESSIONS = 30;                          // sessions at the same time
$IDLE         = 86400;                       // a session without activity is removed after this many seconds
$WAIT         = 20;                          // a viewer's request waits this long for something new

// ---- request ----
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');

function out($a, $code = 200) { http_response_code($code); echo json_encode($a); exit; }
function hdr($name) { $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name)); return isset($_SERVER[$k]) ? (string)$_SERVER[$k] : ''; }
function arg($k) { return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : ''; }
function seqOf($f) { clearstatcache(true, $f); return is_file($f) ? (int)@file_get_contents($f) : 0; }
function b64only($s) { return preg_replace('/[^A-Za-z0-9+\/=]/', '', $s); }
function body($max) {
  $len = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
  if ($len > $max) out(['e' => 'size'], 413);
  $b = file_get_contents('php://input', false, null, 0, $max + 1);
  if ($b === false || strlen($b) > $max) out(['e' => 'size'], 413);
  return $b;
}
function bytes($glob) { $n = 0; foreach (glob($glob) ?: [] as $f) $n += (int)@filesize($f); return $n; }

$method = $_SERVER['REQUEST_METHOD'];
$s = arg('s');
$a = arg('a');
if (!preg_match('/^[A-Za-z0-9]{16,40}$/', $s)) out(['e' => 'session'], 400);
if (!in_array($a, ['snap', 'push', 'pull', 'img', 'hi', 'who', 'end'], true)) out(['e' => 'request'], 400);

if (!is_dir($DATA)) @mkdir($DATA, 0700, true);
if (!is_dir($DATA) || !is_writable($DATA)) out(['e' => 'storage'], 500);
if (!is_file("$DATA/.htaccess")) @file_put_contents("$DATA/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
if (!is_file("$DATA/index.html")) @file_put_contents("$DATA/index.html", '');

// now and then: remove sessions without activity (and the marks of ended ones)
if (mt_rand(1, 30) === 1) {
  foreach (glob("$DATA/*.tok") ?: [] as $t) {
    $id = basename($t, '.tok');
    $last = max((int)@filemtime("$DATA/$id.seq"), (int)@filemtime($t));
    if ($last < time() - $IDLE) foreach (glob("$DATA/$id.*") ?: [] as $f) @unlink($f);
  }
  foreach (glob("$DATA/*.end") ?: [] as $f) if (filemtime($f) < time() - $IDLE) @unlink($f);
}

$P = "$DATA/$s";                 // $P.tok   sha256 of the write token
                                 // $P.seq   number of the last record
                                 // $P.base  the log starts after this record (older ones are in the snapshot)
                                 // $P.log   records: seq \t data
                                 // $P.snap  seq \n data (the whole notebook)
                                 // $P.who   viewers: id -> last seen
                                 // $P.img.<id>  pictures
                                 // $P.end   sharing was stopped
$exists = is_file("$P.tok");

function lockS($P, $mode) { $l = fopen("$P.lock", 'c'); if (!$l) out(['e' => 'storage'], 500); flock($l, $mode); return $l; }
function unlockS($l) { flock($l, LOCK_UN); fclose($l); }
function owner($P) {             // the device that started the session
  $t = hdr('X-RR-Token');
  if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $t)) out(['e' => 'token'], 403);
  $h = @file_get_contents("$P.tok");
  if ($h === false || !hash_equals(trim($h), hash('sha256', $t))) out(['e' => 'token'], 403);
}

// ---- the tablet: start a session / send the whole notebook ----
if ($a === 'snap' && $method === 'POST') {
  if (!$exists) {
    if ($PASSWORD !== '' && !hash_equals(hash('sha256', $PASSWORD), hash('sha256', hdr('X-RR-Pass')))) { usleep(500000); out(['e' => 'password'], 401); }
    $t = hdr('X-RR-Token');
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $t)) out(['e' => 'token'], 403);
    if (count(glob("$DATA/*.tok") ?: []) >= $MAX_SESSIONS || bytes("$DATA/*") > $MAX_TOTAL) out(['e' => 'full'], 507);
    @unlink("$P.end");
    if (@file_put_contents("$P.tok", hash('sha256', $t), LOCK_EX) === false) out(['e' => 'storage'], 500);
    @chmod("$P.tok", 0600);
  } else owner($P);
  $data = b64only(body($MAX_BODY));
  if ($data === '') out(['e' => 'bad'], 400);
  if (bytes("$P.*") + strlen($data) > $MAX_SESSION || bytes("$DATA/*") + strlen($data) > $MAX_TOTAL) out(['e' => 'full'], 507);
  $l = lockS($P, LOCK_EX);
  $n = seqOf("$P.seq") + 1;                       // everything before is in the snapshot
  file_put_contents("$P.snap", "$n\n$data");
  file_put_contents("$P.log", '');
  file_put_contents("$P.seq", (string)$n);
  file_put_contents("$P.base", (string)$n);
  unlockS($l);
  out(['n' => $n]);
}

// ---- the tablet: one change ----
if ($a === 'push' && $method === 'POST') {
  if (!$exists) out(['e' => 'gone'], 404);
  owner($P);
  $data = b64only(body($MAX_BODY));
  if ($data === '') out(['e' => 'bad'], 400);
  clearstatcache();
  if ((int)@filesize("$P.log") + strlen($data) > $MAX_LOG) out(['e' => 'log full'], 409);   // the tablet then sends a snapshot
  $l = lockS($P, LOCK_EX);
  $n = seqOf("$P.seq") + 1;
  file_put_contents("$P.log", "$n\t$data\n", FILE_APPEND);
  file_put_contents("$P.seq", (string)$n);
  unlockS($l);
  out(['n' => $n]);
}

// ---- the tablet: a picture used on the pages ----
if ($a === 'img' && $method === 'POST') {
  if (!$exists) out(['e' => 'gone'], 404);
  owner($P);
  $id = arg('id');
  if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) out(['e' => 'id'], 400);
  $data = b64only(body($MAX_BODY));
  if (bytes("$P.*") + strlen($data) > $MAX_SESSION || bytes("$DATA/*") + strlen($data) > $MAX_TOTAL) out(['e' => 'full'], 507);
  file_put_contents("$P.img.$id", $data, LOCK_EX);
  out(['ok' => 1]);
}

// ---- the tablet: how many are watching ----
if ($a === 'who') {
  if (!$exists) out(['e' => 'gone'], 404);
  owner($P);
  $w = json_decode((string)@file_get_contents("$P.who"), true);
  $c = 0; if (is_array($w)) foreach ($w as $t) if ($t > time() - 70) $c++;
  out(['w' => $c]);
}

// ---- the tablet: stop sharing, remove everything ----
if ($a === 'end' && $method === 'POST') {
  if (!$exists) out(['ok' => 1]);
  owner($P);
  $l = lockS($P, LOCK_EX);
  foreach (glob("$P.*") ?: [] as $f) if (substr($f, -5) !== '.lock') @unlink($f);
  touch("$P.end");
  unlockS($l);
  @unlink("$P.lock");
  out(['ok' => 1]);
}

// ---- viewers ----
if (is_file("$P.end")) out(['ended' => 1]);

if ($a === 'hi' && $method === 'POST') {          // "I am watching" (only counted)
  if (!$exists) out(['ok' => 1]);
  $v = arg('v');
  if (!preg_match('/^[A-Za-z0-9]{6,24}$/', $v)) out(['e' => 'id'], 400);
  $l = lockS($P, LOCK_EX);
  $w = json_decode((string)@file_get_contents("$P.who"), true);
  if (!is_array($w)) $w = [];
  foreach ($w as $k => $t) if ($t < time() - 70) unset($w[$k]);
  if (count($w) < 200 || isset($w[$v])) $w[$v] = time();
  file_put_contents("$P.who", json_encode($w));
  unlockS($l);
  out(['ok' => 1]);
}

if ($a === 'snap') {
  if (!$exists || !is_file("$P.snap")) out(['none' => 1]);
  $l = lockS($P, LOCK_SH);
  $raw = (string)@file_get_contents("$P.snap");
  unlockS($l);
  $p = strpos($raw, "\n");
  if ($p === false) out(['none' => 1]);
  out(['n' => (int)substr($raw, 0, $p), 'd' => substr($raw, $p + 1)]);
}

if ($a === 'img') {
  $id = arg('id');
  if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) out(['e' => 'id'], 400);
  if (!is_file("$P.img.$id")) out(['e' => 'missing'], 404);
  header('Content-Type: text/plain; charset=us-ascii');
  readfile("$P.img.$id");
  exit;
}

if ($a === 'pull') {                               // changes after record N, waiting a while for new ones
  $after = max(0, (int)arg('after'));
  @set_time_limit($WAIT + 20);
  ignore_user_abort(false);
  $until = microtime(true) + $WAIT;
  do {
    if (is_file("$P.end")) out(['ended' => 1]);
    if (!$exists) { clearstatcache(); $exists = is_file("$P.tok"); }
    if ($exists) {
      if ($after < seqOf("$P.base")) out(['reset' => 1]);
      $last = seqOf("$P.seq");
      if ($last > $after) {
        $r = [];
        $l = lockS($P, LOCK_SH);
        $fh = @fopen("$P.log", 'r');
        if ($fh) {
          while (($line = fgets($fh)) !== false) {
            $t = explode("\t", rtrim($line, "\n"), 2);
            if (count($t) === 2 && (int)$t[0] > $after) $r[] = [(int)$t[0], $t[1]];
          }
          fclose($fh);
        }
        unlockS($l);
        if ($r) out(['n' => $last, 'r' => $r]);
        if ($after < seqOf("$P.base")) out(['reset' => 1]);
      }
    }
    echo ' '; @ob_flush(); @flush();               // lets PHP notice a viewer that went away
    if (connection_aborted()) exit;
    usleep(300000);
  } while (microtime(true) < $until);
  out(['n' => $after, 'r' => []]);
}

out(['e' => 'request'], 400);
