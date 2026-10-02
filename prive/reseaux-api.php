<?php
/**
 * SALW : appels aux API de Meta (WhatsApp, Facebook, Instagram, prospects des publicités) et de LinkedIn.
 * Fichier identique dans la plateforme et dans le site Sam. cURL seulement, sans bibliothèque externe.
 *
 * Versions d'API réglables (cfg meta_graph_version, linkedin_version) : Meta et LinkedIn en retirent
 * régulièrement ; une version trop ancienne est refusée avec un message explicite.
 */

declare(strict_types=1);

require_once __DIR__ . '/paiement-api.php';   // http_api()

function meta_base(): string
{
    return rtrim((string)(cfg('meta_api') ?: 'https://graph.facebook.com'), '/') . '/' . (cfg('meta_graph_version') ?: 'v23.0');
}

/** Appel à l'API Graph de Meta. Corps JSON pour WhatsApp, formulaire pour le reste. */
function meta(string $methode, string $chemin, array $params, string $jeton, bool $json = false): array
{
    $entetes = ['Authorization: Bearer ' . $jeton];
    $url = meta_base() . $chemin;
    if ($methode === 'GET') {
        $url .= $params ? '?' . http_build_query($params) : '';
        $corps = null;
    } elseif ($json) {
        $entetes[] = 'Content-Type: application/json';
        $corps = (string)json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $entetes[] = 'Content-Type: application/x-www-form-urlencoded';
        $corps = http_build_query($params);
    }
    [$code, $r] = http_api($methode, $url, $entetes, $corps);
    if ($code >= 400 || $code === 0) {
        throw new RuntimeException('Meta : ' . ($r['error']['error_user_msg'] ?? $r['error']['message'] ?? 'erreur ' . $code));
    }
    return $r;
}

/** Signature X-Hub-Signature-256 des notifications Meta : « sha256= » + HMAC du corps avec le secret de l'application. */
function meta_signature_valide(string $corps, string $entete, string $secret): bool
{
    return $secret !== '' && strpos($entete, 'sha256=') === 0 && hash_equals('sha256=' . hash_hmac('sha256', $corps, $secret), $entete);
}

/** Numéro au format attendu par WhatsApp : chiffres seuls, indicatif compris (+33612… → 33612…). */
function numero_whatsapp(string $telephone): string
{
    return (string)preg_replace('/\D/', '', $telephone);
}

/** Envoie un modèle WhatsApp approuvé ; renvoie l'identifiant du message chez Meta. */
function whatsapp_modele(string $numeroId, string $jeton, string $destinataire, string $modele, string $langue, array $variables): string
{
    $composants = $variables ? [['type' => 'body', 'parameters' => array_map(function ($v) { return ['type' => 'text', 'text' => mb_substr((string)$v, 0, 1000)]; }, array_values($variables))]] : [];
    $r = meta('POST', '/' . $numeroId . '/messages', [
        'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => numero_whatsapp($destinataire), 'type' => 'template',
        'template' => ['name' => $modele, 'language' => ['code' => $langue]] + ($composants ? ['components' => $composants] : []),
    ], $jeton, true);
    return (string)($r['messages'][0]['id'] ?? '');
}

/** Publie sur une page Facebook (texte et lien) ; renvoie l'identifiant de la publication. */
function facebook_publier(string $pageId, string $jeton, string $texte, string $lien = ''): string
{
    return (string)(meta('POST', '/' . $pageId . '/feed', ['message' => $texte] + ($lien !== '' ? ['link' => $lien] : []), $jeton)['id'] ?? '');
}

/** Publie sur Instagram (image JPEG publique obligatoire) en deux étapes : conteneur, puis publication. */
function instagram_publier(string $compteId, string $jeton, string $imageUrl, string $legende): string
{
    $conteneur = (string)(meta('POST', '/' . $compteId . '/media', ['image_url' => $imageUrl, 'caption' => mb_substr($legende, 0, 2200)], $jeton)['id'] ?? '');
    if ($conteneur === '') {
        throw new RuntimeException('Instagram n\'a pas accepté l\'image.');
    }
    return (string)(meta('POST', '/' . $compteId . '/media_publish', ['creation_id' => $conteneur], $jeton)['id'] ?? '');
}

