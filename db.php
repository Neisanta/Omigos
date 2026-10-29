<?php
/**
 * db.php — Compatibilidade: expõe $conn seguro via core.php.
 * Prefira require_once __DIR__ . '/core.php' em arquivos novos.
 */
require_once __DIR__ . '/core.php';
$conn = db();
