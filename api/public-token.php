<?php
/**
 * Entrega o token em JavaScript via PHP (Cloudflare costuma bloquear .js).
 */
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
if (!is_file(__DIR__ . '/config.php')) {
  echo "window.ACOMPANHA_API_TOKEN = '';\n";
  exit;
}
require __DIR__ . '/config.php';
$token = (defined('API_TOKEN') && API_TOKEN) ? API_TOKEN : '';
echo 'window.ACOMPANHA_API_TOKEN = ' . json_encode($token) . ";\n";
echo "window.ACOMPANHA_API_URL = window.ACOMPANHA_API_URL || 'api/index.php';\n";
