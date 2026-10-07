<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
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
if (is_file(__DIR__ . '/webpush.lib.php')) {
  require_once __DIR__ . '/webpush.lib.php';
}
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

function garantir_tabelas_push($mysqli) {
  try {
    $vapid = "CREATE TABLE IF NOT EXISTS acompanha_push_vapid (
      id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
      public_key VARCHAR(255) NOT NULL,
      private_d VARCHAR(255) NOT NULL,
      subject VARCHAR(255) NOT NULL,
      updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $subs = "CREATE TABLE IF NOT EXISTS acompanha_push_subs (
      endpoint_hash CHAR(64) NOT NULL PRIMARY KEY,
      user_id VARCHAR(64) NOT NULL,
      escola_id VARCHAR(64) NOT NULL DEFAULT '',
      endpoint TEXT NOT NULL,
      p256dh VARCHAR(255) NOT NULL,
      auth_key VARCHAR(255) NOT NULL,
      user_agent VARCHAR(255) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$mysqli->query($vapid) || !$mysqli->query($subs)) {
      return false;
    }
    @$mysqli->query("ALTER TABLE acompanha_push_subs ADD INDEX idx_user (user_id)");
    @$mysqli->query("ALTER TABLE acompanha_push_subs ADD INDEX idx_escola (escola_id)");
    return true;
  } catch (Exception $e) {
    return false;
  } catch (Throwable $e) {
    return false;
  }
}

function webpush_assunto_padrao() {
  $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^a-zA-Z0-9.-]/', '', (string) $_SERVER['HTTP_HOST']) : 'localhost';
  if ($host === '') $host = 'localhost';
  return 'mailto:nao-responda@' . $host;
}

function webpush_obter_vapid($mysqli) {
  if (!function_exists('webpush_gerar_par_vapid')) return null;
  garantir_tabelas_push($mysqli);
  $res = $mysqli->query('SELECT public_key, private_d, subject FROM acompanha_push_vapid WHERE id = 1 LIMIT 1');
  $row = $res ? $res->fetch_assoc() : null;
  if ($res) $res->free();
  if ($row && !empty($row['public_key']) && !empty($row['private_d'])) {
    $raw = webpush_b64url_decode($row['public_key']);
    return array(
      'publicKey' => $row['public_key'],
      'privateD' => $row['private_d'],
      'publicRaw' => $raw,
      'subject' => $row['subject'] !== '' ? $row['subject'] : webpush_assunto_padrao()
    );
  }
  $par = webpush_gerar_par_vapid();
  if (!$par) return null;
  $subject = webpush_assunto_padrao();
  $stmt = $mysqli->prepare('REPLACE INTO acompanha_push_vapid (id, public_key, private_d, subject, updated_at) VALUES (1, ?, ?, ?, NOW())');
  if (!$stmt) return null;
  $stmt->bind_param('sss', $par['publicKey'], $par['privateD'], $subject);
  $ok = $stmt->execute();
  $stmt->close();
  if (!$ok) return null;
  $par['subject'] = $subject;
  return $par;
}

function webpush_hash_endpoint($endpoint) {
  return hash('sha256', $endpoint);
}

function webpush_ids_destinatarios($payload, $oc, $excludeUserId, $extraIds = array()) {
  $ids = array();
  $exclude = (string) $excludeUserId;
  $tutorId = isset($oc['tutorId']) ? trim((string) $oc['tutorId']) : '';
  $add = function ($uid) use (&$ids, $exclude, $tutorId) {
    $uid = trim((string) $uid);
    if ($uid === '') return;
    if ($exclude !== '' && $uid === $exclude && $uid !== $tutorId) return;
    $ids[$uid] = true;
  };

  if ($tutorId !== '') $add($tutorId);

  $nivel = isset($oc['nivel']) ? (string) $oc['nivel'] : 'verde';
  $escolaId = isset($oc['escolaId']) ? (string) $oc['escolaId'] : '';
  $tutorNome = isset($oc['tutorNome']) ? trim((string) $oc['tutorNome']) : '';
  $usuarios = (isset($payload['usuarios']) && is_array($payload['usuarios'])) ? $payload['usuarios'] : array();
  foreach ($usuarios as $u) {
    if (!is_array($u)) continue;
    if (isset($u['ativo']) && $u['ativo'] === false) continue;
    $uid = isset($u['id']) ? (string) $u['id'] : '';
    if ($uid === '') continue;
    $uEsc = isset($u['escolaId']) ? (string) $u['escolaId'] : '';
    if ($escolaId !== '' && $uEsc !== '' && $uEsc !== $escolaId) continue;
    $role = isset($u['role']) ? (string) $u['role'] : '';
    $ehTutor = !empty($u['tutor']);
    $nome = isset($u['nome']) ? trim((string) $u['nome']) : '';
    if ($tutorId !== '' && $uid === $tutorId) $add($uid);
    if ($tutorNome !== '' && $ehTutor && strcasecmp($nome, $tutorNome) === 0) $add($uid);
    if (($nivel === 'amarelo' || $nivel === 'vermelho') && $role === 'admin') $add($uid);
  }
  if (is_array($extraIds)) {
    foreach ($extraIds as $id) $add($id);
  }
  return array_keys($ids);
}

function webpush_payload_ocorrencia($oc) {
  $nivel = isset($oc['nivel']) ? (string) $oc['nivel'] : '';
  $label = $nivel === 'vermelho' ? 'Vermelho' : ($nivel === 'amarelo' ? 'Amarelo' : 'Verde');
  $aluno = isset($oc['aluno']) ? (string) $oc['aluno'] : 'Aluno';
  $turma = isset($oc['turma']) ? (string) $oc['turma'] : '';
  $protocolo = isset($oc['protocolo']) ? (string) $oc['protocolo'] : '';
  $corpo = $aluno;
  if ($turma !== '') $corpo .= ' · ' . $turma;
  if ($protocolo !== '') $corpo .= ' · ' . $protocolo;
  $urgencia = $nivel === 'vermelho' ? 'atendimento imediato' : 'precisa da sua atenção';
  return json_encode(array(
    'title' => 'Acompanha-Aí · ' . $label,
    'body' => $corpo . ' — ' . $urgencia,
    'protocolo' => $protocolo,
    'nivel' => $nivel,
    'url' => '/?ocorrencia=' . rawurlencode($protocolo)
  ), JSON_UNESCAPED_UNICODE);
}

