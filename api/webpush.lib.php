<?php
/**
 * Web Push (RFC 8030 / 8291 / 8292) sem Composer.
 * Requer OpenSSL (P-256) e cURL — padrão na Hostinger (PHP 8.1+).
 */

function webpush_b64url_encode($bin) {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function webpush_b64url_decode($str) {
  $str = strtr((string) $str, '-_', '+/');
  $pad = strlen($str) % 4;
  if ($pad) $str .= str_repeat('=', 4 - $pad);
  $out = base64_decode($str, true);
  return $out === false ? '' : $out;
}

function webpush_pad32($bin) {
  $bin = (string) $bin;
  if (strlen($bin) > 32) {
    $bin = substr($bin, -32);
  }
  return str_pad($bin, 32, "\0", STR_PAD_LEFT);
}

function webpush_public_pem($raw65) {
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw65;
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function webpush_private_pem($d32, $raw65) {
  $der = hex2bin('30770201010420') . $d32 . hex2bin('a00a06082a8648ce3d030107a144034200') . $raw65;
  return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

function webpush_der_ecdsa_para_jose($der) {
  $offset = 0;
  if (ord($der[$offset++]) !== 0x30) return '';
  $seqLen = ord($der[$offset++]);
  if ($seqLen & 0x80) {
    $n = $seqLen & 0x7f;
    $offset += $n;
  }
  if (ord($der[$offset++]) !== 0x02) return '';
  $rLen = ord($der[$offset++]);
  $r = substr($der, $offset, $rLen);
  $offset += $rLen;
  if (ord($der[$offset++]) !== 0x02) return '';
  $sLen = ord($der[$offset++]);
  $s = substr($der, $offset, $sLen);
  $r = webpush_pad32(ltrim($r, "\0"));
  $s = webpush_pad32(ltrim($s, "\0"));
  return $r . $s;
}

function webpush_par_ec() {
  if (!function_exists('openssl_pkey_new')) return null;
  $tentativas = array(
    array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC),
    array('ec' => array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC))
  );
  foreach ($tentativas as $cfg) {
    $key = @openssl_pkey_new($cfg);
    if (!$key) continue;
    $details = openssl_pkey_get_details($key);
    if (!$details || empty($details['ec']['x']) || empty($details['ec']['y']) || empty($details['ec']['d'])) continue;
    $x = webpush_pad32($details['ec']['x']);
    $y = webpush_pad32($details['ec']['y']);
    $d = webpush_pad32($details['ec']['d']);
    $raw = "\x04" . $x . $y;
    return array(
      'publicKey' => webpush_b64url_encode($raw),
      'privateD' => webpush_b64url_encode($d),
      'publicRaw' => $raw,
      'pem' => webpush_private_pem($d, $raw)
    );
  }
  return null;
}

function webpush_gerar_par_vapid() {
  $par = webpush_par_ec();
  if (!$par) return null;
  return array(
    'publicKey' => $par['publicKey'],
    'privateD' => $par['privateD'],
    'publicRaw' => $par['publicRaw']
  );
}

function webpush_jwt($audience, $subject, $privatePem) {
  $header = webpush_b64url_encode(json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
  $payload = webpush_b64url_encode(json_encode(array(
    'aud' => $audience,
    'exp' => time() + 12 * 3600,
    'sub' => $subject
  )));
  $input = $header . '.' . $payload;
  $pkey = openssl_pkey_get_private($privatePem);
  if (!$pkey) return '';
  $der = '';
  if (!openssl_sign($input, $der, $pkey, OPENSSL_ALGO_SHA256) || $der === '') {
    return '';
  }
  $jose = webpush_der_ecdsa_para_jose($der);
  if ($jose === '') return '';
  return $input . '.' . webpush_b64url_encode($jose);
}

function webpush_cifrar($payload, $uaPublicRaw, $authSecret) {
  if (!function_exists('hash_hkdf') || !function_exists('openssl_pkey_derive')) {
    return '';
  }
  $local = webpush_par_ec();
  if (!$local) return '';
  $asPublicRaw = $local['publicRaw'];
  $salt = random_bytes(16);
  $peer = openssl_pkey_get_public(webpush_public_pem($uaPublicRaw));
  $asKey = openssl_pkey_get_private($local['pem']);
  if (!$peer || !$asKey) return '';
  $shared = openssl_pkey_derive($peer, $asKey);
  if ($shared === false || $shared === '') return '';
  $shared = webpush_pad32($shared);

  $keyInfo = 'WebPush: info' . "\0" . $uaPublicRaw . $asPublicRaw;
  $ikm = hash_hkdf('sha256', $shared, 32, $keyInfo, $authSecret);
  $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
  $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

  $plaintext = $payload . "\x02";
  $tag = '';
  $cipher = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '');
  if ($cipher === false || strlen($tag) !== 16) return '';

  $rs = pack('N', 4096);
  return $salt . $rs . chr(strlen($asPublicRaw)) . $asPublicRaw . $cipher . $tag;
}

function webpush_enviar($endpoint, $p256dh, $auth, $payloadJson, $vapid, $urgency = 'normal') {
  $uaPub = webpush_b64url_decode($p256dh);
  $authSecret = webpush_b64url_decode($auth);
  if (strlen($uaPub) === 64) $uaPub = "\x04" . $uaPub;
  if (strlen($uaPub) !== 65 || strlen($authSecret) < 16) {
    return array('ok' => false, 'http' => 0, 'erro' => 'chave_inscricao');
  }

  $asD = webpush_b64url_decode($vapid['privateD']);
  $asPub = isset($vapid['publicRaw']) && $vapid['publicRaw'] !== ''
    ? $vapid['publicRaw']
    : webpush_b64url_decode($vapid['publicKey']);
  if (strlen($asD) !== 32 || strlen($asPub) !== 65) {
    return array('ok' => false, 'http' => 0, 'erro' => 'vapid');
  }
  $vapidPem = webpush_private_pem($asD, $asPub);

  $body = webpush_cifrar($payloadJson, $uaPub, $authSecret);
  if ($body === '') {
    return array('ok' => false, 'http' => 0, 'erro' => 'cifra');
  }

  $parts = parse_url($endpoint);
  if (empty($parts['scheme']) || empty($parts['host'])) {
    return array('ok' => false, 'http' => 0, 'erro' => 'endpoint');
  }
  $audience = $parts['scheme'] . '://' . $parts['host'];
  $jwt = webpush_jwt($audience, $vapid['subject'], $vapidPem);
  if ($jwt === '') {
    return array('ok' => false, 'http' => 0, 'erro' => 'jwt');
  }

  if (!function_exists('curl_init')) {
    return array('ok' => false, 'http' => 0, 'erro' => 'curl');
  }

  $headers = array(
    'TTL: 86400',
    'Urgency: ' . ($urgency === 'high' ? 'high' : 'normal'),
    'Content-Type: application/octet-stream',
    'Content-Encoding: aes128gcm',
    'Content-Length: ' . strlen($body),
    'Authorization: vapid t=' . $jwt . ', k=' . $vapid['publicKey']
  );

  $ch = curl_init($endpoint);
  curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
  ));
  $raw = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($raw === false) {
    return array('ok' => false, 'http' => $http, 'erro' => $err !== '' ? $err : 'curl_exec');
  }
  $ok = $http >= 200 && $http < 300;
  $detalhe = '';
  if (!$ok) {
    $partsHdr = explode("\r\n\r\n", (string) $raw, 2);
    $detalhe = isset($partsHdr[1]) ? substr(preg_replace('/\s+/', ' ', $partsHdr[1]), 0, 180) : '';
  }
  return array('ok' => $ok, 'http' => $http, 'erro' => $ok ? '' : ('http_' . $http), 'detalhe' => $detalhe);
}
