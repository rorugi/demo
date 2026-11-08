<?php
// RRnotes live sharing: a tiny relay next to notes.html.
// The notebook on the tablet sends its changes here; viewers fetch them (long polling).
// Everything stored here is encrypted in the browser with a key that never reaches this server
// (it stays after the # of the viewer link). Sessions are removed after a day.
//
// Requests (s = session id, 12–40 letters/digits):
//   POST ?s=..&a=push        body {"f":from,"d":data}  -> {"n":seq}        a change (or a viewer saying hello)
//   POST ?s=..&a=snap        body {"d":data}           -> {"n":seq}        the whole notebook; earlier records are dropped
//   GET  ?s=..&a=snap                                  -> {"n":seq,"d":..} or {"none":1}
//   GET  ?s=..&a=pull&after=N[&not=f]                  -> {"n":last,"r":[[seq,from,data],..]} or {"reset":1}
//   POST ?s=..&a=img&id=..   body data                 -> {"ok":1}         a picture used on the pages
//   GET  ?s=..&a=img&id=..                             -> data

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($a, $code = 200) { http_response_code($code); echo json_encode($a); exit; }

$s = isset($_GET['s']) ? $_GET['s'] : '';
if (!preg_match('/^[A-Za-z0-9]{12,40}$/', $s)) out(['e' => 'session'], 400);
$a = isset($_GET['a']) ? $_GET['a'] : '';

$dir = __DIR__ . '/live-sessions';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); }
if (!is_dir($dir) || !is_writable($dir)) out(['e' => 'storage'], 500);

if (mt_rand(1, 40) === 1) {                       // now and then: remove sessions older than a day
  foreach (glob($dir . '/*') as $f) if (is_file($f) && basename($f) !== '.htaccess' && filemtime($f) < time() - 86400) @unlink($f);
}

$log = "$dir/$s.log";            // lines: seq \t from \t data
$seqf = "$dir/$s.seq";           // the last seq
$basef = "$dir/$s.base";         // the log starts after this seq (older records were folded into the snapshot)
$snapf = "$dir/$s.snap";         // seq \n data
$MAX = 12 * 1024 * 1024;

function readSeq($f) { clearstatcache(true, $f); return is_file($f) ? (int)@file_get_contents($f) : 0; }
function body($max) {
  $b = file_get_contents('php://input', false, null, 0, $max + 1);
  if ($b === false || strlen($b) > $max) out(['e' => 'size'], 413);
  return $b;
}
function lockSession($dir, $s) { $l = fopen("$dir/$s.lock", 'c'); flock($l, LOCK_EX); return $l; }

if ($a === 'push' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $j = json_decode(body($MAX), true);
  if (!is_array($j) || !isset($j['f'], $j['d']) || !is_string($j['f']) || !is_string($j['d'])) out(['e' => 'bad'], 400);
  $from = preg_replace('/[^A-Za-z0-9:_-]/', '', substr($j['f'], 0, 40));
  $data = preg_replace('/[^A-Za-z0-9+\/=]/', '', $j['d']);
  $l = lockSession($dir, $s);
  $n = readSeq($seqf) + 1;
  file_put_contents($log, "$n\t$from\t$data\n", FILE_APPEND);
  file_put_contents($seqf, (string)$n);
  flock($l, LOCK_UN); fclose($l);
  out(['n' => $n]);
}

if ($a === 'snap' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $j = json_decode(body($MAX), true);
  if (!is_array($j) || !isset($j['d']) || !is_string($j['d'])) out(['e' => 'bad'], 400);
  $data = preg_replace('/[^A-Za-z0-9+\/=]/', '', $j['d']);
  $l = lockSession($dir, $s);
  $n = readSeq($seqf) + 1;                         // everything before is in the snapshot: viewers behind it reload it
  file_put_contents($snapf, "$n\n$data");
  file_put_contents($log, '');
  file_put_contents($seqf, (string)$n);
  file_put_contents($basef, (string)$n);
  flock($l, LOCK_UN); fclose($l);
  out(['n' => $n]);
}

if ($a === 'snap') {
  if (!is_file($snapf)) out(['none' => 1]);
  $raw = file_get_contents($snapf);
  $p = strpos($raw, "\n");
  out(['n' => (int)substr($raw, 0, $p), 'd' => substr($raw, $p + 1)]);
}

if ($a === 'pull') {
  $after = isset($_GET['after']) ? (int)$_GET['after'] : 0;
  $not = isset($_GET['not']) ? $_GET['not'] : '';
  @set_time_limit(40);
  if (function_exists('session_write_close')) @session_write_close();
  $until = microtime(true) + 20;                  // wait up to 20 s for something new
  do {
    if ($after < readSeq($basef)) out(['reset' => 1]);
    $last = readSeq($seqf);
    if ($last > $after) {
      $r = [];
      $fh = @fopen($log, 'r');
      if ($fh) {
        while (($line = fgets($fh)) !== false) {
          $t = explode("\t", rtrim($line, "\n"), 3);
          if (count($t) === 3 && (int)$t[0] > $after && $t[1] !== $not) $r[] = [(int)$t[0], $t[1], $t[2]];
        }
        fclose($fh);
      }
      if ($r) out(['n' => $last, 'r' => $r]);
      $after = $last;                             // only records we don't want: keep waiting for more
    }
    if (connection_aborted()) exit;
    usleep(250000);
  } while (microtime(true) < $until);
  out(['n' => $after, 'r' => []]);
}

if ($a === 'img') {
  $id = isset($_GET['id']) ? $_GET['id'] : '';
  if (!preg_match('/^[A-Za-z0-9]{6,64}$/', $id)) out(['e' => 'id'], 400);
  $f = "$dir/$s.img.$id";
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = preg_replace('/[^A-Za-z0-9+\/=]/', '', body($MAX));
    file_put_contents($f, $data);
    out(['ok' => 1]);
  }
  if (!is_file($f)) out(['e' => 'missing'], 404);
  header('Content-Type: text/plain');
  readfile($f);
  exit;
}

out(['e' => 'request'], 400);