function webpush_notificar_ocorrencia($mysqli, $payload, $oc, $excludeUserId, $extraIds = array()) {
  if (!function_exists('webpush_enviar')) {
    return array('ok' => false, 'erro' => 'push_nao_instalado', 'enviados' => 0);
  }
  garantir_tabelas_push($mysqli);
  $ids = webpush_ids_destinatarios($payload, $oc, $excludeUserId, $extraIds);
  if (!$ids) {
    return array('ok' => true, 'enviados' => 0, 'destinatarios' => 0);
  }
  $vapid = webpush_obter_vapid($mysqli);
  if (!$vapid) {
    return array('ok' => false, 'erro' => 'vapid_indisponivel', 'enviados' => 0);
  }
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('s', count($ids));
  $sql = 'SELECT endpoint_hash, endpoint, p256dh, auth_key FROM acompanha_push_subs WHERE user_id IN (' . $placeholders . ')';
  $stmt = $mysqli->prepare($sql);
  if (!$stmt) {
    return array('ok' => false, 'erro' => 'db_subs', 'enviados' => 0);
  }
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $subs = array();
  $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : false;
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) $subs[] = $row;
  } else {
    $h = $ep = $p = $a = '';
    $stmt->bind_result($h, $ep, $p, $a);
    while ($stmt->fetch()) {
      $subs[] = array(
        'endpoint_hash' => $h,
        'endpoint' => $ep,
        'p256dh' => $p,
        'auth_key' => $a
      );
    }
  }
  $stmt->close();
  if (!$subs) {
    return array('ok' => true, 'enviados' => 0, 'destinatarios' => count($ids));
  }

  $json = webpush_payload_ocorrencia($oc);
  $urgency = (isset($oc['nivel']) && $oc['nivel'] === 'vermelho') ? 'high' : 'normal';
  $enviados = 0;
  $mortas = array();
  $erros = array();
  foreach ($subs as $sub) {
    $r = webpush_enviar($sub['endpoint'], $sub['p256dh'], $sub['auth_key'], $json, $vapid, $urgency);
    if (!empty($r['ok'])) {
      $enviados++;
    } else {
      $erros[] = isset($r['erro']) ? $r['erro'] : 'falha';
      if (isset($r['http']) && ($r['http'] === 404 || $r['http'] === 410)) {
        $mortas[] = $sub['endpoint_hash'];
      }
    }
  }
  if ($mortas) {
    $ph = implode(',', array_fill(0, count($mortas), '?'));
    $del = $mysqli->prepare('DELETE FROM acompanha_push_subs WHERE endpoint_hash IN (' . $ph . ')');
    if ($del) {
      $dt = str_repeat('s', count($mortas));
      $del->bind_param($dt, ...$mortas);
      $del->execute();
      $del->close();
    }
  }
  return array(
    'ok' => true,
    'enviados' => $enviados,
    'destinatarios' => count($ids),
    'inscritos' => count($subs),
    'erros' => array_values(array_unique($erros))
  );
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
  return ($login === 'admin' || $login === 'superadmin' || $id === 'u_admin' || $id === 'u_super');
}

function sistemas_licenca_php($escola) {
  $s = '';
  if (is_array($escola) && isset($escola['licencaSistemas'])) {
    $s = strtolower(trim((string) $escola['licencaSistemas']));
  }
  if ($s === 'todos' || $s === 'os tres' || $s === 'três' || $s === 'os tres sistemas') return 'todos';
  if ($s === 'ambos' || $s === 'os dois' || $s === 'dois') return 'ambos';
  if ($s === 'minhavez_matrixedu') return 'minhavez_matrixedu';
  if ($s === 'acompanha_matrixedu') return 'acompanha_matrixedu';
  if ($s === 'matrixedu') return 'matrixedu';
  if ($s === 'minhavez' || $s === 'minha vez' || $s === 'minha-vez' || $s === 'fila') return 'minhavez';
  if ($s === 'acompanha' || $s === 'acompanhaai' || $s === 'acompanha-ai') return 'acompanha';
  return 'ambos';
}

function licenca_inclui_minhavez($escola) {
  $s = sistemas_licenca_php($escola);
  return $s === 'ambos' || $s === 'minhavez' || $s === 'todos' || $s === 'minhavez_matrixedu';
}

function licenca_inclui_matrixedu($escola) {
  if (!is_array($escola)) return false;
  if (!empty($escola['licencaMatrixEdu'])) return true;
  $s = sistemas_licenca_php($escola);
  return $s === 'matrixedu' || $s === 'todos' || $s === 'acompanha_matrixedu' || $s === 'minhavez_matrixedu';
}

function segmento_da_turma_php($turma) {
  $t = strtolower(trim((string) $turma));
  $t = strtr($t, array('é' => 'e', 'ê' => 'e', 'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u'));
  if (preg_match('/\b(medio|em|eja)\b/', $t) || strpos($t, 'ensino medio') !== false) return 'medio';
  return 'fundamental';
}

function http_json_minhavez($url, $method = 'GET', $body = null) {
  $headers = array('Accept: application/json', 'Content-Type: application/json');
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if (strtoupper($method) === 'POST') {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $body ? $body : '');
    }
    $out = curl_exec($ch);
    curl_close($ch);
    return $out;
  }
  $opts = array(
    'http' => array(
      'method' => $method,
      'header' => implode("\r\n", $headers),
      'timeout' => 15,
      'content' => $body ? $body : '',
      'ignore_errors' => true
    )
  );
  return @file_get_contents($url, false, stream_context_create($opts));
}

