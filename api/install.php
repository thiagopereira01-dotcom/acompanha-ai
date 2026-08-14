<?php
/**
 * Redireciona para o instalador atual (ativar.php).
 * Mantido para quem ainda abre /api/install.php
 */
header('Location: ativar.php', true, 302);
exit;
