<?php
/**
 * Plateforme SALW : notifications de Meta pour tous les clients (une seule adresse pour l'application Meta de SALW).
 *
 * - Prospects des publicités Facebook et Instagram (objet « page », champ « leadgen ») ;
 * - Statuts des messages WhatsApp (envoyé, distribué, lu, échec) et réponses « STOP » (objet « whatsapp_business_account »).
 *
 * À déclarer dans developers.facebook.com › votre application › Webhooks, adresse https://<plateforme>/webhooks/meta.php,
 * jeton de vérification = meta_jeton_verification (prive/config.php). Chaque notification est vérifiée par sa signature
 * (X-Hub-Signature-256, clé secrète de l'application = meta_app_secret).
 */

declare(strict_types=1);
require dirname(__DIR__) . '/prive/noyau.php';
require dirname(__DIR__) . '/prive/messages.php';
require dirname(__DIR__) . '/prive/agenda.php';
require dirname(__DIR__) . '/prive/tarifs.php';

// Vérification de l'adresse par Meta : renvoyer le défi si le jeton correspond.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $jeton = (string)cfg('meta_jeton_verification');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && $jeton !== '' && hash_equals($jeton, (string)($_GET['hub_verify_token'] ?? ''))) {
        header('Content-Type: text/plain; charset=utf-8');
        echo preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['hub_challenge'] ?? ''));
        exit;
    }
    http_response_code(403);
    exit;
}

$corps = (string)file_get_contents('php://input', false, null, 0, 2000000);
if (!meta_signature_valide($corps, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), (string)cfg('meta_app_secret'))) {
    repondre_json(401, ['ok' => false]);
}
$notification = json_decode($corps, true);
if (is_array($notification)) {
    traiter_notification_meta($notification);
}
repondre_json(200, ['ok' => true]);