function aplicar_contas_minhavez($destino, $payloadAcompanha) {
  if (!is_array($destino)) $destino = array();
  if (!isset($destino['escolas']) || !is_array($destino['escolas'])) $destino['escolas'] = array();
  if (!isset($destino['usuarios']) || !is_array($destino['usuarios'])) $destino['usuarios'] = array();
  if (!isset($destino['students']) || !is_array($destino['students'])) $destino['students'] = array();
  if (!isset($destino['queue']) || !is_array($destino['queue'])) $destino['queue'] = array();
  if (!isset($destino['visits']) || !is_array($destino['visits'])) $destino['visits'] = array();
  if (!isset($destino['removidos']) || !is_array($destino['removidos'])) {
    $destino['removidos'] = array('usuarios' => array(), 'escolas' => array());
  }
  if (!isset($destino['removidos']['usuarios']) || !is_array($destino['removidos']['usuarios'])) {
    $destino['removidos']['usuarios'] = array();
  }

  $escolas = (isset($payloadAcompanha['escolas']) && is_array($payloadAcompanha['escolas']))
    ? $payloadAcompanha['escolas'] : array();
  $usuarios = (isset($payloadAcompanha['usuarios']) && is_array($payloadAcompanha['usuarios']))
    ? $payloadAcompanha['usuarios'] : array();

  $idsMinhaVez = array();
  foreach ($escolas as $e) {
    if (!is_array($e) || !isset($e['id'])) continue;
    if (licenca_inclui_minhavez($e) && !empty($e['licencaAtiva'])) {
      $idsMinhaVez[(string) $e['id']] = true;
    }
  }

  foreach ($escolas as $e) {
    if (!is_array($e) || !isset($e['id'])) continue;
    $id = (string) $e['id'];
    $inclui = isset($idsMinhaVez[$id]);
    $row = array(
      'id' => $id,
      'nome' => isset($e['nome']) ? $e['nome'] : '',
      'codigo' => isset($e['codigo']) ? $e['codigo'] : '',
      'cidade' => isset($e['cidade']) ? $e['cidade'] : '',
      'licencaChave' => isset($e['licencaChave']) ? $e['licencaChave'] : '',
      'licencaAtiva' => !empty($e['licencaAtiva']) && $inclui,
      'licencaAte' => isset($e['licencaAte']) ? $e['licencaAte'] : '',
      'licencaSistemas' => sistemas_licenca_php($e),
      'criadaEm' => isset($e['criadaEm']) ? $e['criadaEm'] : '',
      'atualizadoEm' => isset($e['atualizadoEm']) ? $e['atualizadoEm'] : gmdate('c')
    );
    $found = false;
    foreach ($destino['escolas'] as $i => $exist) {
      if (is_array($exist) && isset($exist['id']) && (string) $exist['id'] === $id) {
        $destino['escolas'][$i] = array_merge($exist, $row);
        $found = true;
        break;
      }
    }
    if (!$found && $inclui) $destino['escolas'][] = $row;
  }

  foreach ($usuarios as $u) {
    if (!is_array($u) || !isset($u['id'])) continue;
    if (isset($u['role']) && $u['role'] === 'superadmin') continue;
    $escolaId = isset($u['escolaId']) ? (string) $u['escolaId'] : '';
    $deve = $escolaId !== '' && isset($idsMinhaVez[$escolaId]);
    $idx = -1;
    foreach ($destino['usuarios'] as $i => $exist) {
      if (!is_array($exist)) continue;
      $sameId = isset($exist['id']) && (string) $exist['id'] === (string) $u['id'];
      $sameLogin = isset($exist['usuario'], $u['usuario'])
        && strtolower((string) $exist['usuario']) === strtolower((string) $u['usuario']);
      if ($sameId || $sameLogin) { $idx = $i; break; }
    }
    if ($deve) {
      $role = (isset($u['role']) && $u['role'] === 'admin') ? 'admin' : 'professor';
      $row = array(
        'id' => $u['id'],
        'usuario' => isset($u['usuario']) ? $u['usuario'] : '',
        'role' => $role,
        'nome' => isset($u['nome']) ? $u['nome'] : '',
        'professor' => ($role === 'professor') || !empty($u['professor']),
        'escolaId' => $escolaId,
        'email' => isset($u['email']) ? $u['email'] : '',
        'salt' => isset($u['salt']) ? $u['salt'] : '',
        'senhaHash' => isset($u['senhaHash']) ? $u['senhaHash'] : '',
        'mustChangePassword' => !empty($u['mustChangePassword']),
        'senhasAnteriores' => (isset($u['senhasAnteriores']) && is_array($u['senhasAnteriores'])) ? $u['senhasAnteriores'] : array(),
        'senhaAlteradaEm' => isset($u['senhaAlteradaEm']) ? $u['senhaAlteradaEm'] : '',
        'atualizadoEm' => isset($u['atualizadoEm']) ? $u['atualizadoEm'] : gmdate('c')
      );
      if ($idx >= 0) $destino['usuarios'][$idx] = array_merge($destino['usuarios'][$idx], $row);
      else $destino['usuarios'][] = $row;
      $rid = (string) $u['id'];
      $destino['removidos']['usuarios'] = array_values(array_filter(
        $destino['removidos']['usuarios'],
        function ($x) use ($rid) { return (string) $x !== $rid; }
      ));
    } elseif ($idx >= 0) {
      $rid = isset($destino['usuarios'][$idx]['id']) ? (string) $destino['usuarios'][$idx]['id'] : (string) $u['id'];
      if (!in_array($rid, $destino['removidos']['usuarios'], true)) {
        $destino['removidos']['usuarios'][] = $rid;
      }
      array_splice($destino['usuarios'], $idx, 1);
    }
  }

  $alunos = (isset($payloadAcompanha['alunos']) && is_array($payloadAcompanha['alunos']))
    ? $payloadAcompanha['alunos'] : array();
  $remAlunos = array();
  if (isset($payloadAcompanha['removidos']['alunos']) && is_array($payloadAcompanha['removidos']['alunos'])) {
    foreach ($payloadAcompanha['removidos']['alunos'] as $rid) $remAlunos[(string) $rid] = true;
  }
  $idsAlunosOk = array();
  foreach ($alunos as $a) {
    if (!is_array($a) || !isset($a['id'])) continue;
    $escA = isset($a['escolaId']) ? (string) $a['escolaId'] : '';
    if ($escA === '' && count($idsMinhaVez) === 1) {
      $keys = array_keys($idsMinhaVez);
      $escA = (string) $keys[0];
    }
    if ($escA !== '' && isset($idsMinhaVez[$escA]) && empty($remAlunos[(string) $a['id']])) {
      $idsAlunosOk[(string) $a['id']] = true;
    }
  }
  $kept = array();
  foreach ($destino['students'] as $s) {
    if (!is_array($s)) continue;
    if (!isset($s['acompanhaId']) || $s['acompanhaId'] === '') {
      $kept[] = $s;
      continue;
    }
    if (isset($idsAlunosOk[(string) $s['acompanhaId']])) $kept[] = $s;
  }
  $destino['students'] = $kept;

  foreach ($alunos as $a) {
    if (!is_array($a) || !isset($a['id'])) continue;
    if (empty($idsAlunosOk[(string) $a['id']])) continue;
    $esc = isset($a['escolaId']) ? (string) $a['escolaId'] : '';
    if ($esc === '' && count($idsMinhaVez) === 1) {
      $keys = array_keys($idsMinhaVez);
      $esc = (string) $keys[0];
    }
    $idx = -1;
    foreach ($destino['students'] as $i => $exist) {
      if (!is_array($exist)) continue;
      if (isset($exist['acompanhaId']) && (string) $exist['acompanhaId'] === (string) $a['id']) { $idx = $i; break; }
    }
    if ($idx < 0 && !empty($a['ra'])) {
      $ra = strtolower(trim((string) $a['ra']));
      foreach ($destino['students'] as $i => $exist) {
        if (!is_array($exist)) continue;
        $era = isset($exist['ra']) ? strtolower(trim((string) $exist['ra'])) : '';
        $eesc = isset($exist['escolaId']) ? (string) $exist['escolaId'] : '';
        if ($era !== '' && $era === $ra && $eesc === $esc) { $idx = $i; break; }
      }
    }
    if ($idx < 0) {
      $nome = strtolower(trim(isset($a['nome']) ? (string) $a['nome'] : ''));
      $turma = trim(isset($a['turma']) ? (string) $a['turma'] : '');
      foreach ($destino['students'] as $i => $exist) {
        if (!is_array($exist)) continue;
        $enome = strtolower(trim(isset($exist['name']) ? (string) $exist['name'] : ''));
        $eturma = trim(isset($exist['turma']) ? (string) $exist['turma'] : '');
        $eesc = isset($exist['escolaId']) ? (string) $exist['escolaId'] : '';
        if ($enome === $nome && $eturma === $turma && $eesc === $esc) { $idx = $i; break; }
      }
    }
    $prev = $idx >= 0 ? $destino['students'][$idx] : array();
    $idNum = 0;
    if (isset($prev['id']) && is_numeric($prev['id'])) $idNum = (int) $prev['id'];
    if ($idNum <= 0) {
      $max = 0;
      foreach ($destino['students'] as $exist) {
        if (!is_array($exist) || !isset($exist['id'])) continue;
        $n = (int) $exist['id'];
        if ($n > $max) $max = $n;
      }
      $idNum = $max + 1;
    }
    $seg = (isset($prev['segment']) && $prev['segment'] !== '') ? $prev['segment'] : segmento_da_turma_php(isset($a['turma']) ? $a['turma'] : '');
    $row = array(
      'id' => $idNum,
      'name' => isset($a['nome']) ? $a['nome'] : '',
      'turma' => isset($a['turma']) ? $a['turma'] : '',
      'segment' => $seg,
      'escolaId' => $esc,
      'ra' => isset($a['ra']) ? $a['ra'] : '',
      'acompanhaId' => $a['id'],
      'created_at' => isset($prev['created_at']) && $prev['created_at'] !== ''
        ? $prev['created_at']
        : (isset($a['atualizadoEm']) ? $a['atualizadoEm'] : gmdate('c'))
    );
    if ($idx >= 0) $destino['students'][$idx] = array_merge($prev, $row);
    else $destino['students'][] = $row;
  }

  return $destino;
}

