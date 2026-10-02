<?php
/**
 * Plateforme SALW Santé : créneaux, rendez-vous, liste d'attente.
 *
 * Toutes les écritures sensibles (réserver, accepter une offre) passent par une
 * transaction « BEGIN IMMEDIATE » : deux patients ne peuvent jamais obtenir le même créneau.
 */

declare(strict_types=1);

function praticiens(array $c, bool $actifs = true): array
{
    return toutes('SELECT * FROM praticiens WHERE clinique_id = ?' . ($actifs ? ' AND actif = 1' : '') . ' ORDER BY nom', [$c['id']]);
}

function types_rdv(array $c, bool $enLigneSeulement = false): array
{
    return toutes('SELECT * FROM types_rdv WHERE clinique_id = ? AND actif = 1' . ($enLigneSeulement ? ' AND en_ligne = 1' : '') . ' ORDER BY libelle', [$c['id']]);
}

/** Le créneau [debut, fin[ du praticien est-il libre ? (rendez-vous, offres en cours, fermetures) */
function creneau_libre(array $c, int $praticienId, int $debut, int $fin, ?int $offreIgnoree = null): bool
{
    $conflit = valeur("SELECT COUNT(*) FROM rdv WHERE clinique_id = ? AND praticien_id = ? AND statut != 'annule' AND debut < ? AND fin > ?",
        [$c['id'], $praticienId, iso($fin), iso($debut)]);
    if ((int)$conflit > 0) {
        return false;
    }
    // Un créneau proposé à la liste d'attente est réservé à ces patients le temps de l'offre.
    $offre = valeur("SELECT COUNT(*) FROM offres WHERE clinique_id = ? AND praticien_id = ? AND statut = 'envoyee' AND expire_le > ? AND debut < ? AND fin > ?" . ($offreIgnoree ? ' AND debut != (SELECT debut FROM offres WHERE id = ' . (int)$offreIgnoree . ')' : ''),
        [$c['id'], $praticienId, iso(maintenant($c)), iso($fin), iso($debut)]);
    if ((int)$offre > 0) {
        return false;
    }
    $jour = (new DateTimeImmutable('@' . $debut))->setTimezone(tz($c))->format('Y-m-d');
    return (int)valeur('SELECT COUNT(*) FROM fermetures WHERE clinique_id = ? AND jour = ? AND (praticien_id IS NULL OR praticien_id = ?)', [$c['id'], $jour, $praticienId]) === 0;
}

/**
 * Créneaux libres pour un type de rendez-vous. $praticienId null = tous les praticiens.
 * Renvoie une liste triée de ['debut' => iso, 'fin' => iso, 'praticien_id' => int].
 */
function creneaux_libres(array $c, array $type, ?int $praticienId, int $limite = 300): array
{
    $r = reglages($c)['reservation'];
    $now = maintenant($c);
    $premier = $now + (int)$r['delai_min_h'] * 3600;
    $duree = max(5, (int)$type['duree']) * 60;
    $tz = tz($c);
    $sortie = [];
    $prats = $praticienId ? array_filter(praticiens($c), function ($p) use ($praticienId) { return (int)$p['id'] === $praticienId; }) : praticiens($c);
    $jour0 = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
    for ($i = 0; $i <= (int)$r['horizon_jours']; $i++) {
        $jour = $jour0->modify("+{$i} day");
        foreach ($prats as $p) {
            $horaires = json_decode((string)$p['horaires'], true) ?: [];
            foreach ((array)($horaires[$jour->format('N')] ?? []) as $plage) {
                $a = DateTimeImmutable::createFromFormat('Y-m-d H:i', $jour->format('Y-m-d') . ' ' . $plage[0], $tz);
                $b = DateTimeImmutable::createFromFormat('Y-m-d H:i', $jour->format('Y-m-d') . ' ' . $plage[1], $tz);
                if (!$a || !$b) {
                    continue;
                }
                for ($t = $a->getTimestamp(); $t + $duree <= $b->getTimestamp(); $t += $duree) {
                    if ($t >= $premier && creneau_libre($c, (int)$p['id'], $t, $t + $duree)) {
                        $sortie[] = ['debut' => iso($t), 'fin' => iso($t + $duree), 'praticien_id' => (int)$p['id']];
                    }
                }
            }
        }
        if (count($sortie) >= $limite) {
            break;
        }
    }
    usort($sortie, function ($x, $y) { return strcmp($x['debut'], $y['debut']) ?: $x['praticien_id'] <=> $y['praticien_id']; });
    return array_slice($sortie, 0, $limite);
}

