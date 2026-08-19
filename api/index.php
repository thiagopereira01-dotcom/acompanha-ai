<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function json_out($data, $code = 200) {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

set_exception_handler(function ($e) {
  json_out(array('ok' => false, 'erro' => 'excecao', 'detalhe' => $e->getMessage()), 500);
});

function cors_origens_permitidas() {
  if (!defined('API_CORS_ORIGINS') || API_CORS_ORIGINS === '') {
    return array();
  }
  $parts = preg_split('/[\s,]+/', API_CORS_ORIGINS, -1, PREG_SPLIT_NO_EMPTY);
  return $parts ? $parts : array();
}

function aplicar_cors() {
  $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim($_SERVER['HTTP_ORIGIN']) : '';
  $permitidas = cors_origens_permitidas();
  if (!$permitidas) {
    return;
  }
  $liberar = false;
  $usarEstrela = false;
  $originNorm = rtrim($origin, '/');
  foreach ($permitidas as $p) {
    $p = trim($p);
    if ($p === '*') {
      $liberar = true;
      $usarEstrela = true;
      break;
    }
    if ($originNorm !== '' && strcasecmp(rtrim($p, '/'), $originNorm) === 0) {
      $liberar = true;
      break;
    }
  }
  if (!$liberar) {
    return;
  }
  if ($usarEstrela && $origin === '') {
    header('Access-Control-Allow-Origin: *');
  } else {
    header('Access-Control-Allow-Origin: ' . ($origin !== '' ? $origin : '*'));
    header('Vary: Origin');
  }
  header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Accept, X-Api-Token');
  header('Access-Control-Max-Age: 86400');
}

if (!is_file(__DIR__ . '/config.php')) {
  json_out(array('ok' => false, 'erro' => 'nao_configurado'), 503);
}

require __DIR__ . '/config.php';
aplicar_cors();

$action = isset($_GET['action']) ? $_GET['action'] : 'ping';
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function ler_token_recebido() {
  if (isset($_GET['token']) && $_GET['token'] !== '') {
    return (string) $_GET['token'];
  }
  if (isset($_SERVER['HTTP_X_API_TOKEN']) && $_SERVER['HTTP_X_API_TOKEN'] !== '') {
    return (string) $_SERVER['HTTP_X_API_TOKEN'];
  }
  if (isset($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
    return trim(substr($_SERVER['HTTP_AUTHORIZATION'], 7));
  }
  if (function_exists('getallheaders')) {
    $headers = getallheaders();
    if (is_array($headers)) {
      foreach ($headers as $k => $v) {
        $lk = strtolower($k);
        if ($lk === 'x-api-token' && $v !== '') {
          return (string) $v;
        }
        if ($lk === 'authorization' && stripos($v, 'Bearer ') === 0) {
          return trim(substr($v, 7));
        }
      }
    }
  }
  return '';
}

function api_token_ok() {
  if (!defined('API_TOKEN') || API_TOKEN === '') {
    return true;
  }
  $recebido = ler_token_recebido();
  if ($recebido === '') {
    return false;
  }
  return hash_equals(API_TOKEN, $recebido);
}

function exigir_token() {
  if (!api_token_ok()) {
    json_out(array('ok' => false, 'erro' => 'token_invalido'), 401);
  }
}

function db_connect() {
  if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
    json_out(array('ok' => false, 'erro' => 'config_incompleta'), 500);
  }
  // PHP 8+ pode lançar exceção na conexão
  if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
  }
  try {
    $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
  } catch (Exception $e) {
    json_out(array('ok' => false, 'erro' => 'db_conexao', 'detalhe' => $e->getMessage()), 500);
  } catch (Throwable $e) {
    json_out(array('ok' => false, 'erro' => 'db_conexao', 'detalhe' => $e->getMessage()), 500);
  }
  if (!$mysqli || $mysqli->connect_error) {
    json_out(array('ok' => false, 'erro' => 'db_conexao'), 500);
  }
  $mysqli->set_charset('utf8mb4');
  return $mysqli;
}

function garantir_tabela($mysqli) {
  $sql = "CREATE TABLE IF NOT EXISTS acompanha_store (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    payload LONGTEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  if (!$mysqli->query($sql)) {
    json_out(array('ok' => false, 'erro' => 'db_tabela'), 500);
  }
}

function ler_store($mysqli) {
  $res = $mysqli->query('SELECT payload, version, updated_at FROM acompanha_store WHERE id = 1 LIMIT 1');
  if (!$res) {
    json_out(array('ok' => false, 'erro' => 'db_leitura'), 500);
  }
  $row = $res->fetch_assoc();
  $res->free();
  if (!$row) {
    return array(
      'payload' => null,
      'version' => 0,
      'updated_at' => null
    );
  }
  $data = json_decode($row['payload'], true);
  return array(
    'payload' => is_array($data) ? $data : null,
    'version' => (int) $row['version'],
    'updated_at' => $row['updated_at']
  );
}

function contar_totais_payload($data) {
  if (!is_array($data)) {
    return array('usuarios' => 0, 'alunos' => 0, 'ocorrencias' => 0, 'score' => 0);
  }
  $u = (isset($data['usuarios']) && is_array($data['usuarios'])) ? count($data['usuarios']) : 0;
  $a = (isset($data['alunos']) && is_array($data['alunos'])) ? count($data['alunos']) : 0;
  $o = (isset($data['ocorrencias']) && is_array($data['ocorrencias'])) ? count($data['ocorrencias']) : 0;
  return array(
    'usuarios' => $u,
    'alunos' => $a,
    'ocorrencias' => $o,
    'score' => ($u * 2) + ($a * 5) + ($o * 3)
  );
}