function sincronizar_minha_vez($payload) {
  if (!is_array($payload)) return;
  $cfg = (isset($payload['integracaoMinhaVez']) && is_array($payload['integracaoMinhaVez']))
    ? $payload['integracaoMinhaVez'] : array();
  $url = isset($cfg['url']) ? trim((string) $cfg['url']) : '';
  $token = isset($cfg['token']) ? trim((string) $cfg['token']) : '';
  if ($url === '') $url = 'https://minhavez.projetosneres.com/api/index.php';
  $url = rtrim($url, '/');
  if (!preg_match('/index\\.php$/i', $url)) {
    if (preg_match('/\\/api$/i', $url)) $url .= '/index.php';
    else $url .= '/api/index.php';
  }
  if ($token === '') return;
  $sep = (strpos($url, '?') !== false) ? '&' : '?';
  $getUrl = $url . $sep . 'action=get&token=' . rawurlencode($token);
  $rawGet = http_json_minhavez($getUrl, 'GET');
  if (!is_string($rawGet) || $rawGet === '') return;
  $got = json_decode($rawGet, true);
  if (!is_array($got) || empty($got['ok'])) return;
  $version = isset($got['version']) ? (int) $got['version'] : 0;
  $data = aplicar_contas_minhavez(isset($got['data']) && is_array($got['data']) ? $got['data'] : array(), $payload);
  $body = json_encode(array('version' => $version, 'data' => $data), JSON_UNESCAPED_UNICODE);
  $saveUrl = $url . $sep . 'action=save&token=' . rawurlencode($token);
  $rawSave = http_json_minhavez($saveUrl, 'POST', $body);
  $saved = is_string($rawSave) ? json_decode($rawSave, true) : null;
  if (is_array($saved) && isset($saved['erro']) && $saved['erro'] === 'conflito' && isset($saved['data'])) {
    $version = isset($saved['version']) ? (int) $saved['version'] : $version;
    $data = aplicar_contas_minhavez($saved['data'], $payload);
    $body = json_encode(array('version' => $version, 'data' => $data), JSON_UNESCAPED_UNICODE);
    http_json_minhavez($saveUrl, 'POST', $body);
  }
}