/** Lit un prospect issu d'un formulaire de publicité Facebook ou Instagram : [champ => valeur]. */
function meta_prospect(string $prospectId, string $jeton): array
{
    $r = meta('GET', '/' . $prospectId, ['fields' => 'created_time,field_data,form_id,ad_id,platform'], $jeton);
    $champs = [];
    foreach ($r['field_data'] ?? [] as $f) {
        $champs[mb_strtolower((string)($f['name'] ?? ''))] = (string)($f['values'][0] ?? '');
    }
    return ['champs' => $champs, 'formulaire' => (string)($r['form_id'] ?? ''), 'plateforme' => (string)($r['platform'] ?? '')];
}

/** Nom, téléphone, e-mail et accord WhatsApp d'un prospect, quels que soient les noms de champs du formulaire. */
function prospect_normalise(array $champs): array
{
    $cherche = function (array $noms) use ($champs): string {
        foreach ($noms as $n) {
            if (($champs[$n] ?? '') !== '') {
                return trim($champs[$n]);
            }
        }
        return '';
    };
    $prenom = $cherche(['first_name', 'prénom', 'prenom']);
    $nom = $cherche(['last_name', 'nom']);
    $complet = $cherche(['full_name', 'nom_complet', 'nom complet']);
    if ($complet !== '' && $prenom === '') {
        $morceaux = preg_split('/\s+/', $complet, 2);
        $prenom = $morceaux[0];
        $nom = $nom !== '' ? $nom : (string)($morceaux[1] ?? '');
    }
    $accord = mb_strtolower($cherche(['whatsapp', 'contact_whatsapp', 'accord_whatsapp']));
    return ['prenom' => $prenom, 'nom' => $nom, 'telephone' => $cherche(['phone_number', 'telephone', 'téléphone', 'phone']),
        'email' => $cherche(['email', 'e-mail', 'courriel']), 'whatsapp' => in_array($accord, ['oui', 'yes', 'true', '1', 'ok'], true)];
}

// --- LinkedIn ----------------------------------------------------------------------------------

/** Publie sur une page entreprise LinkedIn ; renvoie l'URN de la publication (en-tête x-restli-id). */
function linkedin_publier(string $organisation, string $jeton, string $texte): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extension PHP cURL absente.');
    }
    $base = rtrim((string)(cfg('linkedin_api') ?: 'https://api.linkedin.com'), '/');
    $auteur = strpos($organisation, 'urn:li:') === 0 ? $organisation : 'urn:li:organization:' . preg_replace('/\D/', '', $organisation);
    $ch = curl_init($base . '/rest/posts');
    $entetes = [];
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $jeton, 'Content-Type: application/json', 'X-Restli-Protocol-Version: 2.0.0',
            'LinkedIn-Version: ' . (cfg('linkedin_version') ?: '202608')],
        CURLOPT_POSTFIELDS => (string)json_encode([
            'author' => $auteur, 'commentary' => mb_substr($texte, 0, 3000), 'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HEADERFUNCTION => function ($c, $ligne) use (&$entetes) {
            $p = strpos($ligne, ':');
            if ($p !== false) {
                $entetes[strtolower(trim(substr($ligne, 0, $p)))] = trim(substr($ligne, $p + 1));
            }
            return strlen($ligne);
        },
    ]);
    $reponse = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($reponse === false || $code >= 400 || $code === 0) {
        $r = json_decode((string)$reponse, true);
        throw new RuntimeException('LinkedIn : ' . ($r['message'] ?? 'erreur ' . $code) . ($code === 401 ? ' (jeton expiré ? il dure 60 jours)' : ''));
    }
    return (string)($entetes['x-restli-id'] ?? '');
}

// --- Modèles WhatsApp --------------------------------------------------------------------------

/**
 * Modèle WhatsApp tiré d'un texte à variables {prenom}… : texte au format Meta ({{1}}, {{2}}…) et liste des variables
 * dans l'ordre. Meta refuse un modèle qui commence ou finit par une variable : on ajoute alors « Bonjour, » ou « À bientôt. ».
 */
function modele_whatsapp(string $texte): array
{
    $variables = [];
    $corps = (string)preg_replace_callback('/\{([a-z]+)\}/', function ($m) use (&$variables) {
        $variables[] = $m[1];
        return '{{' . count($variables) . '}}';
    }, trim($texte));
    if (preg_match('/^\{\{\d+\}\}/', $corps)) {
        $corps = 'Bonjour, ' . $corps;
    }
    if (preg_match('/\{\{\d+\}\}[.!?]?$/', $corps)) {
        $corps .= "\nÀ bientôt.";
    }
    return ['corps' => $corps, 'variables' => $variables];
}