/** Retrouve un patient par téléphone (ou e-mail), sinon le crée. */
function patient_trouver_ou_creer(array $c, string $prenom, string $nom, string $tel, string $email, bool $relance): array
{
    $p = null;
    if ($tel !== '') {
        $p = une('SELECT * FROM patients WHERE clinique_id = ? AND telephone = ?', [$c['id'], $tel]);
    }
    if (!$p && $email !== '') {
        $p = une('SELECT * FROM patients WHERE clinique_id = ? AND email = ?', [$c['id'], $email]);
    }
    if ($p) {
        executer('UPDATE patients SET prenom = ?, nom = ?, telephone = COALESCE(NULLIF(?, \'\'), telephone), email = COALESCE(NULLIF(?, \'\'), email), consentement_relance = MAX(consentement_relance, ?) WHERE id = ?',
            [$prenom, $nom, $tel, $email, $relance ? 1 : 0, $p['id']]);
        return une('SELECT * FROM patients WHERE id = ?', [$p['id']]);
    }
    $id = inserer('INSERT INTO patients (clinique_id, prenom, nom, telephone, email, consentement_relance, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$c['id'], $prenom, $nom, $tel, $email, $relance ? 1 : 0, iso(maintenant($c))]);
    return une('SELECT * FROM patients WHERE id = ?', [$id]);
}

function lien(string $page, string $jeton): string
{
    return url_base() . '/p/' . $page . '.php?t=' . $jeton;
}

/** Lien public de réservation : à l'adresse web du client s'il en a une, sinon à celle de la plateforme. */
function lien_reservation(array $c): string
{
    return ((string)($c['domaine'] ?? '') !== '' ? 'https://' . $c['domaine'] : url_base()) . '/p/rdv.php?c=' . rawurlencode($c['slug']);
}

function vars_rdv(array $c, array $rdv, array $patient): array
{
    $p = une('SELECT nom, titre FROM praticiens WHERE id = ?', [$rdv['praticien_id']]);
    return [
        'prenom' => $patient['prenom'], 'date' => date_longue($c, $rdv['debut']), 'heure' => heure($c, $rdv['debut']),
        'praticien' => reglages($c)['mentionner_praticien'] && $p ? trim($p['titre'] . ' ' . $p['nom']) : '',
        'lien' => lien('g', $rdv['jeton']),
    ];
}

/**
 * Crée un rendez-vous si le créneau est libre. Renvoie [rdv, null] ou [null, message d'erreur].
 * $offreId : rendez-vous issu d'une offre de liste d'attente (le créneau lui est réservé).
 */
function creer_rdv(array $c, array $patient, int $praticienId, ?int $typeId, int $debut, int $duree, string $source, ?int $offreId = null, string $infos = ''): array
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if (!creneau_libre($c, $praticienId, $debut, $debut + $duree * 60, $offreId)) {
            $pdo->exec('ROLLBACK');
            return [null, "Ce créneau vient d'être pris. Choisissez-en un autre."];
        }
        $id = inserer('INSERT INTO rdv (clinique_id, praticien_id, patient_id, type_id, debut, fin, statut, source, jeton, cree_le, infos) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$c['id'], $praticienId, $patient['id'], $typeId, iso($debut), iso($debut + $duree * 60), 'confirme', $source, jeton(), iso(maintenant($c)), $infos]);
        $pdo->exec('COMMIT');
    } catch (Exception $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    $rdv = une('SELECT * FROM rdv WHERE id = ?', [$id]);
    evenement($c, 'rdv_cree', $source);

    // Appel manqué récent de ce numéro : le SMS a porté ses fruits.
    if ($patient['telephone'] !== '') {
        $appel = une("SELECT * FROM appels WHERE clinique_id = ? AND numero = ? AND statut = 'manque' AND sms_envoye_le != '' AND recu_le > ? ORDER BY recu_le DESC",
            [$c['id'], $patient['telephone'], iso(maintenant($c) - 72 * 3600)]);
        if ($appel) {
            executer("UPDATE appels SET statut = 'rdv_pris', rdv_id = ? WHERE id = ?", [$id, $appel['id']]);
            evenement($c, 'appel_rattrape', $patient['prenom'] . ' ' . $patient['nom'], (float)$c['valeur_consultation']);
        }
    }
    // Prospect d'une publicité Facebook ou Instagram qui réserve : converti.
    $prospect = une("SELECT * FROM prospects WHERE clinique_id = ? AND statut != 'rdv' AND ((telephone != '' AND telephone = ?) OR (email != '' AND email = ?)) ORDER BY id DESC",
        [$c['id'], (string)$patient['telephone'], (string)$patient['email']]);
    if ($prospect) {
        executer("UPDATE prospects SET statut = 'rdv', rdv_id = ? WHERE id = ?", [$id, $prospect['id']]);
        evenement($c, 'prospect_converti', $patient['prenom'] . ' ' . $patient['nom'] . ' · ' . ucfirst($prospect['source']), (float)$c['valeur_consultation']);
    }
    if (reglages($c)['confirmation']['actif']) {
        envoyer_patient($c, $patient, 'confirmation', rediger($c, 'confirmation', vars_rdv($c, $rdv, $patient)), $id);
    }
    return [$rdv, null];
}

/** Annule un rendez-vous et propose aussitôt le créneau à la liste d'attente. */
function annuler_rdv(array $c, array $rdv, string $par): void
{
    if ($rdv['statut'] === 'annule') {
        return;
    }
    executer("UPDATE rdv SET statut = 'annule', annule_le = ?, annule_par = ? WHERE id = ?", [iso(maintenant($c)), $par, $rdv['id']]);
    evenement($c, 'rdv_annule', $par);
    proposer_liste_attente($c, $rdv);
}

/** Propose un créneau libéré aux premiers patients de la liste d'attente compatibles. Renvoie le nombre d'offres. */
function proposer_liste_attente(array $c, array $rdv): int
{
    $r = reglages($c)['liste_attente'];
    $now = maintenant($c);
    if (!$r['actif'] || ts($rdv['debut']) < $now + (int)$r['marge_min'] * 60) {
        return 0;
    }
    $candidats = toutes("SELECT a.*, p.prenom, p.nom, p.telephone, p.email, p.stop_sms, p.id AS pid FROM attente a JOIN patients p ON p.id = a.patient_id
        WHERE a.clinique_id = ? AND a.statut = 'active' AND a.patient_id != ? AND (a.praticien_id IS NULL OR a.praticien_id = ?) AND (a.type_id IS NULL OR a.type_id = ? OR ? IS NULL)
        ORDER BY a.cree_le LIMIT ?",
        [$c['id'], $rdv['patient_id'], $rdv['praticien_id'], $rdv['type_id'], $rdv['type_id'], (int)$r['simultanes']]);
    if (!$candidats) {
        evenement($c, 'creneau_libere', 'aucun patient en liste d\'attente compatible');
        return 0;
    }
    $expire = min($now + (int)$r['validite_min'] * 60, ts($rdv['debut']) - 30 * 60);
    foreach ($candidats as $a) {
        $jeton = jeton();
        inserer('INSERT INTO offres (clinique_id, attente_id, praticien_id, type_id, debut, fin, rdv_libere_id, jeton, statut, expire_le, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$c['id'], $a['id'], $rdv['praticien_id'], $rdv['type_id'], $rdv['debut'], $rdv['fin'], $rdv['id'], $jeton, 'envoyee', iso($expire), iso($now)]);
        $patient = une('SELECT * FROM patients WHERE id = ?', [$a['patient_id']]);
        envoyer_patient($c, $patient, 'offre_attente', rediger($c, 'offre_attente', [
            'prenom' => $patient['prenom'], 'date' => date_longue($c, $rdv['debut']), 'heure' => heure($c, $rdv['debut']), 'lien' => lien('o', $jeton),
        ]));
    }
    evenement($c, 'creneau_libere', count($candidats) . ' patient(s) prévenu(s)');
    return count($candidats);
}

/** Un patient accepte une offre. Renvoie [rdv, null] ou [null, raison]. */
function accepter_offre(array $c, array $offre): array
{
    $now = maintenant($c);
    if ($offre['statut'] === 'acceptee') {
        return [null, 'Vous avez déjà accepté ce créneau.'];
    }
    if ($offre['statut'] !== 'envoyee') {
        return [null, "Ce créneau a déjà été attribué à un autre patient. Vous restez sur la liste d'attente."];
    }
    if (ts($offre['expire_le']) < $now) {
        executer("UPDATE offres SET statut = 'expiree' WHERE id = ?", [$offre['id']]);
        return [null, "Cette proposition a expiré. Vous restez sur la liste d'attente."];
    }
    $attente = une('SELECT * FROM attente WHERE id = ?', [$offre['attente_id']]);
    $patient = une('SELECT * FROM patients WHERE id = ?', [$attente['patient_id']]);
    $duree = (int)((ts($offre['fin']) - ts($offre['debut'])) / 60);
    [$rdv, $err] = creer_rdv($c, $patient, (int)$offre['praticien_id'], $offre['type_id'] !== null ? (int)$offre['type_id'] : null, ts($offre['debut']), $duree, 'liste_attente', (int)$offre['id']);
    if (!$rdv) {
        executer("UPDATE offres SET statut = 'caduque' WHERE id = ?", [$offre['id']]);
        return [null, "Ce créneau a déjà été attribué à un autre patient. Vous restez sur la liste d'attente."];
    }
    executer("UPDATE offres SET statut = 'acceptee' WHERE id = ?", [$offre['id']]);
    executer("UPDATE offres SET statut = 'caduque' WHERE clinique_id = ? AND debut = ? AND praticien_id = ? AND id != ? AND statut = 'envoyee'",
        [$c['id'], $offre['debut'], $offre['praticien_id'], $offre['id']]);
    executer("UPDATE attente SET statut = 'servie' WHERE id = ?", [$attente['id']]);
    evenement($c, 'creneau_recupere', $patient['prenom'] . ' ' . $patient['nom'], (float)$c['valeur_consultation']);
    return [$rdv, null];
}

/** Crée un devis à relancer. */
function creer_devis(array $c, array $patient, string $libelle, float $montant): int
{
    $id = inserer('INSERT INTO devis (clinique_id, patient_id, libelle, montant, envoye_le, jeton, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$c['id'], $patient['id'], mb_substr($libelle, 0, 120), max(0, $montant), iso(maintenant($c)), jeton(), iso(maintenant($c))]);
    evenement($c, 'devis_envoye', $libelle, $montant);
    return $id;
}

/** Réponse du client à un devis (lien de relance ou secrétariat). */
function repondre_devis(array $c, array $dv, bool $accepte): void
{
    if (!in_array($dv['statut'], ['envoye', 'sans_suite'], true)) {
        return;
    }
    executer('UPDATE devis SET statut = ?, repondu_le = ? WHERE id = ?', [$accepte ? 'accepte' : 'refuse', iso(maintenant($c)), $dv['id']]);
    // Un devis accepté après au moins une relance compte dans le chiffre d'affaires récupéré.
    $relance = trim((string)$dv['relances']) !== '';
    evenement($c, $accepte ? ($relance ? 'devis_relance_accepte' : 'devis_accepte') : 'devis_refuse', $dv['libelle'], $accepte && $relance ? (float)$dv['montant'] : 0);
}

/** Réponses aux questions de réservation du métier, validées. Renvoie [json, erreur]. */
function lire_questions(array $c, array $post): array
{
    $sortie = [];
    foreach (metier($c)['questions'] as $q) {
        $v = trim(str_replace(["
", "
"], ' ', (string)($post['q_' . $q['cle']] ?? '')));
        if ($q['type'] === 'choix' && $v !== '' && !in_array($v, $q['options'], true)) {
            $v = '';
        }
        if (!empty($q['requis']) && $v === '') {
            return ['', $q['libelle'] . ' : champ nécessaire.'];
        }
        if ($v !== '') {
            $sortie[$q['libelle']] = mb_substr($v, 0, 160);
        }
    }
    return [$sortie ? json_encode($sortie, JSON_UNESCAPED_UNICODE) : '', ''];
}

function marquer_statut(array $c, array $rdv, string $statut): void
{
    if (!in_array($statut, ['confirme', 'honore', 'absent'], true)) {
        return;
    }
    executer('UPDATE rdv SET statut = ?, honore_auto = 0 WHERE id = ?', [$statut, $rdv['id']]);
    evenement($c, 'rdv_' . $statut, '');
}
