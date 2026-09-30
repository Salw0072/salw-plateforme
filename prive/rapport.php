<?php
/**
 * Plateforme SALW : indicateurs (tableau de bord) et rapport mensuel envoyé au client par e-mail.
 *
 * Le rapport part le 1er de chaque mois (tâche cron), pour le mois écoulé, à l'adresse choisie
 * dans « Rapport mensuel ». En mode simulation, il est enregistré mais ne part pas.
 */

declare(strict_types=1);

const LIBELLES_MESSAGES = [
    'confirmation' => 'Confirmation', 'rappel_j2' => 'Rappel 48 h', 'rappel_j1' => 'Rappel 24 h', 'rappel_h3' => 'Rappel 3 h', 'offre_attente' => "Offre liste d'attente",
    'appel_manque' => 'Appel manqué', 'avis' => 'Demande d\'avis', 'absence' => 'Après absence', 'reactivation' => 'Réactivation', 'relance_devis' => 'Relance de devis',
];

const MOIS_FR = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** Minutes de secrétariat comptées par message automatique, pour l'estimation du temps évité. */
const MINUTES_PAR_MESSAGE = 2;

/** Indicateurs des N derniers jours, comparés aux N jours précédents (tableau de bord). */
function indicateurs(array $c, int $jours): array
{
    $now = maintenant($c);
    return indicateurs_entre($c, $now - $jours * 86400, $now, $now - 2 * $jours * 86400);
}

/** Indicateurs entre deux instants ; le taux d'absence est comparé à la période [$avant, $debut]. */
function indicateurs_entre(array $c, int $debut, int $fin, int $avant): array
{
    $d = iso($debut);
    $n = iso($fin);
    $d2 = iso($avant);
    $id = $c['id'];
    $honores = (int)valeur("SELECT COUNT(*) FROM rdv WHERE clinique_id = ? AND statut = 'honore' AND fin BETWEEN ? AND ?", [$id, $d, $n]);
    $absents = (int)valeur("SELECT COUNT(*) FROM rdv WHERE clinique_id = ? AND statut = 'absent' AND fin BETWEEN ? AND ?", [$id, $d, $n]);
    // Taux d'absence de la période précédente, pour mesurer l'effet des rappels.
    $h2 = (int)valeur("SELECT COUNT(*) FROM rdv WHERE clinique_id = ? AND statut = 'honore' AND fin BETWEEN ? AND ?", [$id, $d2, $d]);
    $a2 = (int)valeur("SELECT COUNT(*) FROM rdv WHERE clinique_id = ? AND statut = 'absent' AND fin BETWEEN ? AND ?", [$id, $d2, $d]);
    $parType = [];
    foreach (toutes('SELECT type, COUNT(*) n FROM messages WHERE clinique_id = ? AND envoye_le BETWEEN ? AND ? GROUP BY type ORDER BY n DESC', [$id, $d, $n]) as $l) {
        $parType[LIBELLES_MESSAGES[$l['type']] ?? $l['type']] = (int)$l['n'];
    }
    // Réservations en ligne faites le soir (avant 8 h, après 19 h) ou le week-end : celles qu'un secrétariat n'aurait pas prises.
    $horsHoraires = 0;
    foreach (toutes("SELECT cree_le FROM rdv WHERE clinique_id = ? AND cree_le BETWEEN ? AND ? AND source IN ('en_ligne', 'liste_attente')", [$id, $d, $n]) as $r) {
        $l = (new DateTimeImmutable($r['cree_le']))->setTimezone(tz($c));
        $horsHoraires += ((int)$l->format('N') >= 6 || (int)$l->format('G') < 8 || (int)$l->format('G') >= 19) ? 1 : 0;
    }
    return [
        'rdv_crees'   => (int)valeur('SELECT COUNT(*) FROM rdv WHERE clinique_id = ? AND cree_le BETWEEN ? AND ?', [$id, $d, $n]),
        'en_ligne'    => (int)valeur("SELECT COUNT(*) FROM rdv WHERE clinique_id = ? AND cree_le BETWEEN ? AND ? AND source IN ('en_ligne', 'liste_attente')", [$id, $d, $n]),
        'hors_horaires' => $horsHoraires,
        'absence'     => ($honores + $absents) ? $absents / ($honores + $absents) : null,
        'absence_avant' => ($h2 + $a2) ? $a2 / ($h2 + $a2) : null,
        'honores'     => $honores, 'absents' => $absents,
        'recuperes'   => (int)valeur("SELECT COUNT(*) FROM evenements WHERE clinique_id = ? AND type = 'creneau_recupere' AND t BETWEEN ? AND ?", [$id, $d, $n]),
        'appels'      => (int)valeur('SELECT COUNT(*) FROM appels WHERE clinique_id = ? AND recu_le BETWEEN ? AND ?', [$id, $d, $n]),
        'rattrapes'   => (int)valeur("SELECT COUNT(*) FROM appels WHERE clinique_id = ? AND statut = 'rdv_pris' AND recu_le BETWEEN ? AND ?", [$id, $d, $n]),
        'avis'        => (int)valeur("SELECT COUNT(*) FROM messages WHERE clinique_id = ? AND type = 'avis' AND envoye_le BETWEEN ? AND ?", [$id, $d, $n])
                         + (int)valeur("SELECT COUNT(*) FROM rdv WHERE clinique_id = ? AND avis_envoye_le BETWEEN ? AND ? AND id NOT IN (SELECT COALESCE(rdv_id, 0) FROM messages WHERE type = 'avis')", [$id, $d, $n]),
        'avis_clics'  => (int)valeur("SELECT COUNT(*) FROM evenements WHERE clinique_id = ? AND type = 'avis_clic' AND t BETWEEN ? AND ?", [$id, $d, $n]),
        'messages'    => (int)valeur('SELECT COUNT(*) FROM messages WHERE clinique_id = ? AND envoye_le BETWEEN ? AND ?', [$id, $d, $n]),
        'par_type'    => $parType,
        'valeur'      => (float)valeur("SELECT COALESCE(SUM(valeur), 0) FROM evenements WHERE clinique_id = ? AND type IN ('creneau_recupere', 'appel_rattrape', 'devis_relance_accepte') AND t BETWEEN ? AND ?", [$id, $d, $n]),
        'devis_acceptes' => (int)valeur("SELECT COUNT(*) FROM evenements WHERE clinique_id = ? AND type = 'devis_relance_accepte' AND t BETWEEN ? AND ?", [$id, $d, $n]),
    ];
}

