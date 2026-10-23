<?php
/**
 * Subprocess runner for appointment endpoint probes (CLI only).
 *
 * Usage: php endpoint_runner.php <endpoint-path-relative-to-repo-root> <input.json>
 *
 * input.json: {"session": {"UserID": n, "RoleName": "..."}, "post": {...}}
 *
 * Starts a real PHP session carrying the given identity, then includes the
 * endpoint exactly as a web request would, capturing its JSON output.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '1');

if ($argc < 3) {
    fwrite(STDERR, "usage: endpoint_runner.php <endpoint> <input.json>\n");
    exit(2);
}

$endpoint = $argv[1];
$inputFile = $argv[2];

$input = json_decode((string) file_get_contents($inputFile), true);
if (!is_array($input) || !isset($input['session'])) {
    fwrite(STDERR, "bad input json\n");
    exit(2);
}

session_id('probe' . bin2hex(random_bytes(8)));
session_start();
$_SESSION = $input['session'];
session_write_close();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = $input['post'] ?? [];
$_GET = [];
$_REQUEST = $_POST;

$abs = dirname(__DIR__) . '/' . $endpoint;
if (!is_file($abs)) {
    fwrite(STDERR, "endpoint not found: $abs\n");
    exit(2);
}

ob_start();
include $abs;
// The endpoint echoes JSON and calls exit(); the buffer flushes on shutdown.
