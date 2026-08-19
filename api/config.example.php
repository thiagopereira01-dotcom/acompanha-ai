<?php
/**
 * Copie este arquivo para config.php e preencha os dados do MySQL
 * (hPanel Hostinger → Bancos de Dados → MySQL).
 *
 * O instalador (ativar.php) também gera o config.php automaticamente.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'seu_banco');
define('DB_USER', 'seu_usuario');
define('DB_PASS', 'sua_senha');

/** Segredo compartilhado com o site. O instalador gera um valor aleatório. */
define('API_TOKEN', 'troque-por-um-segredo-longo');

/**
 * Origens extras do site, separadas por vírgula.
 * Vazio = só o mesmo domínio (recomendado: site e API na Hostinger).
 * Ex.: https://outro-dominio.com
 */
define('API_CORS_ORIGINS', '');