function pct(?float $x): string
{
    return $x === null ? '–' : str_replace('.', ',', (string)round($x * 100, 1)) . ' %';
}

// --- Rapport mensuel ------------------------------------------------------------------------

/** Mois écoulé à l'heure du client, au format AAAA-MM. */
function mois_precedent(array $c, ?int $t = null): string
{
    return (new DateTimeImmutable('@' . ($t ?? maintenant($c))))->setTimezone(tz($c))->modify('first day of last month')->format('Y-m');
}

function mois_libelle(string $mois): string
{
    [$a, $m] = array_map('intval', explode('-', $mois));
    return MOIS_FR[$m] . ' ' . $a;
}

/** Chiffres d'un mois (arrêtés à maintenant si le mois est en cours) et ceux du mois d'avant. */
function rapport_donnees(array $c, string $mois): array
{
    $debut = new DateTimeImmutable($mois . '-01 00:00:00', tz($c));
    $finMois = $debut->modify('first day of next month')->getTimestamp();
    $avant = $debut->modify('first day of last month')->getTimestamp();
    $fin = min($finMois, maintenant($c));
    return [
        'mois' => $mois,
        'en_cours' => $fin < $finMois,
        'k' => indicateurs_entre($c, $debut->getTimestamp(), $fin, $avant),
        'avant' => indicateurs_entre($c, $avant, $debut->getTimestamp(), $avant - ($debut->getTimestamp() - $avant)),
    ];
}

function rapport_destinataire(array $c): string
{
    return (string)($c['rapport_email'] ?: $c['email']);
}

function rapport_objet(array $c, string $mois): string
{
    return 'Votre bilan de ' . mois_libelle($mois) . ' · ' . $c['nom'];
}

/** Lignes du rapport : [libellé, valeur, détail]. Les lignes sans objet pour ce client sont omises. */
function rapport_lignes(array $c, array $k, array $av): array
{
    $l = [
        ['Rendez-vous pris', (string)$k['rdv_crees'], $k['rdv_crees'] ? round($k['en_ligne'] / $k['rdv_crees'] * 100) . ' % en ligne, sans passer par le téléphone' : ''],
        ['Pris le soir ou le week-end', (string)$k['hors_horaires'], 'quand personne ne pouvait décrocher'],
        ["Taux d'absence", pct($k['absence']), $k['absence'] !== null && $k['absence_avant'] !== null ? 'contre ' . pct($k['absence_avant']) . ' le mois précédent' : 'rendez-vous non honorés sans prévenir'],
    ];
    if ($k['appels'] > 0 || $av['appels'] > 0) {
        $l[] = ['Appels manqués rattrapés', $k['rattrapes'] . ' sur ' . $k['appels'], 'transformés en rendez-vous par SMS'];
    }
    $l[] = ['Créneaux libérés repris', (string)$k['recuperes'], "par la liste d'attente, après une annulation"];
    if ($c['avis_url'] !== '' || $k['avis'] > 0) {
        $l[] = ['Avis Google demandés', (string)$k['avis'], $k['avis_clics'] . ' personne(s) ont ouvert la page d\'avis'];
    }
    if (reglages($c)['relance_devis']['actif'] || $k['devis_acceptes'] > 0) {
        $l[] = ['Devis signés après relance', (string)$k['devis_acceptes'], 'relancés automatiquement à J+3 et J+7'];
    }
    return $l;
}