function payload_e_semente($data) {
  $t = contar_totais_payload($data);
  if ($t['alunos'] > 0 || $t['ocorrencias'] > 0) return false;
  if ($t['usuarios'] > 1) return false;
  if ($t['usuarios'] === 0) return true;
  $lista = $data['usuarios'];
  $u = $lista[0];
  $login = isset($u['usuario']) ? strtolower((string) $u['usuario']) : '';
  $id = isset($u['id']) ? (string) $u['id'] : '';
  return ($login === 'admin' || $id === 'u_admin');
}

if ($action === 'ping') {
  $mysqli = db_connect();
  garantir_tabela($mysqli);
  $store = ler_store($mysqli);
  $mysqli->close();
  json_out(array(
    'ok' => true,
    'precisaToken' => defined('API_TOKEN') && API_TOKEN !== '',
    'token' => (defined('API_TOKEN') ? API_TOKEN : ''),
    'version' => $store['version'],
    'updated_at' => $store['updated_at']
  ));
}

if ($action === 'version') {
  exigir_token();
  $mysqli = db_connect();
  garantir_tabela($mysqli);
  $store = ler_store($mysqli);
  $mysqli->close();
  json_out(array(
    'ok' => true,
    'version' => $store['version'],
    'updated_at' => $store['updated_at']
  ));
}

if ($action === 'get') {
  exigir_token();
  $mysqli = db_connect();
  garantir_tabela($mysqli);
  $store = ler_store($mysqli);
  $mysqli->close();
  json_out(array(
    'ok' => true,
    'version' => $store['version'],
    'updated_at' => $store['updated_at'],
    'data' => $store['payload']
  ));
}

if ($action === 'save' && ($method === 'POST' || $method === 'PUT')) {
  exigir_token();
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
    json_out(array('ok' => false, 'erro' => 'json_invalido'), 400);
  }

  $clientVersion = isset($body['version']) ? (int) $body['version'] : 0;
  $payload = $body['data'];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    json_out(array('ok' => false, 'erro' => 'json_encode'), 400);
  }

  $mysqli = db_connect();
  garantir_tabela($mysqli);
  $mysqli->begin_transaction();

  $res = $mysqli->query('SELECT payload, version, updated_at FROM acompanha_store WHERE id = 1 LIMIT 1 FOR UPDATE');
  if (!$res) {
    $mysqli->rollback();
    $mysqli->close();
    json_out(array('ok' => false, 'erro' => 'db_leitura'), 500);
  }
  $row = $res->fetch_assoc();
  $res->free();

  $currentVersion = $row ? (int) $row['version'] : 0;
  $atual = null;
  if ($row) {
    $atual = json_decode($row['payload'], true);
    if (!is_array($atual)) $atual = null;
  }

  if ($row && $clientVersion !== $currentVersion) {
    $mysqli->rollback();
    $mysqli->close();
    json_out(array(
      'ok' => false,
      'erro' => 'conflito',
      'version' => $currentVersion,
      'updated_at' => $row['updated_at'],
      'data' => $atual
    ), 409);
  }

  // Proteção: nunca deixar um PC novo (base vazia) apagar a base da escola.
  if ($atual !== null) {
    $totAtual = contar_totais_payload($atual);
    $totNovo = contar_totais_payload($payload);
    $novoSemente = payload_e_semente($payload);
    $atualTemDados = !payload_e_semente($atual);

    if ($atualTemDados && $novoSemente) {
      $mysqli->rollback();
      $mysqli->close();
      json_out(array(
        'ok' => false,
        'erro' => 'recusado_semente',
        'version' => $currentVersion,
        'updated_at' => $row['updated_at'],
        'data' => $atual,
        'msg' => 'Envio vazio/padrão recusado para não apagar a base.'
      ), 409);
    }

    if ($atualTemDados && $totNovo['score'] < $totAtual['score']) {
      // Permite redução só se o cliente mandar removidos e ainda restar algo coerente
      // (exclusões reais). Bloqueia queda brusca típica de PC novo.
      $quedaBrusca = (
        ($totAtual['alunos'] > 0 && $totNovo['alunos'] === 0) ||
        ($totAtual['ocorrencias'] > 2 && $totNovo['ocorrencias'] === 0) ||
        ($totAtual['usuarios'] > 1 && $totNovo['usuarios'] <= 1)
      );
      if ($quedaBrusca) {
        $mysqli->rollback();
        $mysqli->close();
        json_out(array(
          'ok' => false,
          'erro' => 'recusado_perda',
          'version' => $currentVersion,
          'updated_at' => $row['updated_at'],
          'data' => $atual,
          'msg' => 'Envio com perda de dados recusado.'
        ), 409);
      }
    }
  }

  $newVersion = $currentVersion + 1;
  $now = gmdate('Y-m-d H:i:s');

  if ($row) {
    $stmt = $mysqli->prepare('UPDATE acompanha_store SET payload = ?, version = ?, updated_at = ? WHERE id = 1');
  } else {
    $stmt = $mysqli->prepare('INSERT INTO acompanha_store (id, payload, version, updated_at) VALUES (1, ?, ?, ?)');
  }
  if (!$stmt) {
    $mysqli->rollback();
    $mysqli->close();
    json_out(array('ok' => false, 'erro' => 'db_prepare'), 500);
  }
  $stmt->bind_param('sis', $json, $newVersion, $now);
  if (!$stmt->execute()) {
    $stmt->close();
    $mysqli->rollback();
    $mysqli->close();
    json_out(array('ok' => false, 'erro' => 'db_gravacao'), 500);
  }
  $stmt->close();
  $mysqli->commit();
  $mysqli->close();

  json_out(array(
    'ok' => true,
    'version' => $newVersion,
    'updated_at' => $now
  ));
}

json_out(array('ok' => false, 'erro' => 'acao_invalida'), 404);
