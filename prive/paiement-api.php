<?php
/**
 * SALW : appels aux API Stripe et PayPal. Fichier identique dans la plateforme et dans le site Sam.
 *
 * Aucune bibliothèque externe : cURL seulement. Les clés viennent de cfg() (prive/config.php,
 * ou les Réglages de l'espace admin pour Sam) et ne sortent jamais du serveur.
 * Montants en euros ; Stripe les attend en centimes, PayPal en texte (« 149.00 »).
 */

declare(strict_types=1);

function stripe_actif(): bool
{
    return (string)cfg('stripe_cle_secrete') !== '';
}

function paypal_actif(): bool
{
    return (string)cfg('paypal_client_id') !== '' && (string)cfg('paypal_secret') !== '';
}

function centimes(float $euros): int
{
    return (int)round($euros * 100);
}

function montant_paypal(float $euros): string
{
    return number_format($euros, 2, '.', '');
}

/** Requête HTTP ; renvoie [code, réponse JSON décodée]. */
function http_api(string $methode, string $url, array $entetes, ?string $corps): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extension PHP cURL absente : activez-la dans hPanel.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $methode,
        CURLOPT_HTTPHEADER => $entetes,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($corps !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);
    }
    $reponse = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur = curl_error($ch);
    curl_close($ch);
    if ($reponse === false) {
        throw new RuntimeException('Connexion au service de paiement impossible (' . $erreur . ').');
    }
    $json = json_decode((string)$reponse, true);
    return [$code, is_array($json) ? $json : []];
}

// --- Stripe ------------------------------------------------------------------------------------

/** Appel à l'API Stripe (paramètres encodés comme un formulaire, tableaux imbriqués compris). */
function stripe(string $methode, string $chemin, array $params = [], string $idempotence = ''): array
{
    $base = rtrim((string)(cfg('stripe_api') ?: 'https://api.stripe.com'), '/');
    $entetes = ['Authorization: Bearer ' . cfg('stripe_cle_secrete'), 'Content-Type: application/x-www-form-urlencoded'];
    if ($idempotence !== '') {
        $entetes[] = 'Idempotency-Key: ' . $idempotence;
    }
    $requete = http_build_query($params, '', '&');
    [$code, $r] = http_api($methode, $base . $chemin . ($methode === 'GET' && $requete !== '' ? '?' . $requete : ''), $entetes, $methode === 'GET' ? null : $requete);
    if ($code >= 400 || $code === 0) {
        throw new RuntimeException('Stripe : ' . ($r['error']['message'] ?? 'erreur ' . $code));
    }
    return $r;
}

/** Vérifie l'en-tête Stripe-Signature (HMAC SHA-256 de « horodatage.corps », 5 minutes de tolérance). */
function stripe_signature_valide(string $corps, string $entete, string $secret, int $tolerance = 300): bool
{
    $t = 0;
    $signatures = [];
    foreach (explode(',', $entete) as $morceau) {
        [$k, $v] = array_pad(explode('=', trim($morceau), 2), 2, '');
        if ($k === 't') {
            $t = (int)$v;
        } elseif ($k === 'v1') {
            $signatures[] = $v;
        }
    }
    if ($secret === '' || $t === 0 || !$signatures || abs(time() - $t) > $tolerance) {
        return false;
    }
    $attendue = hash_hmac('sha256', $t . '.' . $corps, $secret);
    foreach ($signatures as $s) {
        if (hash_equals($attendue, $s)) {
            return true;
        }
    }
    return false;
}

// --- PayPal ------------------------------------------------------------------------------------

function paypal_base(): string
{
    $defaut = cfg('paypal_mode') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    return rtrim((string)(cfg('paypal_api') ?: $defaut), '/');
}

function paypal_jeton(): string
{
    static $jeton = '';
    if ($jeton !== '') {
        return $jeton;
    }
    [$code, $r] = http_api('POST', paypal_base() . '/v1/oauth2/token',
        ['Authorization: Basic ' . base64_encode(cfg('paypal_client_id') . ':' . cfg('paypal_secret')), 'Content-Type: application/x-www-form-urlencoded'],
        'grant_type=client_credentials');
    if ($code >= 400 || empty($r['access_token'])) {
        throw new RuntimeException('PayPal : identifiants refusés (vérifiez le Client ID, le secret et le mode test ou réel).');
    }
    return $jeton = (string)$r['access_token'];
}

/** Appel à l'API REST PayPal (corps JSON). */
function paypal(string $methode, string $chemin, ?array $corps = null, string $idempotence = ''): array
{
    $entetes = ['Authorization: Bearer ' . paypal_jeton(), 'Content-Type: application/json', 'Prefer: return=representation'];
    if ($idempotence !== '') {
        $entetes[] = 'PayPal-Request-Id: ' . $idempotence;
    }
    [$code, $r] = http_api($methode, paypal_base() . $chemin, $entetes, $corps === null ? null : (string)json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($code >= 400 || $code === 0) {
        throw new RuntimeException('PayPal : ' . ($r['details'][0]['description'] ?? $r['message'] ?? 'erreur ' . $code));
    }
    return $r;
}

/** Lien d'une réponse PayPal (« approve », « payer-action »…). */
function paypal_lien(array $r, string ...$rels): string
{
    foreach ($rels as $rel) {
        foreach ($r['links'] ?? [] as $l) {
            if (($l['rel'] ?? '') === $rel) {
                return (string)$l['href'];
            }
        }
    }
    return '';
}

/** Vérifie une notification PayPal auprès de PayPal (seule méthode fiable : la signature n'est pas un simple HMAC). */
function paypal_notification_valide(array $entetes, string $corps): bool
{
    $id = (string)cfg('paypal_webhook_id');
    $evenement = json_decode($corps, true);
    if ($id === '' || !is_array($evenement)) {
        return false;
    }
    $h = array_change_key_case($entetes, CASE_LOWER);
    $r = paypal('POST', '/v1/notifications/verify-webhook-signature', [
        'auth_algo' => $h['paypal-auth-algo'] ?? '', 'cert_url' => $h['paypal-cert-url'] ?? '',
        'transmission_id' => $h['paypal-transmission-id'] ?? '', 'transmission_sig' => $h['paypal-transmission-sig'] ?? '',
        'transmission_time' => $h['paypal-transmission-time'] ?? '', 'webhook_id' => $id, 'webhook_event' => $evenement,
    ]);
    return ($r['verification_status'] ?? '') === 'SUCCESS';
}

/** En-têtes HTTP de la requête reçue (getallheaders n'existe pas partout). */
function entetes_recus(): array
{
    if (function_exists('getallheaders')) {
        return (array)getallheaders();
    }
    $h = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $h[str_replace('_', '-', strtolower(substr($k, 5)))] = $v;
        }
    }
    return $h;
}
