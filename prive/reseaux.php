<?php
/**
 * Plateforme SALW : WhatsApp, Facebook, Instagram et LinkedIn pour chaque client.
 *
 * - WhatsApp : confirmations, rappels et relances envoyés par WhatsApp quand le client final l'a accepté
 *   (case cochée à la réservation, ou accord donné dans un formulaire de publicité), sinon par SMS.
 *   Chaque type de message correspond à un modèle à faire approuver par Meta (page Réseaux sociaux).
 * - Publications : créneaux libres de la semaine (lundi), créneau libéré à la dernière minute, publication libre.
 * - Prospects des publicités Facebook et Instagram : reçus par notification Meta, enregistrés, puis contactés
 *   automatiquement (WhatsApp s'ils l'ont accepté, sinon SMS ou e-mail) avec le lien de réservation.
 *
 * Connexions : identifiant et jeton par réseau (table reseaux), saisis par l'équipe SALW. Les jetons ne sont jamais réaffichés.
 */

declare(strict_types=1);

require_once __DIR__ . '/reseaux-api.php';

const RESEAUX = ['whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn'];

/** Messages envoyés par WhatsApp quand c'est possible, avec la catégorie Meta proposée pour leur modèle. */
const MODELES_WHATSAPP = [
    'confirmation' => 'UTILITY', 'rappel_j2' => 'UTILITY', 'rappel_j1' => 'UTILITY', 'rappel_h3' => 'UTILITY', 'offre_attente' => 'UTILITY',
    'relance_devis' => 'UTILITY', 'prospect' => 'UTILITY', 'avis' => 'MARKETING', 'absence' => 'UTILITY', 'reactivation' => 'MARKETING',
];

function nom_modele_whatsapp(string $type): string
{
    return 'salw_' . $type;
}

/** Connexion d'un client à un réseau, ou null. Instagram utilise le jeton de la page Facebook liée. */
function connexion(array $c, string $reseau): ?array
{
    $r = une('SELECT * FROM reseaux WHERE clinique_id = ? AND reseau = ?', [$c['id'], $reseau]);
    if (!$r || (string)$r['identifiant'] === '') {
        return null;
    }
    if ($reseau === 'instagram' && (string)$r['jeton'] === '') {
        $fb = une('SELECT jeton FROM reseaux WHERE clinique_id = ? AND reseau = ?', [$c['id'], 'facebook']);
        $r['jeton'] = (string)($fb['jeton'] ?? '');
    }
    return (string)$r['jeton'] !== '' ? $r : null;
}

function enregistrer_connexion(array $c, string $reseau, string $identifiant, string $jeton, string $expire = ''): void
{
    $avant = une('SELECT * FROM reseaux WHERE clinique_id = ? AND reseau = ?', [$c['id'], $reseau]);
    $jeton = $jeton !== '' ? $jeton : (string)($avant['jeton'] ?? '');   // champ laissé vide : jeton conservé
    db()->prepare('INSERT INTO reseaux (clinique_id, reseau, identifiant, jeton, nom, expire_le, maj) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(clinique_id, reseau) DO UPDATE SET identifiant = excluded.identifiant, jeton = excluded.jeton, expire_le = excluded.expire_le, maj = excluded.maj,
        nom = CASE WHEN reseaux.identifiant = excluded.identifiant THEN reseaux.nom ELSE \'\' END')
        ->execute([$c['id'], $reseau, $identifiant, $jeton, '', $expire, iso(time())]);
}

function options_reseaux(array $c): array
{
    $d = [
        'semaine' => ['actif' => false, 'reseaux' => ['facebook', 'instagram', 'linkedin'],
            'texte' => "Il reste {nombre} créneaux cette semaine chez {structure}. Réservez en ligne en 1 minute : {lien}"],
        'derniere_minute' => ['actif' => false, 'reseaux' => ['facebook', 'instagram'],
            'texte' => "Un créneau vient de se libérer {date} à {heure} chez {structure}. Premier arrivé, premier servi : {lien}"],
        'prospects' => ['actif' => true],
    ];
    $o = json_decode((string)($c['reseaux_options'] ?? '{}'), true);
    foreach ($d as $k => $v) {
        if (isset($o[$k]) && is_array($o[$k])) {
            $d[$k] = array_merge($v, $o[$k]);
        }
    }
    return $d;
}

/** Envoi en simulation : mode simulation de l'installation, ou structure de démonstration. */
function reseaux_simulation(array $c): bool
{
    return cfg('mode_envoi') !== 'reel' || (int)$c['demo'] === 1;
}

// --- WhatsApp ----------------------------------------------------------------------------------

