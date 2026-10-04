<?php
// Plateforme SALW : /admin mène à l'espace de gestion (/app/), comme /admin sur le site Sam. Les paramètres sont conservés.
declare(strict_types=1);
$q = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: ../app/' . ($q !== '' ? '?' . $q : ''), true, 302);
exit;
