<?php
/**
 * Plateforme SALW : notifications PayPal (abonnements, paiements).
 *
 * À déclarer dans developer.paypal.com › Apps & Credentials › votre application › Webhooks,
 * adresse https://<plateforme>/webhooks/paypal.php, événements : BILLING.SUBSCRIPTION.ACTIVATED,
 * BILLING.SUBSCRIPTION.RE-ACTIVATED, BILLING.SUBSCRIPTION.SUSPENDED, BILLING.SUBSCRIPTION.PAYMENT.FAILED,
 * BILLING.SUBSCRIPTION.CANCELLED, BILLING.SUBSCRIPTION.EXPIRED, PAYMENT.SALE.COMPLETED.
 * L'identifiant du webhook va dans prive/config.php › paypal_webhook_id ; chaque notification est
 * vérifiée auprès de PayPal avant d'être prise en compte.
 */

declare(strict_types=1);
require dirname(__DIR__) . '/prive/noyau.php';
require dirname(__DIR__) . '/prive/messages.php';
require dirname(__DIR__) . '/prive/agenda.php';
require dirname(__DIR__) . '/prive/rapport.php';
require dirname(__DIR__) . '/prive/tarifs.php';
require dirname(__DIR__) . '/prive/paiement.php';

$corps = (string)file_get_contents('php://input', false, null, 0, 1000000);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !paypal_actif()) {
    repondre_json(400, ['ok' => false]);
}
try {
    $valide = paypal_notification_valide(entetes_recus(), $corps);
} catch (RuntimeException $e) {
    repondre_json(503, ['ok' => false]);
}
if (!$valide) {
    repondre_json(400, ['ok' => false]);
}
traiter_evenement_paypal((array)json_decode($corps, true));
repondre_json(200, ['ok' => true]);