function aplicar_contas_matrixedu($destino, $payloadAcompanha) {
  if (!is_array($destino)) $destino = array();
  if (!isset($destino['escolas']) || !is_array($destino['escolas'])) $destino['escolas'] = array();
  if (!isset($destino['usuarios']) || !is_array($destino['usuarios'])) $destino['usuarios'] = array();
  if (!isset($destino['removidos']) || !is_array($destino['removidos'])) {
    $destino['removidos'] = array('usuarios' => array(), 'escolas' => array());
  }
  if (!isset($destino['removidos']['usuarios']) || !is_array($destino['removidos']['usuarios'])) {
    $destino['removidos']['usuarios'] = array();
  }

  $escolas = (isset($payloadAcompanha['escolas']) && is_array($payloadAcompanha['escolas']))
    ? $payloadAcompanha['escolas'] : array();
  $usuarios = (isset($payloadAcompanha['usuarios']) && is_array($payloadAcompanha['usuarios']))
    ? $payloadAcompanha['usuarios'] : array();

  $idsOk = array();
  foreach ($escolas as $e) {
    if (!is_array($e) || !isset($e['id'])) continue;
    if (licenca_inclui_matrixedu($e) && !empty($e['licencaAtiva'])) {
      $idsOk[(string) $e['id']] = true;
    }
  }

  foreach ($escolas as $e) {
    if (!is_array($e) || !isset($e['id'])) continue;
    $id = (string) $e['id'];
    $inclui = isset($idsOk[$id]);
    $row = array(
      'id' => $id,
      'nome' => isset($e['nome']) ? $e['nome'] : '',
      'codigo' => isset($e['codigo']) ? $e['codigo'] : '',
      'cidade' => isset($e['cidade']) ? $e['cidade'] : '',
      'licencaChave' => isset($e['licencaChave']) ? $e['licencaChave'] : '',
      'licencaAtiva' => !empty($e['licencaAtiva']) && $inclui,
      'licencaAte' => isset($e['licencaAte']) ? $e['licencaAte'] : '',
      'licencaSistemas' => sistemas_licenca_php($e),
      'licencaMatrixEdu' => $inclui,
      'criadaEm' => isset($e['criadaEm']) ? $e['criadaEm'] : '',
      'atualizadoEm' => isset($e['atualizadoEm']) ? $e['atualizadoEm'] : gmdate('c')
    );
    $found = false;
    foreach ($destino['escolas'] as $i => $exist) {
      if (is_array($exist) && isset($exist['id']) && (string) $exist['id'] === $id) {
        $destino['escolas'][$i] = array_merge($exist, $row);
        $found = true;
        break;
      }
    }
    if (!$found && $inclui) $destino['escolas'][] = $row;
  }

  foreach ($usuarios as $u) {
    if (!is_array($u) || !isset($u['id'])) continue;
    if (isset($u['role']) && $u['role'] === 'superadmin') continue;
    $escolaId = isset($u['escolaId']) ? (string) $u['escolaId'] : '';
    $deve = $escolaId !== '' && isset($idsOk[$escolaId]);
    $idx = -1;
    foreach ($destino['usuarios'] as $i => $exist) {
      if (!is_array($exist)) continue;
      $sameId = isset($exist['id']) && (string) $exist['id'] === (string) $u['id'];
      $sameLogin = isset($exist['usuario'], $u['usuario'])
        && strtolower((string) $exist['usuario']) === strtolower((string) $u['usuario']);
      $sameEmail = isset($exist['email'], $u['email']) && $u['email'] !== ''
        && strtolower((string) $exist['email']) === strtolower((string) $u['email']);
      if ($sameId || $sameLogin || $sameEmail) { $idx = $i; break; }
    }
    if ($deve) {
      $role = (isset($u['role']) && $u['role'] === 'admin') ? 'admin' : 'professor';
      $licencaLocal = (isset($u['ativo']) && $u['ativo'] === false) ? 'bloqueada' : 'liberada';
      $row = array(
        'id' => $u['id'],
        'usuario' => isset($u['usuario']) ? $u['usuario'] : '',
        'role' => $role,
        'nome' => isset($u['nome']) ? $u['nome'] : '',
        'professor' => ($role === 'professor') || !empty($u['professor']),
        'escolaId' => $escolaId,
        'email' => isset($u['email']) ? $u['email'] : '',
        'salt' => isset($u['salt']) ? $u['salt'] : '',
        'senhaHash' => isset($u['senhaHash']) ? $u['senhaHash'] : '',
        'mustChangePassword' => !empty($u['mustChangePassword']),
        'senhasAnteriores' => (isset($u['senhasAnteriores']) && is_array($u['senhasAnteriores'])) ? $u['senhasAnteriores'] : array(),
        'senhaAlteradaEm' => isset($u['senhaAlteradaEm']) ? $u['senhaAlteradaEm'] : '',
        'atualizadoEm' => isset($u['atualizadoEm']) ? $u['atualizadoEm'] : gmdate('c'),
        'origem' => 'acompanha',
        'licenca' => $licencaLocal
      );
      if ($idx >= 0) $destino['usuarios'][$idx] = array_merge($destino['usuarios'][$idx], $row);
      else $destino['usuarios'][] = $row;
      $rid = (string) $u['id'];
      $destino['removidos']['usuarios'] = array_values(array_filter(
        $destino['removidos']['usuarios'],
        function ($x) use ($rid) { return (string) $x !== $rid; }
      ));
    } elseif ($idx >= 0) {
      $exist = $destino['usuarios'][$idx];
      $origem = isset($exist['origem']) ? (string) $exist['origem'] : '';
      if ($origem === 'local') continue;
      $rid = isset($exist['id']) ? (string) $exist['id'] : (string) $u['id'];
      if (!in_array($rid, $destino['removidos']['usuarios'], true)) {
        $destino['removidos']['usuarios'][] = $rid;
      }
      array_splice($destino['usuarios'], $idx, 1);
    }
  }

  return $destino;
}

function sincronizar_matrixedu($payload) {
  if (!is_array($payload)) return;
  $cfg = (isset($payload['integracaoMatrixEdu']) && is_array($payload['integracaoMatrixEdu']))
    ? $payload['integracaoMatrixEdu'] : array();
  $url = isset($cfg['url']) ? trim((string) $cfg['url']) : '';
  $token = isset($cfg['token']) ? trim((string) $cfg['token']) : '';
  $url = rtrim($url, '/');
  if ($url === '') return;
  if (!preg_match('/index\\.php$/i', $url)) {
    if (preg_match('/\\/api$/i', $url)) $url .= '/index.php';
    else $url .= '/api/index.php';
  }
  if ($token === '') return;
  $sep = (strpos($url, '?') !== false) ? '&' : '?';
  $getUrl = $url . $sep . 'action=get&token=' . rawurlencode($token);
  $rawGet = http_json_minhavez($getUrl, 'GET');
  if (!is_string($rawGet) || $rawGet === '') return;
  $got = json_decode($rawGet, true);
  if (!is_array($got) || empty($got['ok'])) return;
  $version = isset($got['version']) ? (int) $got['version'] : 0;
  $data = aplicar_contas_matrixedu(isset($got['data']) && is_array($got['data']) ? $got['data'] : array(), $payload);
  $body = json_encode(array('version' => $version, 'data' => $data), JSON_UNESCAPED_UNICODE);
  $saveUrl = $url . $sep . 'action=save&token=' . rawurlencode($token);
  $rawSave = http_json_minhavez($saveUrl, 'POST', $body);
  $saved = is_string($rawSave) ? json_decode($rawSave, true) : null;
  if (is_array($saved) && isset($saved['erro']) && $saved['erro'] === 'conflito' && isset($saved['data'])) {
    $version = isset($saved['version']) ? (int) $saved['version'] : $version;
    $data = aplicar_contas_matrixedu($saved['data'], $payload);
    $body = json_encode(array('version' => $version, 'data' => $data), JSON_UNESCAPED_UNICODE);
    http_json_minhavez($saveUrl, 'POST', $body);
  }
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

  try {
    sincronizar_minha_vez($payload);
  } catch (Exception $e) {
  } catch (Throwable $e) {
  }
  try {
    sincronizar_matrixedu($payload);
  } catch (Exception $e) {
  } catch (Throwable $e) {
  }

  json_out(array(
    'ok' => true,
    'version' => $newVersion,
    'updated_at' => $now
  ));
}

function gravar_acompanha_store($mysqli, $payload, $currentVersion) {
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if ($json === false) return null;
  $newVersion = ((int) $currentVersion) + 1;
  $now = gmdate('Y-m-d H:i:s');
  $stmt = $mysqli->prepare('UPDATE acompanha_store SET payload = ?, version = ?, updated_at = ? WHERE id = 1');
  if (!$stmt) return null;
  $stmt->bind_param('sis', $json, $newVersion, $now);
  if (!$stmt->execute()) {
    $stmt->close();
    return null;
  }
  $stmt->close();
  return array('version' => $newVersion, 'updated_at' => $now);
}

function token_minhavez_confere($payload, $recebido) {
  $recebido = trim((string) $recebido);
  if ($recebido === '') return false;
  $cfg = (isset($payload['integracaoMinhaVez']) && is_array($payload['integracaoMinhaVez']))
    ? $payload['integracaoMinhaVez'] : array();
  $mv = isset($cfg['token']) ? trim((string) $cfg['token']) : '';
  if ($mv === '') return false;
  if (strlen($mv) !== strlen($recebido)) return false;
  return hash_equals($mv, $recebido);
}

