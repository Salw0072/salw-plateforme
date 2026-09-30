<?php
// Plateforme SALW : sur l'adresse web d'un client (ex. rdv.cabinet-durand.fr), l'accueil mène à sa page de réservation ;
// sinon, à l'espace de travail.
declare(strict_types=1);
require __DIR__ . '/prive/noyau.php';
$c = clinique_par_domaine((string)($_SERVER['HTTP_HOST'] ?? ''));
header('Location: ' . ($c ? 'p/rdv.php?c=' . rawurlencode($c['slug']) : 'app/'), true, 302);
exit;
