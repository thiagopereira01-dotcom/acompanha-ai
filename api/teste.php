<?php
/**
 * Teste rápido da pasta api/ na Hostinger.
 * Abra: https://seudominio/api/teste.php
 * Apague depois.
 */
header('Content-Type: text/plain; charset=utf-8');
echo "Acompanha-Aí · teste api\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "mysqli: " . ((extension_loaded('mysqli') || class_exists('mysqli')) ? 'OK' : 'AUSENTE') . "\n";
echo "pasta gravavel: " . (is_writable(__DIR__) ? 'SIM' : 'NAO') . "\n";
echo "config.php: " . (is_file(__DIR__ . '/config.php') ? 'existe' : 'nao existe') . "\n";
echo "public-token.js: " . (is_file(__DIR__ . '/public-token.js') ? 'existe' : 'nao existe') . "\n";

if (is_file(__DIR__ . '/config.php')) {
  if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
  require __DIR__ . '/config.php';
  if (!defined('DB_HOST')) {
    echo "config: incompleto\n";
    exit;
  }
  try {
    $m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($m->connect_errno) {
      echo "mysql: FALHA " . $m->connect_error . "\n";
    } else {
      echo "mysql: CONECTOU\n";
      $m->close();
    }
  } catch (Exception $e) {
    echo "mysql: EXCECAO " . $e->getMessage() . "\n";
  } catch (Throwable $e) {
    echo "mysql: EXCECAO " . $e->getMessage() . "\n";
  }
} else {
  echo "mysql: (sem config ainda)\n";
}
echo "OK — apague este arquivo depois.\n";