function achar_aluno_para_ocorrencia($payload, $escolaId, $acompanhaId, $ra, $nome, $turma) {
  $alunos = (isset($payload['alunos']) && is_array($payload['alunos'])) ? $payload['alunos'] : array();
  $esc = (string) $escolaId;
  if ($acompanhaId !== '') {
    foreach ($alunos as $a) {
      if (!is_array($a)) continue;
      if ((string) (isset($a['id']) ? $a['id'] : '') === $acompanhaId) return $a;
    }
  }
  $raN = strtolower(trim($ra));
  if ($raN !== '') {
    foreach ($alunos as $a) {
      if (!is_array($a)) continue;
      $aesc = isset($a['escolaId']) ? (string) $a['escolaId'] : '';
      $ara = strtolower(trim(isset($a['ra']) ? (string) $a['ra'] : ''));
      if ($ara === $raN && ($esc === '' || $aesc === '' || $aesc === $esc)) return $a;
    }
  }
  $nn = strtolower(trim($nome));
  $tt = strtolower(trim($turma));
  if ($nn !== '') {
    foreach ($alunos as $a) {
      if (!is_array($a)) continue;
      $aesc = isset($a['escolaId']) ? (string) $a['escolaId'] : '';
      $anome = strtolower(trim(isset($a['nome']) ? (string) $a['nome'] : ''));
      $aturma = strtolower(trim(isset($a['turma']) ? (string) $a['turma'] : ''));
      if ($anome === $nn && ($tt === '' || $aturma === $tt) && ($esc === '' || $aesc === '' || $aesc === $esc)) return $a;
    }
  }
  return null;
}

function nome_tutor_por_id($payload, $tutorId) {
  if ($tutorId === '') return '';
  $usuarios = (isset($payload['usuarios']) && is_array($payload['usuarios'])) ? $payload['usuarios'] : array();
  foreach ($usuarios as $u) {
    if (!is_array($u)) continue;
    if ((string) (isset($u['id']) ? $u['id'] : '') === (string) $tutorId) {
      return isset($u['nome']) ? (string) $u['nome'] : '';
    }
  }
  return '';
}

function acompanha_minusculo($s) {
  $s = trim((string) $s);
  if ($s === '') return '';
  if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
  return strtolower($s);
}

function tipos_da_ocorrencia_php($oc) {
  if (!is_array($oc)) return array();
  if (isset($oc['tipos']) && is_array($oc['tipos']) && $oc['tipos']) {
    return $oc['tipos'];
  }
  if (!empty($oc['tipo'])) {
    $parts = explode(';', (string) $oc['tipo']);
    $out = array();
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p !== '') $out[] = $p;
    }
    return $out;
  }
  return array();
}

function mesmo_aluno_ocorrencia_php($oc, $escolaId, $alunoId, $ra, $nome, $turma) {
  if (!is_array($oc)) return false;
  $eesc = isset($oc['escolaId']) ? (string) $oc['escolaId'] : '';
  if ($escolaId !== '' && $eesc !== '' && $eesc !== (string) $escolaId) return false;
  $oid = isset($oc['alunoId']) ? (string) $oc['alunoId'] : '';
  if ($alunoId !== '' && $oid !== '' && $oid === (string) $alunoId) return true;
  $ora = isset($oc['ra']) ? trim((string) $oc['ra']) : '';
  if ($ra !== '' && $ora !== '' && acompanha_minusculo($ora) === acompanha_minusculo($ra)) return true;
  $onome = isset($oc['aluno']) ? trim((string) $oc['aluno']) : '';
  $oturma = isset($oc['turma']) ? trim((string) $oc['turma']) : '';
  if ($nome !== '' && acompanha_minusculo($onome) === acompanha_minusculo($nome)) {
    if ($turma === '' || acompanha_minusculo($oturma) === acompanha_minusculo($turma)) return true;
  }
  return false;
}

function contar_mesmo_tipo_php($payload, $escolaId, $alunoId, $ra, $nome, $turma, $tipo) {
  $tipoN = acompanha_minusculo($tipo);
  if ($tipoN === '') return 0;
  $lista = (isset($payload['ocorrencias']) && is_array($payload['ocorrencias'])) ? $payload['ocorrencias'] : array();
  $n = 0;
  foreach ($lista as $oc) {
    if (!is_array($oc)) continue;
    if (isset($oc['nivel']) && $oc['nivel'] === 'vermelho') continue;
    if (!mesmo_aluno_ocorrencia_php($oc, $escolaId, $alunoId, $ra, $nome, $turma)) continue;
    foreach (tipos_da_ocorrencia_php($oc) as $t) {
      if (acompanha_minusculo($t) === $tipoN) {
        $n++;
        break;
      }
    }
  }
  return $n;
}

if ($action === 'push_public_key') {
  exigir_token();
  $mysqli = db_connect();
  garantir_tabela($mysqli);
  $vapid = webpush_obter_vapid($mysqli);
  $mysqli->close();
  if (!$vapid) {
    json_out(array('ok' => false, 'erro' => 'vapid_indisponivel'), 500);
  }
  json_out(array(
    'ok' => true,
    'publicKey' => $vapid['publicKey']
  ));
}

if ($action === 'push_subscribe' && ($method === 'POST' || $method === 'PUT')) {
  exigir_token();
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) {
    json_out(array('ok' => false, 'erro' => 'json_invalido'), 400);
  }
  $userId = isset($body['userId']) ? trim((string) $body['userId']) : '';
  $escolaId = isset($body['escolaId']) ? trim((string) $body['escolaId']) : '';
  $sub = isset($body['subscription']) && is_array($body['subscription']) ? $body['subscription'] : $body;
  $endpoint = isset($sub['endpoint']) ? trim((string) $sub['endpoint']) : '';
  $keys = isset($sub['keys']) && is_array($sub['keys']) ? $sub['keys'] : array();
  $p256dh = isset($keys['p256dh']) ? trim((string) $keys['p256dh']) : '';
  $authKey = isset($keys['auth']) ? trim((string) $keys['auth']) : '';
  $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 250) : '';
  if ($userId === '' || $endpoint === '' || strpos($endpoint, 'https://') !== 0 || $p256dh === '' || $authKey === '') {
    json_out(array('ok' => false, 'erro' => 'inscricao_invalida'), 400);
  }
  $mysqli = db_connect();
  garantir_tabela($mysqli);
  garantir_tabelas_push($mysqli);
  $hash = webpush_hash_endpoint($endpoint);
  $stmt = $mysqli->prepare('REPLACE INTO acompanha_push_subs (endpoint_hash, user_id, escola_id, endpoint, p256dh, auth_key, user_agent, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
  if (!$stmt) {
    $mysqli->close();
    json_out(array('ok' => false, 'erro' => 'db_subs'), 500);
  }
  $stmt->bind_param('sssssss', $hash, $userId, $escolaId, $endpoint, $p256dh, $authKey, $ua);
  $ok = $stmt->execute();
  $stmt->close();
  $mysqli->close();
  if (!$ok) {
    json_out(array('ok' => false, 'erro' => 'db_gravacao'), 500);
  }
  json_out(array('ok' => true));
}

