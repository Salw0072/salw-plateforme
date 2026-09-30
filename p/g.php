<?php
/**
 * Plateforme SALW Santé : gestion d'un rendez-vous depuis le lien du SMS (confirmer, annuler, déplacer).
 * Rien ne se fait à l'ouverture du lien (les antivirus de messagerie « visitent » les liens) : tout passe par un clic.
 */

declare(strict_types=1);
require dirname(__DIR__) . '/prive/public.php';

$rdv = une('SELECT * FROM rdv WHERE jeton = ?', [preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''))]);
if (!$rdv) {
    page_erreur('Lien invalide', 'Ce lien de rendez-vous n\'est pas valide.');
}
$c = clinique((int)$rdv['clinique_id']);
$patient = une('SELECT * FROM patients WHERE id = ?', [$rdv['patient_id']]);
$type = $rdv['type_id'] ? une('SELECT * FROM types_rdv WHERE id = ?', [$rdv['type_id']]) : ['id' => null, 'libelle' => 'Consultation', 'duree' => (ts($rdv['fin']) - ts($rdv['debut'])) / 60];
$pr = une('SELECT * FROM praticiens WHERE id = ?', [$rdv['praticien_id']]);
$csrf = jeton_csrf_public();
$futur = ts($rdv['debut']) > maintenant($c);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_public_valide() && $futur && $rdv['statut'] === 'confirme') {
    $a = (string)($_POST['action'] ?? '');
    if ($a === 'confirmer') {
        executer('UPDATE rdv SET confirme_patient_le = ? WHERE id = ?', [iso(maintenant($c)), $rdv['id']]);
        evenement($c, 'rdv_confirme_patient', $patient['prenom'] . ' ' . $patient['nom']);
        $message = 'Merci, votre présence est confirmée.';
    } elseif ($a === 'annuler') {
        annuler_rdv($c, $rdv, 'client');
        $message = 'Votre rendez-vous est annulé. Merci d\'avoir prévenu : le créneau peut servir à quelqu\'un d\'autre.';
    } elseif ($a === 'deplacer') {
        [$praticienId, $debut] = explode('|', (string)($_POST['creneau'] ?? '|') . '|');
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $debut)) {
            [$nouveau, $err] = creer_rdv($c, $patient, (int)$praticienId, $type['id'] !== null ? (int)$type['id'] : null, ts($debut), (int)$type['duree'], 'en_ligne');
            if ($nouveau) {
                annuler_rdv($c, $rdv, 'client (déplacement)');
                header('Location: g.php?t=' . $nouveau['jeton'] . '&deplace=1');
                exit;
            }
            $message = $err;
        }
    }
    $rdv = une('SELECT * FROM rdv WHERE id = ?', [$rdv['id']]);
}
if (isset($_GET['deplace'])) {
    $message = 'Votre rendez-vous est déplacé. Une nouvelle confirmation vous a été envoyée.';
}

$h = '<div class="carte"><h1>Votre rendez-vous</h1>' . ($message !== '' ? '<p class="info" role="status">' . h($message) . '</p>' : '')
    . '<p class="grand">' . h(ucfirst(date_longue($c, $rdv['debut']))) . ' à ' . h(heure($c, $rdv['debut'])) . '</p>'
    . '<p>' . h(nom_pro($pr)) . ' · ' . h($type['libelle']) . '<br>' . h(trim($c['adresse'] . ', ' . $c['ville'], ', ')) . '</p>';
if ($rdv['statut'] === 'annule') {
    $h .= '<p><b>Ce rendez-vous est annulé.</b></p><a class="btn" href="rdv.php?c=' . h($c['slug']) . '">Prendre un autre rendez-vous</a>';
} elseif (!$futur) {
    $h .= '<p>Ce rendez-vous est passé.</p>';
} else {
    $h .= ($rdv['confirme_patient_le'] !== '' ? '<p class="ok-texte">✓ Présence confirmée</p>' : '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="confirmer"><button class="btn">Je confirme ma présence</button></form>')
        . '<div class="deux-boutons"><a class="btn contour" href="?t=' . h($rdv['jeton']) . '&changer=1#deplacer">Déplacer</a>'
        . '<form method="post" onsubmit="return confirm(\'Annuler ce rendez-vous ?\')"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="annuler"><button class="btn contour">Annuler</button></form></div>';
    if (isset($_GET['changer'])) {
        $h .= '<form method="post" class="form" id="deplacer"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="deplacer"><h2>Choisir un autre créneau</h2><label>Créneau<select name="creneau" required>';
        foreach (creneaux_libres($c, $type['id'] !== null ? $type : ['duree' => $type['duree']], (int)$rdv['praticien_id'], 60) as $cr) {
            $h .= '<option value="' . $cr['praticien_id'] . '|' . h($cr['debut']) . '">' . h(ucfirst(date_longue($c, $cr['debut'])) . ' à ' . heure($c, $cr['debut'])) . '</option>';
        }
        $h .= '</select></label><button class="btn">Déplacer mon rendez-vous</button></form>';
    }
}
page_patient($c, 'Votre rendez-vous', $h . '</div>');
