<?php
/**
 * Plateforme SALW Santé : lien de la demande d'avis. Compte le clic, puis ouvre la page d'avis Google de la clinique.
 */

declare(strict_types=1);
require dirname(__DIR__) . '/prive/public.php';

$rdv = une('SELECT * FROM rdv WHERE jeton = ?', [preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''))]);
$c = $rdv ? clinique((int)$rdv['clinique_id']) : null;
if (!$c || $c['avis_url'] === '') {
    page_erreur('Lien invalide', 'Ce lien n\'est plus valide.');
}
evenement($c, 'avis_clic', '');
header('Location: ' . $c['avis_url'], true, 302);