if ($action === 'push_unsubscribe' && ($method === 'POST' || $method === 'PUT')) {
  exigir_token();
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) {
    json_out(array('ok' => false, 'erro' => 'json_invalido'), 400);
  }
  $endpoint = isset($body['endpoint']) ? trim((string) $body['endpoint']) : '';
  $userId = isset($body['userId']) ? trim((string) $body['userId']) : '';
  $mysqli = db_connect();
  garantir_tabela($mysqli);
  if ($endpoint !== '') {
    $hash = webpush_hash_endpoint($endpoint);
    $stmt = $mysqli->prepare('DELETE FROM acompanha_push_subs WHERE endpoint_hash = ?');
    if ($stmt) {
      $stmt->bind_param('s', $hash);
      $stmt->execute();
      $stmt->close();
    }
  } elseif ($userId !== '') {
    $stmt = $mysqli->prepare('DELETE FROM acompanha_push_subs WHERE user_id = ?');
    if ($stmt) {
      $stmt->bind_param('s', $userId);
      $stmt->execute();
      $stmt->close();
    }
  }
  $mysqli->close();
  json_out(array('ok' => true));
}

if ($action === 'notify_ocorrencia' && ($method === 'POST' || $method === 'PUT')) {
  exigir_token();
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) {
    json_out(array('ok' => false, 'erro' => 'json_invalido'), 400);
  }
  $oc = isset($body['ocorrencia']) && is_array($body['ocorrencia']) ? $body['ocorrencia'] : $body;
  if (!isset($oc['protocolo']) || trim((string) $oc['protocolo']) === '') {
    json_out(array('ok' => false, 'erro' => 'ocorrencia_invalida'), 400);
  }
  $exclude = isset($body['excludeUserId']) ? trim((string) $body['excludeUserId']) : '';
  $extra = isset($body['destUserIds']) && is_array($body['destUserIds']) ? $body['destUserIds'] : array();
  $mysqli = db_connect();
  garantir_tabela($mysqli);
  garantir_tabelas_push($mysqli);
  $store = ler_store($mysqli);
  $payload = is_array($store['payload']) ? $store['payload'] : array();
  $resultado = webpush_notificar_ocorrencia($mysqli, $payload, $oc, $exclude, $extra);
  $mysqli->close();
  json_out($resultado);
}

if ($action === 'push_test' && ($method === 'POST' || $method === 'PUT')) {
  exigir_token();
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) {
    json_out(array('ok' => false, 'erro' => 'json_invalido'), 400);
  }
  $userId = isset($body['userId']) ? trim((string) $body['userId']) : '';
  if ($userId === '') {
    json_out(array('ok' => false, 'erro' => 'userId'), 400);
  }
  $mysqli = db_connect();
  garantir_tabela($mysqli);
  garantir_tabelas_push($mysqli);
  $oc = array(
    'protocolo' => 'TESTE',
    'nivel' => 'verde',
    'aluno' => 'Aviso de teste',
    'turma' => '',
    'tutorId' => $userId,
    'escolaId' => isset($body['escolaId']) ? trim((string) $body['escolaId']) : ''
  );
  $resultado = webpush_notificar_ocorrencia($mysqli, array('usuarios' => array()), $oc, '', array($userId));
  $mysqli->close();
  json_out($resultado);
}

