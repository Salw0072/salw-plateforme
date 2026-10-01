<?php
/**
 * Plateforme SALW : notifications Stripe (paiements, factures, résiliations).
 *
 * À déclarer dans Stripe › Développeurs › Webhooks, adresse https://<plateforme>/webhooks/stripe.php,
 * événements : checkout.session.completed, checkout.session.async_payment_succeeded,
 * checkout.session.async_payment_failed, invoice.paid, invoice.payment_failed,
 * customer.subscription.updated, customer.subscription.deleted.
 * Le secret de signature (whsec_…) va dans prive/config.php › stripe_secret_webhook.
 */

declare(strict_types=1);
require dirname(__DIR__) . '/prive/noyau.php';
require dirname(__DIR__) . '/prive/messages.php';
require dirname(__DIR__) . '/prive/agenda.php';
require dirname(__DIR__) . '/prive/rapport.php';
require dirname(__DIR__) . '/prive/tarifs.php';
require dirname(__DIR__) . '/prive/paiement.php';

$corps = (string)file_get_contents('php://input', false, null, 0, 1000000);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !stripe_signature_valide($corps, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), (string)cfg('stripe_secret_webhook'))) {
    repondre_json(400, ['ok' => false]);
}
$evenement = json_decode($corps, true);
if (is_array($evenement)) {
    traiter_evenement_stripe($evenement);
}
repondre_json(200, ['ok' => true]);