function whatsapp_possible(array $c, ?array $patient, string $type): bool
{
    return $patient && (int)($patient['whatsapp'] ?? 0) === 1 && (string)$patient['telephone'] !== ''
        && isset(MODELES_WHATSAPP[$type]) && connexion($c, 'whatsapp') !== null;
}

/**
 * Envoie le message par WhatsApp (modèle approuvé, variables dans l'ordre du texte du client).
 * Renvoie l'identifiant du message enregistré, ou 0 si l'envoi a échoué : l'appelant passe alors au SMS.
 */
function envoyer_whatsapp(array $c, array $patient, string $type, string $texte, ?int $rdvId): int
{
    $modele = modele_whatsapp((string)(reglages($c)['textes'][$type] ?? textes_defaut($c)[$type] ?? ''));
    $vars = vars_message($texte);
    $valeurs = array_map(function ($v) use ($vars) { return (string)($vars[$v] ?? ''); }, $modele['variables']);
    $rendu = (string)preg_replace_callback('/\{\{(\d+)\}\}/', function ($m) use ($valeurs) { return $valeurs[(int)$m[1] - 1] ?? ''; }, $modele['corps']);
    $statut = 'simule';
    $ref = '';
    $erreur = '';
    if (!reseaux_simulation($c)) {
        $wa = connexion($c, 'whatsapp');
        try {
            $ref = whatsapp_modele((string)$wa['identifiant'], (string)$wa['jeton'], (string)$patient['telephone'], nom_modele_whatsapp($type), 'fr', $valeurs);
            $statut = 'envoye';
        } catch (RuntimeException $e) {
            $statut = 'echec';
            $erreur = mb_substr($e->getMessage(), 0, 300);
        }
    }
    $id = inserer('INSERT INTO messages (clinique_id, patient_id, destinataire, canal, type, contenu, statut, envoye_le, rdv_id, erreur, ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$c['id'], $patient['id'], $patient['telephone'], 'whatsapp', $type, $rendu, $statut, iso(maintenant($c)), $rdvId, $erreur, $ref]);
    if ($statut === 'echec') {
        evenement($c, 'whatsapp_echec', $patient['prenom'] . ' ' . $patient['nom'] . ' · SMS envoyé à la place');
        return 0;
    }
    evenement($c, 'message_' . $type, $patient['prenom'] . ' ' . $patient['nom'] . ' · WhatsApp');
    return $id;
}

// --- Publications ------------------------------------------------------------------------------

/** Image pour Instagram : JPEG public obligatoire, prise dans la photo de couverture, sinon le logo. */
function image_publication(array $c): string
{
    foreach (['couverture', 'logo'] as $quoi) {
        $f = image_fichier($c, $quoi);
        if ($f !== '' && is_file($f) && preg_match('/\.jpe?g$/i', $f)) {
            return image_url($c, $quoi);
        }
    }
    return '';
}

/**
 * Publie un texte sur les réseaux demandés, une seule fois par clé (ex. « semaine-2026-41 »).
 * Renvoie [réseau => [statut, détail]].
 */
function publier(array $c, array $reseaux, string $texte, string $lien, string $origine, string $cle): array
{
    $resultats = [];
    foreach (array_intersect(array_keys(RESEAUX), $reseaux) as $reseau) {
        if ($reseau === 'whatsapp') {
            continue;
        }
        $cx = connexion($c, $reseau);
        if (!$cx) {
            $resultats[$reseau] = ['ignore', 'non connecté'];
            continue;
        }
        $image = $reseau === 'instagram' ? image_publication($c) : '';
        if ($reseau === 'instagram' && $image === '') {
            $resultats[$reseau] = ['ignore', 'Instagram exige une image JPEG : ajoutez une photo de couverture'];
            continue;
        }
        $complet = $reseau === 'facebook' || $lien === '' ? $texte : trim(str_replace($lien, '', $texte)) . ' ' . $lien;
        $st = db()->prepare('INSERT OR IGNORE INTO publications (clinique_id, reseau, origine, cle, texte, lien, image, statut, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$c['id'], $reseau, $origine, $cle, $complet, $lien, $image, 'en_cours', iso(time())]);
        if ($st->rowCount() === 0) {
            $resultats[$reseau] = ['ignore', 'déjà publié'];
            continue;
        }
        $id = (int)db()->lastInsertId();
        if (reseaux_simulation($c)) {
            executer("UPDATE publications SET statut = 'simule' WHERE id = ?", [$id]);
            $resultats[$reseau] = ['simule', 'simulation'];
            continue;
        }
        try {
            $ref = $reseau === 'facebook' ? facebook_publier((string)$cx['identifiant'], (string)$cx['jeton'], $texte, $lien)
                : ($reseau === 'instagram' ? instagram_publier((string)$cx['identifiant'], (string)$cx['jeton'], $image, $complet)
                : linkedin_publier((string)$cx['identifiant'], (string)$cx['jeton'], $complet));
            executer("UPDATE publications SET statut = 'publie', ref = ? WHERE id = ?", [$ref, $id]);
            $resultats[$reseau] = ['publie', $ref];
        } catch (RuntimeException $e) {
            executer("UPDATE publications SET statut = 'echec', erreur = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 300), $id]);
            $resultats[$reseau] = ['echec', $e->getMessage()];
        }
    }
    if ($resultats) {
        evenement($c, 'publication', $origine . ' · ' . implode(', ', array_map(function ($r, $v) { return RESEAUX[$r] . ' : ' . $v[0]; }, array_keys($resultats), $resultats)));
    }
    return $resultats;
}

function remplir(string $modele, array $vars): string
{
    return trim((string)preg_replace('/\s{2,}/', ' ', strtr($modele, array_combine(array_map(function ($k) { return '{' . $k . '}'; }, array_keys($vars)), array_values($vars)))));
}

/** Tâche cron : créneaux libres de la semaine (lundi à partir de 9 h) et créneau libéré à la dernière minute. */
function publications_automatiques(array $c, ?int $t = null): array
{
    $t = $t ?? maintenant($c);
    $o = options_reseaux($c);
    $l = (new DateTimeImmutable('@' . $t))->setTimezone(tz($c));
    $journal = [];
    $type = types_rdv($c, true)[0] ?? null;
    if (!$type) {
        return [];
    }
    if ($o['semaine']['actif'] && (int)$l->format('N') === 1 && (int)$l->format('G') >= 9) {
        $cle = 'semaine-' . $l->format('o-W');
        if (!valeur('SELECT id FROM publications WHERE clinique_id = ? AND cle = ?', [$c['id'], $cle])) {
            $fin = $t + 7 * 86400;
            $n = count(array_filter(creneaux_libres($c, $type, null, 300), function ($cr) use ($fin) { return ts($cr['debut']) < $fin; }));
            if ($n > 0) {
                $res = publier($c, $o['semaine']['reseaux'], remplir($o['semaine']['texte'], ['nombre' => (string)$n, 'structure' => $c['nom'], 'lien' => lien_reservation($c)]), lien_reservation($c), 'semaine', $cle);
                $journal[] = 'Publication de la semaine (' . $n . ' créneaux) : ' . implode(', ', array_map(function ($v) { return $v[0]; }, $res));
            }
        }
    }
    if ($o['derniere_minute']['actif']) {
        // Après la liste d'attente (70 min), un créneau annulé encore libre dans les 48 h est publié, au plus un par jour.
        $deja = (int)valeur("SELECT COUNT(*) FROM publications WHERE clinique_id = ? AND origine = 'derniere_minute' AND cree_le > ?", [$c['id'], iso(time() - 86400)]);
        $candidats = $deja ? [] : toutes("SELECT * FROM rdv WHERE clinique_id = ? AND statut = 'annule' AND annule_le BETWEEN ? AND ? AND debut BETWEEN ? AND ? ORDER BY debut LIMIT 5",
            [$c['id'], iso($t - 86400), iso($t - 70 * 60), iso($t + 2 * 3600), iso($t + 48 * 3600)]);
        foreach ($candidats as $r) {
            if (valeur('SELECT id FROM publications WHERE clinique_id = ? AND cle = ?', [$c['id'], 'creneau-' . $r['id']])
                || !creneau_libre($c, (int)$r['praticien_id'], ts($r['debut']), ts($r['fin']))) {
                continue;
            }
            $texte = remplir($o['derniere_minute']['texte'], ['date' => date_longue($c, $r['debut']), 'heure' => heure($c, $r['debut']), 'structure' => $c['nom'], 'lien' => lien_reservation($c)]);
            $res = publier($c, $o['derniere_minute']['reseaux'], $texte, lien_reservation($c), 'derniere_minute', 'creneau-' . $r['id']);
            $journal[] = 'Créneau libéré publié : ' . implode(', ', array_map(function ($v) { return $v[0]; }, $res));
            break;
        }
    }
    return $journal;
}

// --- Prospects des publicités ------------------------------------------------------------------

/** Prospect reçu d'un formulaire de publicité : enregistré une fois, puis contacté avec le lien de réservation. */
function traiter_prospect(array $c, string $prospectId): ?array
{
    if (valeur('SELECT id FROM prospects WHERE ref = ?', [$prospectId])) {
        return null;
    }
    $fb = connexion($c, 'facebook');
    if (!$fb) {
        return null;
    }
    $lu = meta_prospect($prospectId, (string)$fb['jeton']);
    $p = prospect_normalise($lu['champs']);
    $tel = $p['telephone'] !== '' ? telephone($p['telephone'], $c['pays']) : '';
    $source = $lu['plateforme'] === 'ig' ? 'instagram' : 'facebook';
    $st = db()->prepare('INSERT OR IGNORE INTO prospects (clinique_id, source, ref, formulaire, prenom, nom, telephone, email, whatsapp, donnees, recu_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$c['id'], $source, $prospectId, $lu['formulaire'], $p['prenom'], $p['nom'], $tel, $p['email'], $p['whatsapp'] ? 1 : 0, (string)json_encode($lu['champs'], JSON_UNESCAPED_UNICODE), iso(maintenant($c))]);
    if ($st->rowCount() === 0) {
        return null;
    }
    $id = (int)db()->lastInsertId();
    evenement($c, 'prospect_recu', trim($p['prenom'] . ' ' . $p['nom']) . ' · ' . RESEAUX[$source]);
    if (options_reseaux($c)['prospects']['actif'] && ($tel !== '' || $p['email'] !== '')) {
        $patient = patient_trouver_ou_creer($c, $p['prenom'] ?: 'Prospect', $p['nom'] ?: RESEAUX[$source], $tel, $p['email'], false);
        if ($p['whatsapp']) {
            executer('UPDATE patients SET whatsapp = 1 WHERE id = ?', [$patient['id']]);
            $patient['whatsapp'] = 1;
        }
        $mid = envoyer_patient($c, $patient, 'prospect', rediger($c, 'prospect', ['prenom' => $p['prenom'] ?: '', 'lien' => lien_reservation($c)]));
        $canal = $mid ? (string)valeur('SELECT canal FROM messages WHERE id = ?', [$mid]) : '';
        executer("UPDATE prospects SET statut = 'contacte', canal = ?, contacte_le = ? WHERE id = ?", [$canal, iso(maintenant($c)), $id]);
    }
    return une('SELECT * FROM prospects WHERE id = ?', [$id]);
}

/** Notification Meta (webhooks/meta.php) : prospects des publicités et statuts des messages WhatsApp. */
function traiter_notification_meta(array $n): void
{
    foreach ($n['entry'] ?? [] as $entree) {
        foreach ($entree['changes'] ?? [] as $chg) {
            $v = $chg['value'] ?? [];
            if (($n['object'] ?? '') === 'page' && ($chg['field'] ?? '') === 'leadgen' && !empty($v['leadgen_id'])) {
                $c = clinique_par_reseau('facebook', (string)($v['page_id'] ?? $entree['id'] ?? ''));
                if ($c) {
                    try {
                        traiter_prospect($c, (string)$v['leadgen_id']);
                    } catch (RuntimeException $e) {
                        evenement($c, 'prospect_echec', $e->getMessage());
                    }
                }
            } elseif (($n['object'] ?? '') === 'whatsapp_business_account') {
                $c = clinique_par_reseau('whatsapp', (string)($v['metadata']['phone_number_id'] ?? ''));
                if (!$c) {
                    continue;
                }
                foreach ($v['statuses'] ?? [] as $s) {
                    $statut = ['sent' => 'envoye', 'delivered' => 'distribue', 'read' => 'lu', 'failed' => 'echec'][$s['status'] ?? ''] ?? null;
                    if ($statut) {
                        executer('UPDATE messages SET statut = ?, erreur = ? WHERE clinique_id = ? AND canal = ? AND ref = ?',
                            [$statut, (string)($s['errors'][0]['title'] ?? ''), $c['id'], 'whatsapp', (string)($s['id'] ?? '')]);
                    }
                }
                foreach ($v['messages'] ?? [] as $m) {
                    // « STOP » : plus de messages WhatsApp pour ce numéro (les SMS de service continuent).
                    if (preg_match('/^\s*(stop|arr[eê]t|arreter|unsubscribe)\s*$/iu', (string)($m['text']['body'] ?? ''))) {
                        executer("UPDATE patients SET whatsapp = 0 WHERE clinique_id = ? AND REPLACE(telephone, '+', '') = ?", [$c['id'], numero_whatsapp((string)($m['from'] ?? ''))]);
                        evenement($c, 'whatsapp_stop', '+' . numero_whatsapp((string)($m['from'] ?? '')));
                    }
                }
            }
        }
    }
}

function clinique_par_reseau(string $reseau, string $identifiant): ?array
{
    if ($identifiant === '') {
        return null;
    }
    $id = valeur('SELECT clinique_id FROM reseaux WHERE reseau = ? AND identifiant = ?', [$reseau, $identifiant]);
    return $id ? clinique((int)$id) : null;
}
