<?php
/**
 * Instalador Acompanha-Aí — Hostinger
 * Formulário clássico (POST) + proteção contra exceções do PHP 8/mysqli.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=utf-8');

$lockFile = __DIR__ . '/.installed';
$configFile = __DIR__ . '/config.php';
$erro = '';
$ok = false;
$geradoToken = '';
$avisoPermissao = '';
$jaInstalado = is_file($lockFile) && is_file($configFile);

function h($s) {
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function gerar_token() {
  if (function_exists('random_bytes')) {
    try {
      return bin2hex(random_bytes(24));
    } catch (Exception $e) {}
  }
  return sha1(uniqid('acompanha', true) . mt_rand());
}

function escrever_arquivo($path, $conteudo) {
  $ok = @file_put_contents($path, $conteudo);
  if ($ok === false) {
    return false;
  }
  @chmod($path, 0644);
  return true;
}

function escrever_config($host, $name, $user, $pass, $token, $cors = '') {
  $tpl = "<?php\n"
    . "define('DB_HOST', " . var_export($host, true) . ");\n"
    . "define('DB_NAME', " . var_export($name, true) . ");\n"
    . "define('DB_USER', " . var_export($user, true) . ");\n"
    . "define('DB_PASS', " . var_export($pass, true) . ");\n"
    . "define('API_TOKEN', " . var_export($token, true) . ");\n"
    . "define('API_CORS_ORIGINS', " . var_export(trim($cors), true) . ");\n";
  return escrever_arquivo(__DIR__ . '/config.php', $tpl);
}

function escrever_token_js($token) {
  $js = 'window.ACOMPANHA_API_TOKEN = ' . json_encode($token) . ";\n";
  return escrever_arquivo(__DIR__ . '/public-token.js', $js);
}

function escrever_config_js_raiz($token) {
  $js = "window.ACOMPANHA_API_URL = \"api/index.php\";\n"
    . 'window.ACOMPANHA_API_TOKEN = ' . json_encode($token) . ";\n";
  return escrever_arquivo(dirname(__DIR__) . '/config.js', $js);
}

function url_app() {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
  $scheme = $https ? 'https' : 'http';
  $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
  $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/api/ativar.php';
  $base = rtrim(dirname(dirname($script)), '/');
  if ($base === '' || $base === '\\') $base = '';
  return $scheme . '://' . $host . $base . '/';
}

function url_api() {
  return rtrim(url_app(), '/') . '/api/index.php';
}

function instalar($host, $name, $user, $pass, $cors = '') {
  $host = trim($host);
  $name = trim($name);
  $user = trim($user);
  if ($host === '') $host = 'localhost';
  if ($name === '' || $user === '') {
    return array('ok' => false, 'erro' => 'Preencha nome do banco e usuário.');
  }
  if (!extension_loaded('mysqli') && !class_exists('mysqli')) {
    return array(
      'ok' => false,
      'erro' => 'A extensão mysqli não está ativa. No hPanel: Avançado → Configuração PHP → Extensions → marque mysqli → Salvar.'
    );
  }

  // PHP 8+ lança exceção em falha de conexão — sem isso a Hostinger devolve HTTP 500.
  if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
  }

  $mysqli = null;
  try {
    $mysqli = new mysqli($host, $user, $pass, $name);
  } catch (Exception $e) {
    return array(
      'ok' => false,
      'erro' => 'Não conectou no MySQL: ' . $e->getMessage()
        . '. Confira host (geralmente localhost), nome completo do banco/usuário e senha no hPanel.'
    );
  } catch (Throwable $e) {
    return array(
      'ok' => false,
      'erro' => 'Não conectou no MySQL: ' . $e->getMessage()
        . '. Confira host (geralmente localhost), nome completo do banco/usuário e senha no hPanel.'
    );
  }

  if (!$mysqli || $mysqli->connect_errno) {
    $msg = $mysqli ? $mysqli->connect_error : 'falha desconhecida';
    return array(
      'ok' => false,
      'erro' => 'Não conectou no MySQL: ' . $msg
        . '. Use o nome completo do hPanel (ex.: u123456789_acompanha) e host localhost.'
    );
  }

  if (method_exists($mysqli, 'set_charset')) {
    $mysqli->set_charset('utf8mb4');
  }

  $sql = "CREATE TABLE IF NOT EXISTS acompanha_store (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    payload LONGTEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  try {
    if (!$mysqli->query($sql)) {
      $err = $mysqli->error;
      $mysqli->close();
      return array('ok' => false, 'erro' => 'Conectou, mas não criou a tabela: ' . $err);
    }
  } catch (Exception $e) {
    @$mysqli->close();
    return array('ok' => false, 'erro' => 'Erro ao criar tabela: ' . $e->getMessage());
  } catch (Throwable $e) {
    @$mysqli->close();
    return array('ok' => false, 'erro' => 'Erro ao criar tabela: ' . $e->getMessage());
  }

  $mysqli->close();

  $token = gerar_token();
  $cfgOk = escrever_config($host, $name, $user, $pass, $token, $cors);
  $jsOk = escrever_token_js($token);
  escrever_config_js_raiz($token);

  if (!$cfgOk || !$jsOk) {
    return array(
      'ok' => false,
      'erro' => 'Conectou no banco e criou a tabela, mas a pasta api/ não deixou gravar arquivos (permissão). '
        . 'No Gerenciador de Arquivos da Hostinger: pasta api → Permissões → 755 (ou 775). '
        . 'Depois use o bloco “Baixar config.php” abaixo e envie os arquivos manualmente.',
      'token' => $token,
      'manual' => true,
      'host' => $host,
      'name' => $name,
      'user' => $user,
      'pass' => $pass
    );
  }

  @file_put_contents(__DIR__ . '/.installed', gmdate('c'));
  return array(
    'ok' => true,
    'token' => $token,
    'appUrl' => url_app(),
    'apiUrl' => url_api(),
    'cors' => trim($cors)
  );
}

$appUrl = url_app();
$apiUrl = url_api();
$hostVal = 'localhost';
$nameVal = '';
$userVal = '';
$passVal = '';
$corsVal = '';
$tokenManual = '';
$geradoCors = '';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reinstalar'])) {
    @unlink($lockFile);
    $jaInstalado = false;
  } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['instalar']) || isset($_POST['db_name']))) {
    $hostVal = isset($_POST['db_host']) ? $_POST['db_host'] : 'localhost';
    $nameVal = isset($_POST['db_name']) ? $_POST['db_name'] : '';
    $userVal = isset($_POST['db_user']) ? $_POST['db_user'] : '';
    $passVal = isset($_POST['db_pass']) ? $_POST['db_pass'] : '';
    $corsVal = isset($_POST['cors_origins']) ? trim($_POST['cors_origins']) : '';

    $resultado = instalar($hostVal, $nameVal, $userVal, $passVal, $corsVal);
    if (!empty($resultado['ok'])) {
      $ok = true;
      $jaInstalado = true;
      $geradoToken = $resultado['token'];
      $geradoCors = isset($resultado['cors']) ? $resultado['cors'] : $corsVal;
      $appUrl = isset($resultado['appUrl']) ? $resultado['appUrl'] : $appUrl;
      $apiUrl = isset($resultado['apiUrl']) ? $resultado['apiUrl'] : $apiUrl;
    } else {
      $erro = isset($resultado['erro']) ? $resultado['erro'] : 'Falha na instalação.';
      if (!empty($resultado['token'])) {
        $tokenManual = $resultado['token'];
        $avisoPermissao = '1';
      }
    }
  }
} catch (Exception $e) {
  $erro = 'Erro interno no instalador: ' . $e->getMessage();
} catch (Throwable $e) {
  $erro = 'Erro interno no instalador: ' . $e->getMessage();
}

$self = 'ativar.php';
$phpVer = PHP_VERSION;
$mysqliOk = (extension_loaded('mysqli') || class_exists('mysqli')) ? 'sim' : 'não';
$writable = is_writable(__DIR__) ? 'sim' : 'não';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ativar Acompanha-Aí · Hostinger</title>
<style>
  :root { font-family: system-ui, sans-serif; }
  body {
    margin: 0; min-height: 100vh;
    background: radial-gradient(900px 420px at 8% -8%, rgba(229,57,53,.12), transparent 55%), #F6F1F1;
    color: #1A1212;
  }
  .box {
    max-width: 580px; margin: 40px auto; background: #fff;
    border: 1px solid #E0D4D4; border-radius: 16px; padding: 28px 26px;
    box-shadow: 0 12px 40px rgba(122,16,16,.12);
  }
  h1 { margin: 0 0 8px; font-size: 1.35rem; }
  p, li { color: #6B5757; line-height: 1.5; font-size: .92rem; }
  label { display: block; font-weight: 600; font-size: .82rem; margin: 14px 0 6px; }
  input {
    width: 100%; box-sizing: border-box; padding: 10px 12px;
    border: 1px solid #E0D4D4; border-radius: 10px; font: inherit;
  }
  button, .btn {
    display: block; margin-top: 18px; width: 100%; padding: 12px; box-sizing: border-box;
    border: 0; border-radius: 10px; cursor: pointer; font-weight: 700; text-align: center;
    text-decoration: none; color: #fff; background: linear-gradient(135deg, #E53935, #8B1515);
    font: inherit;
  }
  .btn-ghost {
    background: #fff; color: #7A1010; border: 1px solid #E0D4D4; font-weight: 600;
  }
  .ok { background: #D8F3E6; color: #1A7A4C; padding: 12px 14px; border-radius: 10px; }
  .err { background: #F8E4E4; color: #B71C1C; padding: 12px 14px; border-radius: 10px; }
  .hint { background: #F7ECD8; color: #7A5A18; padding: 12px 14px; border-radius: 10px; font-size: .85rem; }
  .diag { font-size: .78rem; color: #6B5757; background: #F6F1F1; padding: 10px 12px; border-radius: 10px; }
  code { background: #F0E8E8; padding: 1px 6px; border-radius: 4px; word-break: break-all; }
  ol { padding-left: 18px; }
  details { margin-top: 18px; font-size: .85rem; color: #6B5757; }
  textarea { width: 100%; min-height: 120px; box-sizing: border-box; font-family: ui-monospace, monospace; font-size: .75rem; }
</style>
</head>
<body>
  <div class="box">
    <h1>Acompanha-Aí · ativar servidor</h1>

    <p class="diag">
      Diagnóstico: PHP <?php echo h($phpVer); ?> · mysqli: <?php echo h($mysqliOk); ?> ·
      pasta api gravável: <?php echo h($writable); ?>
    </p>

    <?php if ($ok): ?>
      <p class="ok">Instalação concluída. Os dados passam a gravar no MySQL automaticamente.</p>
      <ol>
        <li>Abra o sistema e use <strong>Ctrl+F5</strong> para recarregar.</li>
        <li>Confirme o status verde em <strong>Servidor</strong> (sincronização com o MySQL da Hostinger).</li>
        <li><strong>Apague</strong> <code>api/ativar.php</code>, <code>api/install.php</code> e <code>api/teste.php</code>.</li>
      </ol>
      <p class="hint">API: <code><?php echo h($apiUrl); ?></code><?php if ($geradoCors): ?><br>CORS extra: <code><?php echo h($geradoCors); ?></code><?php endif; ?></p>
      <textarea readonly id="configJsBox">window.ACOMPANHA_API_URL = <?php echo json_encode($apiUrl); ?>;
window.ACOMPANHA_API_TOKEN = <?php echo json_encode($geradoToken); ?>;
</textarea>
      <button type="button" class="btn-ghost" id="btnBaixarConfigJs">Baixar config.js</button>
      <a class="btn" href="<?php echo h($appUrl); ?>">Abrir o Acompanha-Aí nesta Hostinger</a>

    <?php elseif ($jaInstalado): ?>
      <p class="ok">O servidor já está instalado.</p>
      <a class="btn" href="<?php echo h($appUrl); ?>">Abrir o Acompanha-Aí</a>
      <form method="post" action="<?php echo h($self); ?>">
        <button class="btn-ghost" type="submit" name="reinstalar" value="1">Reinstalar (novo token)</button>
      </form>

    <?php else: ?>
      <p class="hint">
        Use o <strong>nome completo</strong> do banco e do usuário do hPanel
        (ex.: <code>u123456789_acompanha</code>). Host: <code>localhost</code>.
      </p>
      <?php if ($erro): ?><p class="err"><?php echo h($erro); ?></p><?php endif; ?>

      <form method="post" action="<?php echo h($self); ?>" id="formInstalar">
        <label for="db_host">Host MySQL</label>
        <input id="db_host" name="db_host" value="<?php echo h($hostVal); ?>" required autocomplete="off">

        <label for="db_name">Nome do banco (completo)</label>
        <input id="db_name" name="db_name" value="<?php echo h($nameVal); ?>" required placeholder="usuario_acompanha" autocomplete="off">

        <label for="db_user">Usuário do banco (completo)</label>
        <input id="db_user" name="db_user" value="<?php echo h($userVal); ?>" required autocomplete="off">

        <label for="db_pass">Senha do banco</label>
        <input id="db_pass" name="db_pass" type="password" value="" autocomplete="new-password">

        <label for="cors_origins">URL extra (opcional)</label>
        <input id="cors_origins" name="cors_origins" value="<?php echo h($corsVal); ?>" placeholder="https://outro-dominio.com" autocomplete="off">
        <p style="margin:6px 0 0;font-size:.78rem;">Deixe vazio se o site e a API ficarem no mesmo domínio da Hostinger (recomendado). Várias origens: separe por vírgula.</p>

        <button type="submit" name="instalar" value="1">Criar tabela e ativar</button>
      </form>

      <details open>
        <summary><strong>Instalação manual (se o botão der erro)</strong></summary>
        <ol>
          <li>hPanel → <strong>phpMyAdmin</strong> → selecione o banco → aba SQL → cole e execute:</li>
        </ol>
        <textarea readonly id="sqlBox">CREATE TABLE IF NOT EXISTS acompanha_store (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  payload LONGTEXT NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</textarea>
        <button type="button" class="btn-ghost" id="btnCopiarSql">Copiar SQL</button>

        <ol start="2">
          <li>Preencha os campos acima e clique em <strong>Gerar arquivos</strong>.</li>
          <li>Baixe <code>config.php</code> e <code>public-token.js</code> e envie para a pasta <code>api/</code> no Gerenciador de Arquivos (substitua os existentes).</li>
          <li>Abra o site com Ctrl+F5.</li>
        </ol>

        <button type="button" class="btn-ghost" id="btnGerarArquivos">Gerar arquivos para download</button>
        <p id="manualMsg" style="display:none;"></p>
      </details>
    <?php endif; ?>
  </div>

  <script>
    (function () {
      var sqlBtn = document.getElementById('btnCopiarSql');
      if (sqlBtn) {
        sqlBtn.addEventListener('click', function () {
          var t = document.getElementById('sqlBox');
          t.select();
          try { navigator.clipboard.writeText(t.value); } catch (e) { document.execCommand('copy'); }
          sqlBtn.textContent = 'SQL copiado!';
        });
      }

      function download(nome, texto) {
        var blob = new Blob([texto], { type: 'text/plain;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = nome;
        a.click();
        URL.revokeObjectURL(a.href);
      }

      function phpExport(s) {
        return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
      }

      var btn = document.getElementById('btnGerarArquivos');
      if (btn) {
        btn.addEventListener('click', function () {
          var host = document.getElementById('db_host').value.trim() || 'localhost';
          var name = document.getElementById('db_name').value.trim();
          var user = document.getElementById('db_user').value.trim();
          var pass = document.getElementById('db_pass').value;
          var msg = document.getElementById('manualMsg');
          if (!name || !user) {
            msg.style.display = 'block';
            msg.className = 'err';
            msg.textContent = 'Preencha banco e usuário antes de gerar.';
            return;
          }
          var token = '';
          var chars = 'abcdef0123456789';
          for (var i = 0; i < 48; i++) token += chars[Math.floor(Math.random() * chars.length)];

          var openTag = '<' + '?php\n';
          var corsEl = document.getElementById('cors_origins');
          var cors = corsEl ? corsEl.value.trim() : '';
          var cfg = openTag
            + "define('DB_HOST', " + phpExport(host) + ");\n"
            + "define('DB_NAME', " + phpExport(name) + ");\n"
            + "define('DB_USER', " + phpExport(user) + ");\n"
            + "define('DB_PASS', " + phpExport(pass) + ");\n"
            + "define('API_TOKEN', " + phpExport(token) + ");\n"
            + "define('API_CORS_ORIGINS', " + phpExport(cors) + ");\n";

          var js = 'window.ACOMPANHA_API_TOKEN = ' + JSON.stringify(token) + ';\n';
          download('config.php', cfg);
          setTimeout(function () { download('public-token.js', js); }, 400);

          msg.style.display = 'block';
          msg.className = 'ok';
          msg.innerHTML = 'Arquivos gerados. Envie os dois para a pasta <code>api/</code> na Hostinger (substituindo). Token: <code>' + token + '</code>';
        });
      }

      var btnCfg = document.getElementById('btnBaixarConfigJs');
      if (btnCfg) {
        btnCfg.addEventListener('click', function () {
          var t = document.getElementById('configJsBox');
          download('config.js', t ? t.value : '');
          btnCfg.textContent = 'config.js baixado';
        });
      }

      <?php if ($tokenManual): ?>
      // Se o banco conectou mas não gravou arquivo, ainda assim oferece download.
      (function () {
        var msg = document.getElementById('manualMsg');
        if (msg) {
          msg.style.display = 'block';
          msg.className = 'hint';
          msg.textContent = 'Banco OK. Use “Gerar arquivos” e envie config.php + public-token.js para api/.';
        }
      })();
      <?php endif; ?>
    })();
  </script>
</body>
</html>
