<?php
/**
 * Plateforme SALW : logo ou photo de couverture d'un client (rangés dans prive/, donc servis par ce script).
 */

declare(strict_types=1);
require dirname(__DIR__) . '/prive/noyau.php';

$c = clinique_par_slug((string)($_GET['c'] ?? ''));
$f = $c ? image_fichier($c, ($_GET['i'] ?? '') === 'couverture' ? 'couverture' : 'logo') : '';
if ($f === '' || !is_file($f)) {
    http_response_code(404);
    exit;
}
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
if (!isset($types[$ext])) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($f));
header('Cache-Control: public, max-age=2592000, immutable');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: cross-origin');
readfile($f);
