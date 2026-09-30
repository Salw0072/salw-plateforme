<?php
/**
 * Plateforme SALW : réponse d'un client à un devis, depuis le lien de relance.
 * Rien ne se fait à l'ouverture du lien : le client clique sur « J'accepte » ou « Je refuse ».
 */

declare(strict_types=1);
require dirname(__DIR__) . '/prive/public.php';

$dv = une('SELECT * FROM devis WHERE jeton = ?', [preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''))]);
if (!$dv) {
    page_erreur('Lien invalide', 'Ce devis est introuvable.');
}
$c = clinique((int)$dv['clinique_id']);
$csrf = jeton_csrf_public();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_public_valide() && in_array($dv['statut'], ['envoye', 'sans_suite'], true)) {
    $accepte = ($_POST['reponse'] ?? '') === 'oui';
    repondre_devis($c, $dv, $accepte);
    $message = $accepte ? 'Merci ! Votre accord est bien enregistré : nous vous recontactons pour fixer la suite.' : 'C\'est noté. Merci de votre réponse.';
    $dv = une('SELECT * FROM devis WHERE id = ?', [$dv['id']]);
}
$h = '<div class="carte"><h1>Votre devis</h1>' . ($message !== '' ? '<p class="info" role="status">' . h($message) . '</p>' : '')
    . '<p class="grand">' . h($dv['libelle']) . '</p>' . ((float)$dv['montant'] > 0 ? '<p>Montant : <b>' . h(euros((float)$dv['montant'])) . '</b></p>' : '')
    . '<p class="petit">Envoyé le ' . h(local($c, $dv['envoye_le'], 'd/m/Y')) . ' par ' . h($c['nom']) . '.</p>';
if ($dv['statut'] === 'accepte') {
    $h .= '<p class="ok-texte">✓ Devis accepté</p>';
} elseif ($dv['statut'] === 'refuse') {
    $h .= '<p>Vous avez décliné ce devis. Une question ? Appelez-nous au ' . h(telephone_lisible((string)$c['telephone'])) . '.</p>';
} else {
    $h .= '<div class="deux-boutons"><form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="reponse" value="oui"><button class="btn">J\'accepte ce devis</button></form>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="reponse" value="non"><button class="btn contour">Je refuse</button></form></div>'
        . '<p class="petit">Une question avant de décider ? Appelez-nous au ' . h(telephone_lisible((string)$c['telephone'])) . '.</p>';
}
page_patient($c, 'Votre devis', $h . '</div>');