if ($action === 'append_ocorrencia' && ($method === 'POST' || $method === 'PUT')) {
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) {
    json_out(array('ok' => false, 'erro' => 'json_invalido'), 400);
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
  $payload = array();
  if ($row) {
    $decoded = json_decode($row['payload'], true);
    if (is_array($decoded)) $payload = $decoded;
  }
  $recebido = ler_token_recebido();
  if (!api_token_ok() && !token_minhavez_confere($payload, $recebido)) {
    $mysqli->rollback();
    $mysqli->close();
    json_out(array('ok' => false, 'erro' => 'token_invalido'), 401);
  }

  $escolaId = isset($body['escolaId']) ? trim((string) $body['escolaId']) : '';
  $visitId = isset($body['visitId']) ? trim((string) $body['visitId']) : '';
  $alunoNome = isset($body['aluno']) ? trim((string) $body['aluno']) : '';
  $turma = isset($body['turma']) ? trim((string) $body['turma']) : '';
  $professor = isset($body['professor']) ? trim((string) $body['professor']) : '';
  $minutos = isset($body['minutos']) ? (int) $body['minutos'] : 10;
  if ($minutos < 1) $minutos = 10;
  $ra = isset($body['ra']) ? trim((string) $body['ra']) : '';
  $acompanhaId = isset($body['acompanhaId']) ? trim((string) $body['acompanhaId']) : '';

  if ($alunoNome === '') {
    $mysqli->rollback();
    $mysqli->close();
    json_out(array('ok' => false, 'erro' => 'aluno_obrigatorio'), 400);
  }

  if (!isset($payload['ocorrencias']) || !is_array($payload['ocorrencias'])) $payload['ocorrencias'] = array();
  if (!isset($payload['logs']) || !is_array($payload['logs'])) $payload['logs'] = array();
  if (!isset($payload['removidos']) || !is_array($payload['removidos'])) {
    $payload['removidos'] = array('ocorrencias' => array());
  }
  if (!isset($payload['removidos']['ocorrencias']) || !is_array($payload['removidos']['ocorrencias'])) {
    $payload['removidos']['ocorrencias'] = array();
  }

  if ($visitId !== '') {
    foreach ($payload['ocorrencias'] as $exist) {
      if (!is_array($exist)) continue;
      $vid = isset($exist['origemMinhaVezVisitId']) ? (string) $exist['origemMinhaVezVisitId'] : '';
      $eesc = isset($exist['escolaId']) ? (string) $exist['escolaId'] : '';
      if ($vid === $visitId && ($escolaId === '' || $eesc === '' || $eesc === $escolaId)) {
        $mysqli->commit();
        $mysqli->close();
        json_out(array(
          'ok' => true,
          'jaExistia' => true,
          'protocolo' => isset($exist['protocolo']) ? $exist['protocolo'] : ''
        ));
      }
    }
  }

  $aluno = achar_aluno_para_ocorrencia($payload, $escolaId, $acompanhaId, $ra, $alunoNome, $turma);
  $tutorId = ($aluno && isset($aluno['tutorId'])) ? (string) $aluno['tutorId'] : '';
  $tutorNome = nome_tutor_por_id($payload, $tutorId);
  $celular = ($aluno && isset($aluno['celular'])) ? (string) $aluno['celular'] : '';
  $email = ($aluno && isset($aluno['email'])) ? (string) $aluno['email'] : '';
  $alunoId = ($aluno && isset($aluno['id'])) ? (string) $aluno['id'] : $acompanhaId;
  $raFinal = ($aluno && isset($aluno['ra']) && $aluno['ra'] !== '') ? (string) $aluno['ra'] : $ra;
  if ($escolaId === '' && $aluno && isset($aluno['escolaId'])) $escolaId = (string) $aluno['escolaId'];

  $usuariosStore = (isset($payload['usuarios']) && is_array($payload['usuarios'])) ? $payload['usuarios'] : array();
  $professorIdFinal = '';
  if ($professor !== '') {
    foreach ($usuariosStore as $uProf) {
      if (!is_array($uProf)) continue;
      $unome = isset($uProf['nome']) ? trim((string) $uProf['nome']) : '';
      if ($unome === '' || strcasecmp($unome, $professor) !== 0) continue;
      $uEsc = isset($uProf['escolaId']) ? trim((string) $uProf['escolaId']) : '';
      if ($escolaId !== '' && $uEsc !== '' && $uEsc !== $escolaId) continue;
      $professorIdFinal = isset($uProf['id']) ? trim((string) $uProf['id']) : '';
      $professor = $unome;
      break;
    }
  }
  $professorId = $professorIdFinal;
  if ($professor === '') $professor = 'Minha Vez';

  try {
    $tz = new DateTimeZone('America/Sao_Paulo');
  } catch (Exception $e) {
    $tz = new DateTimeZone('UTC');
  }
  $agora = new DateTime('now', $tz);
  $dataBr = $agora->format('d/m/Y H:i');
  $iso = $agora->format('c');
  $tipo = 'Permanência excessiva no banheiro';
  $descricao = 'Aluno permaneceu ' . $minutos . ' minuto(s) no banheiro. Registro automático solicitado pelo professor no Minha Vez'
    . ($professor !== '' ? ' (' . $professor . ')' : '') . '.';

  $anteriores = contar_mesmo_tipo_php($payload, $escolaId, $alunoId, $raFinal, $alunoNome, $turma, $tipo);
  $reclassificado = $anteriores >= 1;
  $nivel = $reclassificado ? 'amarelo' : 'verde';
  $prefixo = $reclassificado ? 'AM' : 'VD';
  $protocolo = $prefixo . '-' . $agora->format('Ymd') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
  $providencias = $reclassificado
    ? array(
      'Registro formal da ocorrência',
      'Encaminhamento imediato à gestão',
      'Ciência e acompanhamento do tutor',
      'Escuta individual do estudante',
      'Contato telefônico ou convocação da família',
      'Definição de estratégias pedagógicas para mudança de comportamento',
      'Acompanhamento sistemático pelo tutor e pela equipe gestora',
      'Registro das intervenções e devolutivas'
    )
    : array(
      'Intervenção pedagógica',
      'Orientação ao estudante',
      'Registro da ocorrência'
    );
  $responsavel = $reclassificado
    ? 'Necessitam da participação da gestão/tutor'
    : 'Responsabilidade do professor';
  $motivoRec = $reclassificado
    ? 'Reincidência do mesmo tipo: a primeira ocorrência de permanência excessiva no banheiro ficou Verde; esta passa automaticamente para Amarelo.'
    : '';

  $oc = array(
    'protocolo' => $protocolo,
    'data' => $dataBr,
    'dataRegistro' => $dataBr,
    'professor' => $professor,
    'professorId' => $professorId,
    'aluno' => $alunoNome,
    'alunoId' => $alunoId,
    'ra' => $raFinal,
    'alunoManual' => !$aluno,
    'turma' => $turma,
    'nivel' => $nivel,
    'nivelOriginal' => 'verde',
    'reclassificado' => $reclassificado,
    'motivoReclassificacao' => $motivoRec,
    'verdesAnteriores' => $anteriores,
    'tipos' => array($tipo),
    'tipo' => $tipo,
    'descricao' => $descricao,
    'email' => $email,
    'celular' => $celular,
    'tutorId' => $tutorId,
    'tutorNome' => $tutorNome,
    'direcionadoGestao' => $reclassificado,
    'providencias' => $providencias,
    'responsavel' => $responsavel,
    'escolaId' => $escolaId,
    'origemMinhaVez' => true,
    'origemMinhaVezVisitId' => $visitId,
    'tutorCiente' => false,
    'tutorCienteEm' => '',
    'tutorCientePor' => '',
    'tratativa' => '',
    'tratativaEm' => '',
    'tratativaPor' => '',
    'gestaoAcompanhou' => false,
    'gestaoAcompanhouEm' => '',
    'gestaoAcompanhouPor' => '',
    'gestaoObs' => '',
    'whatsappEnviado' => false,
    'whatsappEnviadoEm' => '',
    'whatsappEnviadoPor' => '',
    'emailEnviado' => false,
    'emailEnviadoEm' => '',
    'emailEnviadoPor' => '',
    'emailErro' => '',
    'atualizadoEm' => $iso
  );

  array_unshift($payload['ocorrencias'], $oc);
  $payload['removidos']['ocorrencias'] = array_values(array_filter(
    $payload['removidos']['ocorrencias'],
    function ($k) use ($protocolo) { return (string) $k !== $protocolo; }
  ));
  array_unshift($payload['logs'], array(
    'id' => 'log_' . substr(md5($protocolo . $iso), 0, 12),
    'em' => $iso,
    'acao' => 'ocorrencia',
    'detalhe' => 'Minha Vez registrou ocorrência ' . $protocolo . ' · ' . $alunoNome . ' · ' . ($reclassificado ? 'Amarelo (reincidência)' : 'Verde'),
    'usuario' => '',
    'nome' => $professor,
    'role' => 'professor',
    'escolaId' => $escolaId,
    'alvo' => $protocolo
  ));
  $payload['atualizadoEm'] = $iso;

  $currentVersion = $row ? (int) $row['version'] : 0;
  $gravou = gravar_acompanha_store($mysqli, $payload, $currentVersion);
  if (!$gravou) {
    $mysqli->rollback();
    $mysqli->close();
    json_out(array('ok' => false, 'erro' => 'db_gravacao'), 500);
  }
  $mysqli->commit();
  $push = array('ok' => true, 'enviados' => 0);
  try {
    $push = webpush_notificar_ocorrencia($mysqli, $payload, $oc, '');
  } catch (Exception $e) {
    $push = array('ok' => false, 'erro' => $e->getMessage(), 'enviados' => 0);
  } catch (Throwable $e) {
    $push = array('ok' => false, 'erro' => $e->getMessage(), 'enviados' => 0);
  }
  $mysqli->close();
  json_out(array(
    'ok' => true,
    'protocolo' => $protocolo,
    'version' => $gravou['version'],
    'push' => $push
  ));
}

json_out(array('ok' => false, 'erro' => 'acao_invalida'), 404);