/** Corps HTML du rapport, compatible avec les messageries (tableaux, styles en ligne, 600 px). */
function rapport_html(array $c, array $r): string
{
    $k = $r['k'];
    $av = $r['avant'];
    $a = couleur_accent($c);
    $logo = logo_url($c);
    $heures = round($k['messages'] * MINUTES_PAR_MESSAGE / 60, 1);
    $f = 'font-family:Arial,Helvetica,sans-serif;';
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F4F7;padding:24px 0"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;border-top:5px solid ' . $a . '">'
        . '<tr><td style="padding:24px 28px 8px;' . $f . '">'
        . ($logo !== '' ? '<img src="' . h($logo) . '" alt="' . h($c['nom']) . '" style="max-height:48px;max-width:180px;display:block;margin-bottom:14px">' : '<div style="' . $f . 'font-size:18px;font-weight:bold;color:#171B34;margin-bottom:10px">' . h($c['nom']) . '</div>')
        . '<div style="font-size:13px;color:#5F6070;text-transform:uppercase;letter-spacing:1px">Bilan ' . ($r['en_cours'] ? 'provisoire ' : '') . 'de ' . h(mois_libelle($r['mois'])) . '</div>'
        . '<div style="font-size:15px;color:#1E1F2B;margin-top:14px">Chiffre d\'affaires récupéré par vos automatisations</div>'
        . '<div style="font-size:40px;font-weight:bold;color:' . $a . ';line-height:1.2">' . h(euros($k['valeur'])) . '</div>'
        . '<div style="font-size:13px;color:#5F6070">Mois précédent : ' . h(euros($av['valeur'])) . '</div></td></tr>'
        . '<tr><td style="padding:12px 28px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
    foreach (rapport_lignes($c, $k, $av) as [$lib, $val, $det]) {
        $h .= '<tr><td style="' . $f . 'padding:11px 0;border-bottom:1px solid #E6E3EA"><div style="font-size:15px;color:#1E1F2B">' . h($lib) . '</div>'
            . ($det !== '' ? '<div style="font-size:12.5px;color:#5F6070">' . h($det) . '</div>' : '') . '</td>'
            . '<td align="right" style="' . $f . 'padding:11px 0;border-bottom:1px solid #E6E3EA;font-size:20px;font-weight:bold;color:#171B34;white-space:nowrap">' . h($val) . '</td></tr>';
    }
    $h .= '</table></td></tr>'
        . '<tr><td style="padding:8px 28px 4px;' . $f . 'font-size:14.5px;color:#1E1F2B">' . $k['messages'] . ($k['messages'] > 1 ? ' messages envoyés' : ' message envoyé') . ' automatiquement'
        . ($heures >= 1 ? ', soit environ <b>' . h(str_replace('.', ',', (string)$heures)) . ' h</b> de téléphone et de secrétariat évitées' : '') . '.</td></tr>'
        . '<tr><td style="padding:18px 28px 24px"><a href="' . h(url_base() . '/app/') . '" style="' . $f . 'display:inline-block;background:' . $a . ';color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 22px;border-radius:999px">Voir le détail dans mon espace</a></td></tr>'
        . '<tr><td style="padding:16px 28px 22px;border-top:1px solid #E6E3EA;' . $f . 'font-size:12px;color:#5F6070;line-height:1.5">'
        . 'Méthode : chaque créneau repris, chaque appel rattrapé et chaque devis signé après relance est compté à la valeur moyenne d\'un rendez-vous (' . h(euros((float)$c['valeur_consultation'])) . '). '
        . 'Temps évité : ' . MINUTES_PAR_MESSAGE . ' minutes par message. Chiffres ' . ($r['en_cours'] ? 'arrêtés à aujourd\'hui' : 'du 1er au dernier jour du mois') . '.<br>'
        . 'Rapport préparé par SALW CONSULTING, qui opère votre plateforme de rendez-vous. Une question : répondez simplement à cet e-mail.</td></tr>'
        . '</table></td></tr></table>';
    return $h;
}

