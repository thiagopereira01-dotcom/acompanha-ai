<?php
/**
 * Copie este arquivo para config.php e preencha os dados do MySQL
 * (cPanel → Bancos de Dados MySQL).
 *
 * O instalador (install.php) também gera o config.php automaticamente.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'seu_banco');
define('DB_USER', 'seu_usuario');
define('DB_PASS', 'sua_senha');

/** Segredo compartilhado com o site. O instalador gera um valor aleatório. */
define('API_TOKEN', 'troque-por-um-segredo-longo');
