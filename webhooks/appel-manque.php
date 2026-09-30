<?php
/**
 * Plateforme SALW Santé : signalement d'un appel manqué par le standard téléphonique.
 *
 * POST, en-tête « X-Cle: <cle_webhook> », champs : clinique (identifiant court), numero.
 * Compatible avec tout standard capable d'appeler une URL (Twilio Studio, Ringover, OVH, Aircall…).
 * Le SMS part au passage suivant du moteur (délai réglable dans « Automatisations »).
 */

declare(strict_types=1);
require dirname(__DIR__) . '/prive/noyau.php';

$cle = (string)cfg('cle_webhook');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || strlen($cle) < 24 || !hash_equals($cle, (string)($_SERVER['HTTP_X_CLE'] ?? ''))) {
    repondre_json(401, ['ok' => false]);
}
$c = clinique_par_slug((string)($_POST['clinique'] ?? ''));
$numero = $c ? telephone((string)($_POST['numero'] ?? ($_POST['From'] ?? '')), $c['pays']) : '';
if (!$c || $numero === '') {
    repondre_json(422, ['ok' => false, 'error' => 'clinique ou numéro invalide']);
}
inserer('INSERT INTO appels (clinique_id, numero, recu_le, source) VALUES (?, ?, ?, ?)', [$c['id'], $numero, iso(maintenant($c)), 'standard']);
evenement($c, 'appel_manque', telephone_lisible($numero));
repondre_json(200, ['ok' => true]);