function rapport_texte(array $c, array $r): string
{
    $k = $r['k'];
    $t = rapport_objet($c, $r['mois']) . "\n\nChiffre d'affaires récupéré : " . euros($k['valeur']) . ' (mois précédent : ' . euros($r['avant']['valeur']) . ")\n\n";
    foreach (rapport_lignes($c, $k, $r['avant']) as [$lib, $val, $det]) {
        $t .= '- ' . $lib . ' : ' . $val . ($det !== '' ? ' (' . $det . ')' : '') . "\n";
    }
    return $t . "\n" . $k['messages'] . ($k['messages'] > 1 ? ' messages envoyés' : ' message envoyé') . " automatiquement.\n\nDétail : " . url_base() . "/app/\n\nSALW CONSULTING";
}

/**
 * Envoie (ou, en simulation, enregistre) le rapport d'un mois. Renvoie [ok, message].
 * Un mois n'est envoyé qu'une fois automatiquement ; un envoi manuel remplace l'enregistrement.
 */
function envoyer_rapport(array $c, string $mois, string $dest = ''): array
{
    $dest = $dest !== '' ? $dest : rapport_destinataire($c);
    if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Aucune adresse e-mail valide pour le rapport.'];
    }
    $r = rapport_donnees($c, $mois);
    $statut = 'simule';
    $erreur = '';
    if (cfg('mode_envoi') === 'reel' && !(int)$c['demo']) {
        $ok = envoyer_email_html($dest, rapport_objet($c, $mois), rapport_html($c, $r), rapport_texte($c, $r));
        $statut = $ok ? 'envoye' : 'echec';
        $erreur = $ok ? '' : 'mail() a échoué';
    }
    executer('INSERT INTO rapports (clinique_id, mois, destinataire, statut, envoye_le, erreur) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(clinique_id, mois) DO UPDATE SET destinataire = excluded.destinataire, statut = excluded.statut, envoye_le = excluded.envoye_le, erreur = excluded.erreur',
        [$c['id'], $mois, $dest, $statut, iso(time()), $erreur]);
    evenement($c, 'rapport_' . $statut, mois_libelle($mois) . ' · ' . $dest);
    return [$statut !== 'echec', ['simule' => 'Rapport enregistré (mode simulation : rien n\'est parti).', 'envoye' => 'Rapport envoyé à ' . $dest . '.', 'echec' => 'Échec de l\'envoi.'][$statut]];
}

/** E-mail HTML avec sa version texte (multipart/alternative). */
function envoyer_email_html(string $a, string $objet, string $html, string $texte): bool
{
    $exp = (string)cfg('email_expediteur');
    $b = 'salw' . bin2hex(random_bytes(8));
    $entetes = "From: =?UTF-8?B?" . base64_encode('SALW CONSULTING') . "?= <{$exp}>\r\nReply-To: {$exp}\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$b}\"";
    $corps = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texte))
        . "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode('<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head><body style="margin:0">' . $html . '</body></html>'))
        . "--{$b}--";
    return @mail($a, '=?UTF-8?B?' . base64_encode($objet) . '?=', $corps, $entetes);
}

/** Tâche cron : le 1er du mois à partir de 8 h (heure du client), rapport du mois écoulé, une seule fois. */
function rapports_mensuels(array $c, ?int $t = null): ?string
{
    $t = $t ?? maintenant($c);
    $l = (new DateTimeImmutable('@' . $t))->setTimezone(tz($c));
    if ((int)$l->format('j') !== 1 || (int)$l->format('G') < 8 || !(int)$c['rapport_actif'] || rapport_destinataire($c) === '') {
        return null;
    }
    $mois = mois_precedent($c, $t);
    if (valeur('SELECT id FROM rapports WHERE clinique_id = ? AND mois = ?', [$c['id'], $mois])) {
        return null;
    }
    // Sans adresse du site, les liens de l'e-mail seraient faux (la tâche cron ne connaît pas le domaine).
    if (PHP_SAPI === 'cli' && (string)cfg('url_site') === '') {
        return 'Rapport ' . $mois . ' non envoyé : renseignez url_site dans prive/config.php';
    }
    // Un mois sans aucune activité (client pas encore lancé) ne donne pas lieu à un rapport.
    $k = rapport_donnees($c, $mois)['k'];
    if ($k['rdv_crees'] === 0 && $k['messages'] === 0 && $k['appels'] === 0) {
        executer("INSERT INTO rapports (clinique_id, mois, destinataire, statut, envoye_le) VALUES (?, ?, ?, 'sans_activite', ?)", [$c['id'], $mois, rapport_destinataire($c), iso(time())]);
        return 'Rapport ' . $mois . ' : aucune activité, pas d\'envoi';
    }
    return 'Rapport ' . $mois . ' : ' . envoyer_rapport($c, $mois)[1];
}
