<?php
/**
 * Plateforme SALW Santé : moteur des automatisations.
 *
 * executer_automatisations() est lancé toutes les 5 minutes par cron.php (et à la demande
 * dans la démonstration). Chaque règle marque ce qu'elle a fait (date d'envoi, liste des
 * rappels) : relancer le moteur ne renvoie jamais deux fois le même message.
 *
 * Règles, dans l'ordre :
 *  1. Appel manqué     SMS avec le lien de réservation, quelques minutes après l'appel
 *  2. Rappels          48 h avant, 24 h avant (si non confirmé), 3 h avant (option)
 *  3. Liste d'attente  offres expirées : le créneau passe aux patients suivants
 *  4. Honoré           rendez-vous passé sans absence signalée = honoré (option)
 *  5. Avis Google      demandé quelques heures après la visite, une fois par an au plus
 *  6. Absence          message pour reprendre rendez-vous
 *  7. Réactivation     patients consentants, non revus depuis N mois
 *  8. Relance de devis  devis sans réponse : relance à J+3 puis J+7 (artisans, garages, avocats, coachs)
 */

declare(strict_types=1);

function executer_automatisations(array $c): array
{
    $bilan = [];
    $r = reglages($c);
    $now = maintenant($c);

    // 1. Appels manqués : un SMS par numéro et par 24 h, après le délai de rappel.
    if ($r['appel_manque']['actif']) {
        $appels = toutes("SELECT * FROM appels WHERE clinique_id = ? AND statut = 'manque' AND sms_envoye_le = '' AND recu_le <= ?",
            [$c['id'], iso($now - (int)$r['appel_manque']['delai_min'] * 60)]);
        foreach ($appels as $a) {
            $recent = valeur("SELECT COUNT(*) FROM messages WHERE clinique_id = ? AND destinataire = ? AND type = 'appel_manque' AND envoye_le > ?", [$c['id'], $a['numero'], iso($now - 86400)]);
            $patient = une('SELECT * FROM patients WHERE clinique_id = ? AND telephone = ?', [$c['id'], $a['numero']]);
            if ((int)$recent === 0) {
                envoyer($c, $patient, $a['numero'], 'appel_manque', rediger($c, 'appel_manque', ['prenom' => $patient['prenom'] ?? '', 'lien' => lien_reservation($c)]));
                $bilan[] = 'SMS après appel manqué : ' . telephone_lisible($a['numero']);
            }
            executer('UPDATE appels SET sms_envoye_le = ? WHERE id = ?', [iso($now), $a['id']]);
        }
    }

    // 2. Rappels avant le rendez-vous.
    $aVenir = toutes("SELECT r.*, p.prenom, p.nom, p.telephone, p.email, p.stop_sms FROM rdv r JOIN patients p ON p.id = r.patient_id
        WHERE r.clinique_id = ? AND r.statut = 'confirme' AND r.debut > ? AND r.debut <= ?", [$c['id'], iso($now), iso($now + 49 * 3600)]);
    foreach ($aVenir as $rdv) {
        $faits = array_filter(explode(',', (string)$rdv['rappels']));
        $avant = ts($rdv['debut']) - $now;
        $patient = une('SELECT * FROM patients WHERE id = ?', [$rdv['patient_id']]);
        // Du plus proche au plus lointain : si plusieurs rappels sont dus en même temps (moteur arrêté
        // un moment, horloge de démonstration avancée), seul le plus proche part ; les autres sont sautés.
        $envoye = false;
        foreach (['rappel_h3', 'rappel_j1', 'rappel_j2'] as $type) {
            $regle = $r[$type];
            $seuil = (int)$regle['heures'] * 3600;
            // Pas de rappel « 48 h avant » pour un rendez-vous pris moins de 48 h avant.
            $prisAvant = ts($rdv['debut']) - ts($rdv['cree_le']) > $seuil;
            if (!$regle['actif'] || in_array($type, $faits, true) || $avant > $seuil || !$prisAvant) {
                continue;
            }
            if ($envoye) {
                $faits[] = $type;
                continue;
            }
            // Le rappel de la veille n'est utile que si le patient n'a pas encore confirmé.
            if ($type === 'rappel_j1' && !empty($regle['si_non_confirme']) && $rdv['confirme_patient_le'] !== '') {
                $faits[] = $type;
                continue;
            }
            envoyer_patient($c, $patient, $type, rediger($c, $type, vars_rdv($c, $rdv, $patient)), (int)$rdv['id']);
            $faits[] = $type;
            $envoye = true;
            $bilan[] = str_replace(['rappel_j2', 'rappel_j1', 'rappel_h3'], ['Rappel 48 h', 'Rappel 24 h', 'Rappel 3 h'], $type) . ' : ' . $rdv['prenom'] . ' ' . $rdv['nom'];
        }
        executer('UPDATE rdv SET rappels = ? WHERE id = ?', [implode(',', array_unique($faits)), $rdv['id']]);
    }

    // 3. Offres de liste d'attente expirées : si le créneau est toujours libre, on passe aux suivants.
    $expirees = toutes("SELECT * FROM offres WHERE clinique_id = ? AND statut = 'envoyee' AND expire_le <= ?", [$c['id'], iso($now)]);
    $relances = [];
    foreach ($expirees as $o) {
        executer("UPDATE offres SET statut = 'expiree' WHERE id = ?", [$o['id']]);
        $relances[$o['praticien_id'] . '|' . $o['debut']] = $o;
    }
    foreach ($relances as $o) {
        if (ts($o['debut']) > $now && creneau_libre($c, (int)$o['praticien_id'], ts($o['debut']), ts($o['fin']))) {
            // Les patients déjà sollicités pour ce créneau ne le sont pas deux fois.
            $deja = array_column(toutes('SELECT attente_id FROM offres WHERE clinique_id = ? AND debut = ? AND praticien_id = ?', [$c['id'], $o['debut'], $o['praticien_id']]), 'attente_id');
            executer("UPDATE attente SET statut = 'sollicitee' WHERE id IN (" . implode(',', array_map('intval', $deja ?: [0])) . ") AND statut = 'active'");
            $n = proposer_liste_attente($c, ['id' => $o['rdv_libere_id'], 'patient_id' => 0, 'praticien_id' => $o['praticien_id'], 'type_id' => $o['type_id'], 'debut' => $o['debut'], 'fin' => $o['fin']]);
            executer("UPDATE attente SET statut = 'active' WHERE statut = 'sollicitee' AND clinique_id = ?", [$c['id']]);
            if ($n) {
                $bilan[] = "Créneau du " . date_longue($c, $o['debut']) . " reproposé à {$n} autre(s) patient(s)";
            }
        }
    }

    // 4. Rendez-vous passés : honorés par défaut, sauf absence signalée (réglable).
    if ($r['avis']['honore_par_defaut']) {
        $n = executer("UPDATE rdv SET statut = 'honore', honore_auto = 1 WHERE clinique_id = ? AND statut = 'confirme' AND fin <= ?", [$c['id'], iso($now - 3600)]);
        if ($n) {
            $bilan[] = "{$n} rendez-vous passé(s) marqué(s) honoré(s)";
        }
    }

    // 5. Demande d'avis Google : même message pour tous les patients (pas de tri des mécontents,
    //    interdit par les règles de Google), une fois par an au plus par patient.
    if ($r['avis']['actif'] && $c['avis_url'] !== '') {
        $honores = toutes("SELECT r.*, p.prenom, p.dernier_avis FROM rdv r JOIN patients p ON p.id = r.patient_id
            WHERE r.clinique_id = ? AND r.statut = 'honore' AND r.avis_envoye_le = '' AND r.fin <= ? AND r.fin > ?",
            [$c['id'], iso($now - (int)$r['avis']['heures_apres'] * 3600), iso($now - 7 * 86400)]);
        foreach ($honores as $rdv) {
            executer('UPDATE rdv SET avis_envoye_le = ? WHERE id = ?', [iso($now), $rdv['id']]);
            // Relu à chaque tour : un patient venu deux fois ne reçoit qu'une demande.
            $patient = une('SELECT * FROM patients WHERE id = ?', [$rdv['patient_id']]);
            if ($patient['dernier_avis'] !== '' && ts($patient['dernier_avis']) > $now - (int)$r['avis']['intervalle_mois'] * 30 * 86400) {
                continue;
            }
            envoyer_patient($c, $patient, 'avis', rediger($c, 'avis', ['prenom' => $patient['prenom'], 'lien' => lien('a', $rdv['jeton'])]), (int)$rdv['id']);
            executer('UPDATE patients SET dernier_avis = ? WHERE id = ?', [iso($now), $patient['id']]);
            $bilan[] = 'Demande d\'avis : ' . $patient['prenom'] . ' ' . $patient['nom'];
        }
    }

    // 6. Absence : message pour reprendre rendez-vous.
    if ($r['absence']['actif']) {
        foreach (toutes("SELECT * FROM rdv WHERE clinique_id = ? AND statut = 'absent' AND absence_envoyee_le = '' AND fin > ?", [$c['id'], iso($now - 3 * 86400)]) as $rdv) {
            executer('UPDATE rdv SET absence_envoyee_le = ? WHERE id = ?', [iso($now), $rdv['id']]);
            $patient = une('SELECT * FROM patients WHERE id = ?', [$rdv['patient_id']]);
            envoyer_patient($c, $patient, 'absence', rediger($c, 'absence', ['prenom' => $patient['prenom'], 'lien' => lien_reservation($c)]), (int)$rdv['id']);
            $bilan[] = 'Message après absence : ' . $patient['prenom'] . ' ' . $patient['nom'];
        }
    }

    // 7. Réactivation : patients ayant accepté les messages de suivi, non revus depuis N mois,
    //    sans rendez-vous à venir, pas relancés récemment. 20 par passage au plus.
    if ($r['reactivation']['actif']) {
        $limite = iso($now - (int)$r['reactivation']['mois'] * 30 * 86400);
        $pasAvant = iso($now - (int)$r['reactivation']['intervalle_mois'] * 30 * 86400);
        $cibles = toutes("SELECT p.* FROM patients p WHERE p.clinique_id = ? AND p.consentement_relance = 1 AND p.stop_sms = 0
            AND (p.derniere_relance = '' OR p.derniere_relance < ?)
            AND (SELECT MAX(fin) FROM rdv WHERE patient_id = p.id AND statut = 'honore') < ?
            AND NOT EXISTS (SELECT 1 FROM rdv WHERE patient_id = p.id AND statut = 'confirme' AND debut > ?) LIMIT 20",
            [$c['id'], $pasAvant, $limite, iso($now)]);
        foreach ($cibles as $patient) {
            envoyer_patient($c, $patient, 'reactivation', rediger($c, 'reactivation', ['prenom' => $patient['prenom'], 'lien' => lien_reservation($c)]));
            executer('UPDATE patients SET derniere_relance = ? WHERE id = ?', [iso($now), $patient['id']]);
            $bilan[] = 'Réactivation : ' . $patient['prenom'] . ' ' . $patient['nom'];
        }
    }

    // 8. Relance des devis sans réponse : deux relances au plus, puis le devis est considéré sans suite.
    if ($r['relance_devis']['actif']) {
        foreach (toutes("SELECT * FROM devis WHERE clinique_id = ? AND statut = 'envoye'", [$c['id']]) as $dv) {
            $faites = array_filter(explode(',', (string)$dv['relances']));
            $age = $now - ts($dv['envoye_le']);
            $due = null;
            if (count($faites) === 0 && $age >= (int)$r['relance_devis']['premier_j'] * 86400) {
                $due = 'r1';
            } elseif (count($faites) === 1 && $age >= (int)$r['relance_devis']['second_j'] * 86400) {
                $due = 'r2';
            } elseif (count($faites) >= 2 && $age >= ((int)$r['relance_devis']['second_j'] + 14) * 86400) {
                executer("UPDATE devis SET statut = 'sans_suite' WHERE id = ?", [$dv['id']]);
                continue;
            }
            if ($due === null) {
                continue;
            }
            $patient = une('SELECT * FROM patients WHERE id = ?', [$dv['patient_id']]);
            envoyer_patient($c, $patient, 'relance_devis', rediger($c, 'relance_devis', ['prenom' => $patient['prenom'], 'devis' => $dv['libelle'], 'lien' => lien('d', $dv['jeton'])]));
            $faites[] = $due;
            executer('UPDATE devis SET relances = ? WHERE id = ?', [implode(',', $faites), $dv['id']]);
            $bilan[] = 'Relance de devis : ' . $patient['prenom'] . ' ' . $patient['nom'] . ' (' . $dv['libelle'] . ')';
        }
    }

    return $bilan;
}
