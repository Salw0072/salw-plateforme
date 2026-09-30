<?php
/**
 * Plateforme SALW Santé : proposition d'un créneau libéré (liste d'attente). Le premier qui accepte l'obtient.
 */

declare(strict_types=1);
require dirname(__DIR__) . '/prive/public.php';

$offre = une('SELECT * FROM offres WHERE jeton = ?', [preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''))]);
if (!$offre) {
    page_erreur('Lien invalide', 'Cette proposition n\'existe pas.');
}
$c = clinique((int)$offre['clinique_id']);
$pr = une('SELECT * FROM praticiens WHERE id = ?', [$offre['praticien_id']]);
$csrf = jeton_csrf_public();
$h = '<div class="carte"><h1>Un créneau s\'est libéré</h1><p class="grand">' . h(ucfirst(date_longue($c, $offre['debut']))) . ' à ' . h(heure($c, $offre['debut'])) . '</p><p>' . h(nom_pro($pr)) . '</p>';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_public_valide()) {
    [$rdv, $raison] = accepter_offre($c, $offre);
    if ($rdv) {
        page_patient($c, 'Créneau obtenu', $h . '<p class="ok-texte">✓ Ce créneau est à vous. Une confirmation vous a été envoyée par SMS.</p><a class="btn contour" href="g.php?t=' . h($rdv['jeton']) . '">Gérer ce rendez-vous</a></div>');
    }
    page_patient($c, 'Créneau déjà attribué', $h . '<p>' . h($raison) . '</p></div>');
}
if ($offre['statut'] !== 'envoyee' || ts($offre['expire_le']) < maintenant($c)) {
    page_patient($c, 'Proposition terminée', $h . '<p>Ce créneau a déjà été attribué ou la proposition a expiré. Vous restez sur la liste d\'attente.</p></div>');
}
page_patient($c, 'Créneau disponible', $h . '<p>Plusieurs personnes ont reçu cette proposition : le premier qui confirme obtient le créneau. Valable jusqu\'à ' . h(heure($c, $offre['expire_le'])) . '.</p>'
    . '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><button class="btn">Je prends ce créneau</button></form></div>');
