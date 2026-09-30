<?php
/**
 * Plateforme SALW : clients de démonstration (un par métier) et scénario guidé.
 *
 * Tout est fictif : structures « (démo) », personnes inventées, numéros pris dans la tranche
 * 06 39 98 xx xx que l'ARCEP réserve aux œuvres de fiction (aucun abonné réel).
 * Le scénario utilise le vrai moteur ; seule l'horloge du client de démonstration est avancée.
 */

declare(strict_types=1);

function tel_fiction(int $n): string
{
    return '+3363998' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function slug_demo(string $metier): string
{
    return 'demo-' . $metier;
}

/** (Re)crée le client de démonstration d'un métier, avec 60 jours d'historique. */
function creer_demo(string $metier): array
{
    $profil = metiers()[$metier] ?? metiers()['sante'];
    $d = $profil['demo'];
    $existante = une('SELECT * FROM cliniques WHERE slug = ?', [slug_demo($metier)]);
    if ($existante) {
        foreach (['evenements', 'offres'] as $t) {
            executer("DELETE FROM {$t} WHERE clinique_id = ?", [$existante['id']]);
        }
        executer('DELETE FROM cliniques WHERE id = ?', [$existante['id']]);
    }
    $tzNom = $d['pays'] === 'BE' ? 'Europe/Brussels' : 'Europe/Paris';
    $id = inserer('INSERT INTO cliniques (slug, nom, adresse, ville, pays, fuseau, telephone, email, avis_url, valeur_consultation, faq, demo, horloge_decalage, metier, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)',
        [slug_demo($metier), $d['nom'], $d['adresse'], $d['ville'], $d['pays'], $tzNom, tel_fiction(1000), 'contact@demo.example', 'https://www.google.com/maps', $d['valeur'], $d['faq'], $metier, iso(time())]);
    // Une couleur par métier, pour montrer l'image du client sur sa page de réservation.
    $couleurs = ['sante' => '#0f766e', 'juridique' => '#1f3a5f', 'immobilier' => '#0e7490', 'artisan' => '#c2410c', 'coach' => '#7c3aed', 'ecole' => '#1d4ed8', 'garage' => '#b91c1c'];
    executer('UPDATE cliniques SET couleur = ?, atouts = ? WHERE id = ?', [$couleurs[$metier] ?? '', implode("\n", vitrine_metier($metier)['atouts_demo']), $id]);
    $c = clinique($id);

    // Horaires : lundi-vendredi pour le premier, emplois du temps partiels pour les autres.
    $plages = [['09:00', '12:00'], ['14:00', '18:00']];
    $h = function (array $jours) use ($plages) { $x = []; foreach ($jours as $j) { $x[(string)$j] = $plages; } return json_encode($x); };
    $pros = [];
    foreach ($d['pros'] as $i => [$nom, $titre]) {
        $pros[] = inserer('INSERT INTO praticiens (clinique_id, nom, titre, horaires) VALUES (?, ?, ?, ?)', [$id, $nom, $titre, $h([[1, 2, 3, 4, 5], [1, 2, 4], [2, 3, 4, 5]][$i % 3])]);
    }
    $types = [];
    foreach ($d['types'] as [$libelle, $duree, $enLigne]) {
        $types[] = inserer('INSERT INTO types_rdv (clinique_id, libelle, duree, en_ligne) VALUES (?, ?, ?, ?)', [$id, $libelle, $duree, $enLigne]);
    }
    $t1 = $types[0];
    $duree1 = (int)$d['types'][0][1] * 60;

    $prenoms = ['Emma', 'Louis', 'Jade', 'Gabriel', 'Léa', 'Arthur', 'Chloé', 'Raphaël', 'Manon', 'Jules', 'Alice', 'Adam', 'Lina', 'Nathan', 'Rose', 'Paul', 'Anna', 'Victor', 'Zoé', 'Hugo', 'Inès', 'Lucas', 'Sarah', 'Tom', 'Julie', 'Noah', 'Clara', 'Léo', 'Eva', 'Ethan'];
    $noms = ['Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Durand', 'Moreau', 'Simon', 'Laurent', 'Lefebvre', 'Michel', 'Garcia', 'David', 'Bertrand', 'Roux', 'Vincent', 'Fournier', 'Girard', 'Bonnet', 'Dupuis'];
    mt_srand(crc32($metier));
    $clients = [];
    foreach ($prenoms as $i => $pr) {
        $clients[] = inserer('INSERT INTO patients (clinique_id, prenom, nom, telephone, email, consentement_relance, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $pr, $noms[$i % count($noms)], tel_fiction(1001 + $i), '', $i % 3 === 0 ? 0 : 1, iso(time() - 90 * 86400)]);
    }
    $now = time();
    $statuts = ['honore', 'honore', 'honore', 'honore', 'honore', 'honore', 'honore', 'honore', 'honore', 'honore', 'absent', 'annule'];
    for ($j = 60; $j >= 1; $j--) {
        $jour = (new DateTimeImmutable('@' . ($now - $j * 86400)))->setTimezone(tz($c));
        if ((int)$jour->format('N') > 5) {
            continue;
        }
        for ($k = 0; $k < 5; $k++) {
            $debut = $jour->setTime(9 + $k, 0)->getTimestamp();
            $statut = $statuts[mt_rand(0, count($statuts) - 1)];
            if ($statut === 'absent' && $j < 30 && mt_rand(0, 2) > 0) {
                $statut = 'honore'; // moins d'absences depuis le lancement des rappels, il y a 30 jours
            }
            inserer('INSERT INTO rdv (clinique_id, praticien_id, patient_id, type_id, debut, fin, statut, source, jeton, rappels, avis_envoye_le, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $pros[$k % 3], $clients[mt_rand(0, count($clients) - 1)], $t1, iso($debut), iso($debut + $duree1), $statut, mt_rand(0, 2) ? 'en_ligne' : 'secretariat', jeton(),
                    $j < 30 ? 'rappel_j2,rappel_j1' : '', $statut === 'honore' && $j < 30 ? iso($debut + 3 * 3600) : '', iso($debut - mt_rand(3, 10) * 86400)]);
            if ($j < 30 && $statut === 'annule' && mt_rand(0, 1)) {
                inserer('INSERT INTO evenements (clinique_id, t, type, detail, valeur) VALUES (?, ?, ?, ?, ?)', [$id, iso($debut - 86400), 'creneau_recupere', 'historique de démonstration', $d['valeur']]);
            }
            if ($j < 30 && $statut === 'honore' && mt_rand(0, 3) === 0) {
                inserer('INSERT INTO evenements (clinique_id, t, type, detail) VALUES (?, ?, ?, ?)', [$id, iso($debut + 5 * 3600), 'avis_clic', '']);
            }
        }
    }
    for ($i = 0; $i < 12; $i++) {
        $t = $now - mt_rand(1, 29) * 86400 - mt_rand(0, 8) * 3600;
        $rattrape = $i % 3 !== 0;
        inserer('INSERT INTO appels (clinique_id, numero, recu_le, statut, sms_envoye_le, source) VALUES (?, ?, ?, ?, ?, ?)', [$id, tel_fiction(1200 + $i), iso($t), $rattrape ? 'rdv_pris' : 'manque', iso($t + 120), 'simulation']);
        if ($rattrape) {
            inserer('INSERT INTO evenements (clinique_id, t, type, detail, valeur) VALUES (?, ?, ?, ?, ?)', [$id, iso($t + 3600), 'appel_rattrape', 'historique de démonstration', $d['valeur']]);
        }
    }
    for ($j = 1; $j <= 15; $j++) {
        $jour = (new DateTimeImmutable('@' . ($now + $j * 86400)))->setTimezone(tz($c));
        if ((int)$jour->format('N') > 5) {
            continue;
        }
        foreach ([9, 15] as $hh) {
            $debut = $jour->setTime($hh, 0)->getTimestamp();
            inserer("INSERT INTO rdv (clinique_id, praticien_id, patient_id, type_id, debut, fin, statut, source, jeton, cree_le) VALUES (?, ?, ?, ?, ?, ?, 'confirme', 'en_ligne', ?, ?)",
                [$id, $pros[0], $clients[mt_rand(0, count($clients) - 1)], $t1, iso($debut), iso($debut + $duree1), jeton(), iso($now - 5 * 86400)]);
        }
    }
    // Liste d'attente : Lucas, Inès et Hugo d'abord (ils servent au scénario).
    foreach ([21 => 10, 20 => 9, 19 => 8, 25 => 3] as $idx => $depuis) {
        inserer('INSERT INTO attente (clinique_id, patient_id, preference, cree_le) VALUES (?, ?, ?, ?)', [$id, $clients[$idx], 'Au plus tôt', iso($now - $depuis * 86400)]);
    }
    // Devis en cours (métiers qui relancent les devis).
    if (reglages($c)['relance_devis']['actif']) {
        foreach ([[2, 'Travaux de rénovation salle de bains', 3400, 12], [5, 'Forfait accompagnement', 1800, 5], [8, 'Intervention complète', 950, 1]] as [$idx, $lib, $mt, $jours]) {
            $dv = creer_devis($c, une('SELECT * FROM patients WHERE id = ?', [$clients[$idx]]), $lib, $mt);
            executer('UPDATE devis SET envoye_le = ?, relances = ? WHERE id = ?', [iso($now - $jours * 86400), $jours > 7 ? 'r1,r2' : ($jours > 3 ? 'r1' : ''), $dv]);
        }
    }
    return clinique($id);
}

/** Crée les démonstrations des 7 métiers. */
function creer_toutes_demos(): void
{
    foreach (array_keys(metiers()) as $m) {
        creer_demo($m);
    }
}

// --- Scénario guidé --------------------------------------------------------------------

function avancer_horloge(array $c, int $cible): array
{
    executer('UPDATE cliniques SET horloge_decalage = ? WHERE id = ?', [$cible - time(), $c['id']]);
    return clinique((int)$c['id']);
}

function etat_scenario(array $c): array
{
    return $_SESSION['scenario'][$c['id']] ?? [];
}

function sauver_scenario(array $c, array $s): void
{
    $_SESSION['scenario'][$c['id']] = $s;
}

/** Étapes adaptées au métier : [titre, ce qui se passe, bouton]. */
function etapes_scenario(array $c): array
{
    $r = reglages($c);
    $client = mot($c, 'client');
    $rdv = mot($c, 'rdv');
    $st = mot($c, 'structure');
    $e = [
        1 => ['Camille réserve en ligne', "Camille Martin prend rendez-vous ({$rdv}) sur la page de réservation, un soir à 22 h, quand personne ne répond. Elle reçoit aussitôt la confirmation par SMS, avec un lien pour gérer son rendez-vous." . (metier($c)['questions'] ? ' Les questions de réservation du métier sont enregistrées.' : ''), 'Camille réserve'],
        2 => ['48 heures avant', "L'horloge avance à 48 h du rendez-vous. Le moteur envoie le rappel à Camille, et à tous les {$client}s concernés au même moment.", 'Avancer de 48 h'],
        3 => ['Camille annule depuis le lien', $r['liste_attente']['actif'] ? "Empêchée, Camille clique sur « Annuler » dans le SMS, sans appeler. Le créneau est aussitôt proposé aux 3 premiers {$client}s de la liste d'attente." : "Empêchée, Camille annule par le lien du SMS : le créneau se libère aussitôt dans l'agenda en ligne (liste d'attente désactivée pour ce métier).", 'Camille annule'],
        4 => ['Lucas accepte le premier', $r['liste_attente']['actif'] ? "Lucas clique le premier et obtient le créneau. Inès clique juste après : le créneau est déjà attribué, elle reste sur la liste d'attente." : "Lucas réserve le créneau libéré en ligne.", 'Lucas prend le créneau'],
        5 => ['Un appel est manqué', "Nadia appelle pendant que personne ne peut décrocher. Peu après, elle reçoit un SMS avec le lien de réservation, et réserve.", "Simuler l'appel manqué"],
        6 => ['Après le rendez-vous de Lucas', $r['avis']['actif'] ? "Le rendez-vous passe en « honoré », puis Lucas reçoit la demande d'avis Google." : "Le rendez-vous passe en « honoré » (pas de demande d'avis pour ce métier, réglable).", 'Avancer après le rendez-vous'],
        7 => ['Un client ne vient pas', "Julien ne se présente pas. Il est marqué absent en un clic et reçoit un message pour reprendre rendez-vous.", 'Marquer Julien absent'],
        8 => ['Des mois plus tard', "Martine, qui a accepté les messages de suivi, n'est pas revenue depuis longtemps. Le moteur lui propose de reprendre rendez-vous.", 'Lancer la réactivation'],
    ];
    if ($r['relance_devis']['actif']) {
        $e[9] = ['Un devis relancé est accepté', "Un devis envoyé il y a 3 jours est resté sans réponse : le moteur le relance par SMS. Le client l'accepte en un clic depuis le lien.", 'Relancer et accepter'];
    }
    return $e;
}

/** Exécute une étape. Renvoie [ce qui a été fait (lignes), ids des messages produits]. */
function jouer_etape(array $c, int $n): array
{
    $s = etat_scenario($c);
    $avant = (int)valeur('SELECT COALESCE(MAX(id), 0) FROM messages WHERE clinique_id = ?', [$c['id']]);
    $fait = [];
    $type = types_rdv($c, true)[0];
    $pro = une("SELECT * FROM praticiens WHERE clinique_id = ? AND nom = 'Claire Morel'", [$c['id']]);

    if ($n === 1) {
        $cible = (new DateTimeImmutable('@' . maintenant($c)))->setTimezone(tz($c))->modify('+4 days');
        $debut = null;
        foreach (creneaux_libres($c, $type, (int)$pro['id'], 400) as $cr) {
            if (local($c, $cr['debut'], 'Y-m-d') >= $cible->format('Y-m-d') && local($c, $cr['debut'], 'H') >= '10') {
                $debut = ts($cr['debut']);
                break;
            }
        }
        $camille = patient_trouver_ou_creer($c, 'Camille', 'Martin', tel_fiction(2001), '', false);
        $infos = [];
        foreach (metier($c)['questions'] as $q) {
            $infos[$q['libelle']] = $q['type'] === 'choix' ? $q['options'][0] : ['adresse' => '18 rue des Lilas', 'immatriculation' => 'AB-123-CD', 'niveau' => 'Seconde', 'objectif' => 'Préparer une reconversion', 'secteur' => 'Centre-ville', 'vehicule' => 'Peugeot 208'][$q['cle']] ?? 'Exemple';
        }
        [$rdv] = creer_rdv($c, $camille, (int)$pro['id'], (int)$type['id'], (int)$debut, (int)$type['duree'], 'en_ligne', null, $infos ? json_encode($infos, JSON_UNESCAPED_UNICODE) : '');
        $s = ['rdv_camille' => (int)$rdv['id']];
        $fait[] = 'Rendez-vous créé : ' . date_longue($c, $rdv['debut']) . ' à ' . heure($c, $rdv['debut']) . ', ' . nom_pro($pro) . '.';
        foreach ($infos as $k => $v) {
            $fait[] = "Réponse enregistrée · {$k} : {$v}";
        }
    } elseif ($n === 2) {
        $rdv = une('SELECT * FROM rdv WHERE id = ?', [$s['rdv_camille'] ?? 0]);
        $c = avancer_horloge($c, ts($rdv['debut']) - 47 * 3600);
        $fait = executer_automatisations($c);
    } elseif ($n === 3) {
        $rdv = une('SELECT * FROM rdv WHERE id = ?', [$s['rdv_camille'] ?? 0]);
        annuler_rdv($c, $rdv, 'client');
        $fait[] = 'Rendez-vous de Camille annulé par le lien du SMS.';
        $nb = (int)valeur('SELECT COUNT(*) FROM offres WHERE rdv_libere_id = ?', [$rdv['id']]);
        $fait[] = $nb ? "{$nb} " . mot($c, 'clients') . " de la liste d'attente prévenu(s) en même temps." : 'Créneau de nouveau réservable en ligne.';
    } elseif ($n === 4) {
        $camilleRdv = une('SELECT * FROM rdv WHERE id = ?', [$s['rdv_camille'] ?? 0]);
        $offres = toutes('SELECT o.*, p.prenom FROM offres o JOIN attente a ON a.id = o.attente_id JOIN patients p ON p.id = a.patient_id WHERE o.rdv_libere_id = ? ORDER BY o.id', [$camilleRdv['id']]);
        if ($offres) {
            $lucas = array_values(array_filter($offres, function ($o) { return $o['prenom'] === 'Lucas'; }))[0] ?? $offres[0];
            [$rdv] = accepter_offre($c, $lucas);
            $fait[] = 'Lucas obtient le créneau (' . heure($c, $rdv['debut']) . ').';
            $ines = array_values(array_filter($offres, function ($o) { return $o['prenom'] === 'Inès'; }))[0] ?? null;
            if ($ines) {
                [, $raison] = accepter_offre($c, une('SELECT * FROM offres WHERE id = ?', [$ines['id']]));
                $fait[] = 'Inès clique ensuite : « ' . $raison . ' »';
            }
        } else {
            $lucas = une("SELECT * FROM patients WHERE clinique_id = ? AND prenom = 'Lucas'", [$c['id']]);
            [$rdv] = creer_rdv($c, $lucas, (int)$camilleRdv['praticien_id'], (int)$camilleRdv['type_id'], ts($camilleRdv['debut']), (int)((ts($camilleRdv['fin']) - ts($camilleRdv['debut'])) / 60), 'en_ligne');
            $fait[] = 'Lucas réserve le créneau libéré en ligne (' . heure($c, $rdv['debut']) . ').';
        }
        $s['rdv_lucas'] = (int)$rdv['id'];
    } elseif ($n === 5) {
        inserer('INSERT INTO appels (clinique_id, numero, recu_le, source) VALUES (?, ?, ?, ?)', [$c['id'], tel_fiction(2002), iso(maintenant($c)), 'simulation']);
        evenement($c, 'appel_manque', telephone_lisible(tel_fiction(2002)));
        $c = avancer_horloge($c, maintenant($c) + 3 * 60);
        $fait = executer_automatisations($c);
        $nadia = patient_trouver_ou_creer($c, 'Nadia', 'Benali', tel_fiction(2002), '', false);
        $liste = creneaux_libres($c, $type, null, 40);
        $cr = $liste[12] ?? $liste[0];
        [$rdv] = creer_rdv($c, $nadia, (int)$cr['praticien_id'], (int)$type['id'], ts($cr['debut']), (int)$type['duree'], 'en_ligne');
        $fait[] = 'Nadia réserve depuis le lien : ' . date_longue($c, $rdv['debut']) . ' à ' . heure($c, $rdv['debut']) . '. Appel rattrapé.';
    } elseif ($n === 6) {
        $rdv = une('SELECT * FROM rdv WHERE id = ?', [$s['rdv_lucas'] ?? 0]);
        $c = avancer_horloge($c, ts($rdv['fin']) + 3 * 3600 + 60);
        $fait = executer_automatisations($c);
    } elseif ($n === 7) {
        $julien = patient_trouver_ou_creer($c, 'Julien', 'Roche', tel_fiction(2003), '', false);
        $debut = maintenant($c) - 2 * 3600;
        $j = jeton();
        inserer("INSERT INTO rdv (clinique_id, praticien_id, patient_id, type_id, debut, fin, statut, source, jeton, cree_le) VALUES (?, ?, ?, ?, ?, ?, 'confirme', 'secretariat', ?, ?)",
            [$c['id'], $pro['id'], $julien['id'], $type['id'], iso($debut), iso($debut + 1200), $j, iso($debut - 5 * 86400)]);
        marquer_statut($c, une('SELECT * FROM rdv WHERE jeton = ?', [$j]), 'absent');
        $fait[] = 'Julien marqué absent.';
        $fait = array_merge($fait, executer_automatisations($c));
    } elseif ($n === 8) {
        $martine = patient_trouver_ou_creer($c, 'Martine', 'Colas', tel_fiction(2004), '', true);
        $ancien = maintenant($c) - ((int)reglages($c)['reactivation']['mois'] + 1) * 31 * 86400;
        inserer("INSERT INTO rdv (clinique_id, praticien_id, patient_id, type_id, debut, fin, statut, source, jeton, cree_le) VALUES (?, ?, ?, ?, ?, ?, 'honore', 'secretariat', ?, ?)",
            [$c['id'], $pro['id'], $martine['id'], $type['id'], iso($ancien), iso($ancien + 1200), jeton(), iso($ancien - 86400)]);
        $fait[] = 'Martine : dernière venue il y a ' . ((int)reglages($c)['reactivation']['mois'] + 1) . ' mois, messages de suivi acceptés.';
        $fait = array_merge($fait, executer_automatisations($c));
    } elseif ($n === 9) {
        $client = patient_trouver_ou_creer($c, 'Karim', 'Haddad', tel_fiction(2005), '', false);
        $dvId = creer_devis($c, $client, 'Devis n° 2026-118', 1450);
        executer('UPDATE devis SET envoye_le = ? WHERE id = ?', [iso(maintenant($c) - 3 * 86400 - 60), $dvId]);
        $fait[] = 'Devis de 1 450 € envoyé à Karim il y a 3 jours, sans réponse.';
        $fait = array_merge($fait, executer_automatisations($c));
        repondre_devis($c, une('SELECT * FROM devis WHERE id = ?', [$dvId]), true);
        $fait[] = 'Karim clique sur le lien et accepte le devis : 1 450 € comptés dans le chiffre d\'affaires récupéré.';
    }
    $s['etape'] = $n;
    sauver_scenario($c, $s);
    $ids = array_column(toutes('SELECT id FROM messages WHERE clinique_id = ? AND id > ? ORDER BY id', [$c['id'], $avant]), 'id');
    return [$fait, $ids];
}
