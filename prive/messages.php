<?php
/**
 * Plateforme SALW Santé : envoi des messages aux patients.
 *
 * Un seul point de sortie, envoyer(). En mode « simulation », rien ne part : le message
 * est enregistré et s'affiche dans l'application (téléphone à l'écran), liens cliquables.
 * En mode « reel » : SMS par Twilio si le patient a un mobile, sinon e-mail.
 * Les SMS ne contiennent aucune information médicale (ni motif ni spécialité).
 */

declare(strict_types=1);

require_once __DIR__ . '/reseaux.php';

/** Remplit un modèle de message : {prenom} {date} {heure} {clinique} {praticien} {adresse} {lien}. */
function rediger(array $c, string $type, array $vars): string
{
    $textes = reglages($c)['textes'];
    $modele = (string)($textes[$type] ?? textes_defaut($c)[$type] ?? '');
    $vars += ['clinique' => $c['nom'], 'structure' => $c['nom'], 'adresse' => trim($c['adresse'] . ' ' . $c['ville'])];
    $sortie = preg_replace_callback('/\{([a-z]+)\}/', function ($m) use ($vars) {
        return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
    }, $modele);
    $texte = trim(preg_replace('/\s{2,}/', ' ', $sortie));
    // Valeurs gardées pour WhatsApp, dont les modèles reçoivent les variables une par une.
    $GLOBALS['salw_variables'][md5($texte)] = $vars;
    return $texte;
}

/** Variables utilisées pour rédiger ce texte (voir rediger). */
function vars_message(string $texte): array
{
    return $GLOBALS['salw_variables'][md5($texte)] ?? [];
}

/**
 * Envoie un message à un patient (ou à un numéro seul, pour un appel manqué).
 * Renvoie l'identifiant du message enregistré.
 */
function envoyer(array $c, ?array $patient, string $destinataire, string $type, string $texte, ?int $rdvId = null): int
{
    $canal = strpos($destinataire, '@') !== false ? 'email' : 'sms';
    if ($canal === 'sms') {
        $texte = sms_gsm($texte);
    }
    if ($patient && (int)$patient['stop_sms'] === 1 && $canal === 'sms' && $type === 'reactivation') {
        return 0;
    }
    $statut = 'simule';
    $erreur = '';
    // Les démonstrations restent toujours en simulation : leurs numéros sont fictifs.
    if (cfg('mode_envoi') === 'reel' && !(int)$c['demo']) {
        [$ok, $erreur] = $canal === 'sms' ? envoyer_sms_twilio($destinataire, $texte) : envoyer_email($c, $destinataire, $texte);
        $statut = $ok ? 'envoye' : 'echec';
    }
    $id = inserer('INSERT INTO messages (clinique_id, patient_id, destinataire, canal, type, contenu, statut, envoye_le, rdv_id, erreur) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$c['id'], $patient['id'] ?? null, $destinataire, $canal, $type, $texte, $statut, iso(maintenant($c)), $rdvId, $erreur]);
    evenement($c, 'message_' . $type, ($patient ? $patient['prenom'] . ' ' . $patient['nom'] : telephone_lisible($destinataire)));
    return $id;
}

/** Envoie au canal du patient : mobile en priorité, sinon e-mail. */
function envoyer_patient(array $c, array $patient, string $type, string $texte, ?int $rdvId = null): int
{
    // WhatsApp si le client l'a accepté et que la structure est connectée ; en cas d'échec, SMS.
    if (whatsapp_possible($c, $patient, $type)) {
        $id = envoyer_whatsapp($c, $patient, $type, $texte, $rdvId);
        if ($id) {
            return $id;
        }
    }
    $dest = $patient['telephone'] !== '' ? $patient['telephone'] : $patient['email'];
    return $dest === '' ? 0 : envoyer($c, $patient, $dest, $type, $texte, $rdvId);
}

function envoyer_sms_twilio(string $a, string $texte): array
{
    $t = (array)cfg('twilio');
    if (($t['sid'] ?? '') === '' || ($t['token'] ?? '') === '' || ($t['expediteur'] ?? '') === '' || !function_exists('curl_init')) {
        return [false, 'Twilio non configuré'];
    }
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($t['sid']) . '/Messages.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['From' => $t['expediteur'], 'To' => $a, 'Body' => $texte]),
        CURLOPT_USERPWD => $t['sid'] . ':' . $t['token'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $rep = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code >= 200 && $code < 300, $code >= 300 ? mb_substr((string)$rep, 0, 300) : ''];
}

function envoyer_email(array $c, string $a, string $texte): array
{
    $exp = (string)cfg('email_expediteur');
    $entetes = "From: =?UTF-8?B?" . base64_encode($c['nom']) . "?= <{$exp}>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    $ok = @mail($a, '=?UTF-8?B?' . base64_encode($c['nom']) . '?=', chunk_split(base64_encode($texte)), $entetes);
    return [$ok, $ok ? '' : 'mail() a échoué'];
}

/**
 * Remplace les caractères hors alphabet SMS standard (GSM 7 bits) par leur équivalent : un seul « ê »
 * ou un guillemet « » ferait passer tout le message à 70 caractères par SMS, donc à 2 ou 3 SMS facturés.
 * Les accents courants du français (é è à ù) font partie de l'alphabet et sont conservés.
 */
function sms_gsm(string $t): string
{
    $t = strtr($t, [
        'â' => 'a', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c', 'ÿ' => 'y',
        'À' => 'A', 'Â' => 'A', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ù' => 'U', 'Û' => 'U',
        'œ' => 'oe', 'Œ' => 'OE', '°' => 'o', '«' => '"', '»' => '"', '“' => '"', '”' => '"', '‘' => "'", '’' => "'", '…' => '...',
        '–' => '-', '—' => '-', "\u{00A0}" => ' ', "\u{202F}" => ' ',
    ]);
    return trim((string)preg_replace('/ {2,}/', ' ', $t));
}

/** Nombre de SMS facturés pour un texte (GSM 7 bits : 160 / 153 ; avec accents hors GSM : 70 / 67). */
function segments_sms(string $texte): int
{
    $gsm = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€";
    $unicode = false;
    foreach (preg_split('//u', $texte, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (mb_strpos($gsm, $ch) === false) {
            $unicode = true;
            break;
        }
    }
    $n = mb_strlen($texte);
    if ($unicode) {
        return $n <= 70 ? 1 : (int)ceil($n / 67);
    }
    return $n <= 160 ? 1 : (int)ceil($n / 153);
}
