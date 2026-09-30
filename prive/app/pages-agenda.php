<?php
/**
 * Plateforme SALW Santé : agenda, patients, liste d'attente, appels manqués, messages.
 */

declare(strict_types=1);

function page_agenda(): void
{
    $c = clinique_courante();
    $tzc = tz($c);
    $jour = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['jour'] ?? '')) ? (string)$_GET['jour'] : (new DateTimeImmutable('@' . maintenant($c)))->setTimezone($tzc)->format('Y-m-d');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rdv = une('SELECT * FROM rdv WHERE id = ? AND clinique_id = ?', [(int)($_POST['id'] ?? 0), $c['id']]);
        $a = (string)($_POST['action'] ?? '');
        if ($rdv && $a === 'annuler') {
            annuler_rdv($c, $rdv, 'secretariat');
            $n = (int)valeur("SELECT COUNT(*) FROM offres WHERE rdv_libere_id = ?", [$rdv['id']]);
            flash('ok', 'Rendez-vous annulé.' . ($n ? " Créneau proposé à {$n} " . mot($c, 'clients') . " de la liste d'attente." : ''));
        } elseif ($rdv && in_array($a, ['honore', 'absent', 'confirme'], true)) {
            marquer_statut($c, $rdv, $a);
            if ($a === 'absent') {
                executer_automatisations($c);
            }
            flash('ok', 'Statut enregistré.');
        }
        journaliser('rdv_' . $a, (string)($_POST['id'] ?? ''), (int)$c['id']);
        aller('agenda', ['jour' => $jour]);
    }

    $d = new DateTimeImmutable($jour, $tzc);
    $rdvs = toutes("SELECT r.*, p.prenom, p.nom, p.telephone, t.libelle FROM rdv r JOIN patients p ON p.id = r.patient_id LEFT JOIN types_rdv t ON t.id = r.type_id
        WHERE r.clinique_id = ? AND r.debut BETWEEN ? AND ? ORDER BY r.debut", [$c['id'], iso($d->getTimestamp()), iso($d->modify('+1 day')->getTimestamp() - 1)]);
    entete('Agenda', 'agenda');
    $nav = '<a class="btn contour" href="' . h(url('agenda', ['jour' => $d->modify('-1 day')->format('Y-m-d')])) . '">‹ Veille</a>'
        . '<form method="get" class="ligne"><input type="hidden" name="p" value="agenda"><input type="date" name="jour" value="' . h($jour) . '" data-envoi-auto aria-label="Jour"></form>'
        . '<a class="btn contour" href="' . h(url('agenda', ['jour' => $d->modify('+1 day')->format('Y-m-d')])) . '">Lendemain ›</a>'
        . '<a class="btn" href="' . h(url('nouveau', ['jour' => $jour])) . '">+ Rendez-vous</a>';
    titre('Agenda', date_longue($c, iso($d->getTimestamp() + 43200)) . ' ' . $d->format('Y'), $nav);
    echo '<div class="colonnes-agenda">';
    foreach (praticiens($c) as $p) {
        echo '<section class="carte"><h2>' . h(nom_pro($p)) . '</h2>';
        $liste = array_filter($rdvs, function ($r) use ($p) { return (int)$r['praticien_id'] === (int)$p['id']; });
        if (!$liste) {
            echo '<p class="vide-mini">Aucun rendez-vous.</p>';
        }
        foreach ($liste as $r) {
            $passe = ts($r['fin']) < maintenant($c);
            echo '<article class="creneau-rdv ' . h($r['statut']) . '"><div class="cr-h">' . h(local($c, $r['debut'], 'H:i')) . '</div><div class="cr-c"><b>' . h($r['prenom'] . ' ' . $r['nom']) . '</b> '
                . badge(LIBELLES_STATUTS[$r['statut']], ton_statut($r['statut'])) . ($r['confirme_patient_le'] !== '' ? ' ' . badge('confirmé par le client', 'vert') : '')
                . '<br><span class="gris">' . h((string)$r['libelle']) . ' · ' . h(['en_ligne' => 'réservé en ligne', 'secretariat' => 'secrétariat', 'liste_attente' => "liste d'attente", 'telephone' => 'téléphone'][$r['source']] ?? $r['source']) . ' · ' . h(telephone_lisible($r['telephone'])) . '</span>';
            // Réponses aux questions de réservation du métier (domaine, adresse, immatriculation…).
            foreach ((array)json_decode((string)$r['infos'], true) as $q => $v) {
                echo '<br><span class="info-rdv">' . h($q) . ' : <b>' . h((string)$v) . '</b></span>';
            }
            if ($r['statut'] !== 'annule') {
                echo '<div class="cr-actions">';
                foreach ($passe ? ['honore' => 'Honoré', 'absent' => 'Absent'] : ['annuler' => 'Annuler'] as $act => $lib) {
                    if ($act === $r['statut']) {
                        continue;
                    }
                    echo '<form method="post"' . ($act === 'annuler' ? ' data-confirmer="Annuler ce rendez-vous ? Le créneau sera proposé à la liste d\'attente."' : '') . '>' . champ_csrf()
                        . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="action" value="' . $act . '"><button class="lien">' . $lib . '</button></form>';
                }
                echo '</div>';
            }
            echo '</div></article>';
        }
        echo '</section>';
    }
    echo '</div>';
    pied();
}

function page_nouveau(): void
{
    $c = clinique_courante();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tel = telephone((string)($_POST['telephone'] ?? ''), $c['pays']);
        $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
        $type = une('SELECT * FROM types_rdv WHERE id = ? AND clinique_id = ?', [(int)($_POST['type'] ?? 0), $c['id']]);
        [$praticienId, $debut] = array_map('strval', explode('|', (string)($_POST['creneau'] ?? '|')) + [1 => '']);
        [$infos, $errQ] = lire_questions($c, $_POST);
        if (trim((string)($_POST['prenom'] ?? '')) === '' || ($tel === '' && $email === '') || !$type || !$praticienId || $errQ !== '') {
            flash('erreur', $errQ !== '' ? $errQ : 'Prénom, mobile (ou e-mail), type et créneau sont nécessaires.');
            aller('nouveau');
        }
        $patient = patient_trouver_ou_creer($c, trim((string)$_POST['prenom']), trim((string)($_POST['nom'] ?? '')), $tel, $email, !empty($_POST['relance']));
        [$rdv, $err] = creer_rdv($c, $patient, (int)$praticienId, (int)$type['id'], ts($debut), (int)$type['duree'], 'secretariat', null, $infos);
        journaliser('rdv_cree', $patient['prenom'] . ' ' . $patient['nom'], (int)$c['id']);
        flash($rdv ? 'ok' : 'erreur', $rdv ? 'Rendez-vous créé, confirmation envoyée au ' . mot($c, 'client') . '.' : $err);
        aller($rdv ? 'agenda' : 'nouveau', $rdv ? ['jour' => local($c, $rdv['debut'], 'Y-m-d')] : []);
    }
    $types = types_rdv($c);
    $typeId = (int)($_GET['type'] ?? ($types[0]['id'] ?? 0));
    $type = une('SELECT * FROM types_rdv WHERE id = ? AND clinique_id = ?', [$typeId, $c['id']]) ?: ($types[0] ?? null);
    $prat = (int)($_GET['praticien'] ?? 0) ?: null;
    entete('Nouveau rendez-vous', 'agenda');
    titre('Nouveau rendez-vous', 'Pris par téléphone ou à l\'accueil. Le ' . mot($c, 'client') . ' reçoit la confirmation et les rappels comme en ligne.');
    echo '<form method="get" class="filtres-ligne"><input type="hidden" name="p" value="nouveau"><label>Type <select name="type" data-envoi-auto>';
    foreach ($types as $t) {
        echo '<option value="' . (int)$t['id'] . '"' . ((int)$t['id'] === (int)$type['id'] ? ' selected' : '') . '>' . h($t['libelle']) . ' (' . (int)$t['duree'] . ' min)</option>';
    }
    echo '</select></label><label>' . h(mot($c, 'Pro')) . ' <select name="praticien" data-envoi-auto><option value="">Tous</option>';
    foreach (praticiens($c) as $p) {
        echo '<option value="' . (int)$p['id'] . '"' . ($prat === (int)$p['id'] ? ' selected' : '') . '>' . h(nom_pro($p)) . '</option>';
    }
    echo '</select></label></form>';
    $noms = [];
    foreach (praticiens($c) as $p) {
        $noms[$p['id']] = nom_pro($p);
    }
    echo '<form method="post" class="carte form">' . champ_csrf() . '<input type="hidden" name="type" value="' . (int)$type['id'] . '"><label>Créneau<select name="creneau" required>';
    foreach (creneaux_libres($c, $type, $prat, 150) as $cr) {
        echo '<option value="' . $cr['praticien_id'] . '|' . h($cr['debut']) . '">' . h(date_longue($c, $cr['debut']) . ' · ' . local($c, $cr['debut'], 'H:i') . ' · ' . $noms[$cr['praticien_id']]) . '</option>';
    }
    echo '</select></label><div class="champ-duo"><label>Prénom<input name="prenom" required maxlength="60"></label><label>Nom<input name="nom" maxlength="60"></label></div>'
        . '<div class="champ-duo"><label>Mobile<input name="telephone" type="tel" placeholder="06 12 34 56 78"></label><label>E-mail (si pas de mobile)<input name="email" type="email"></label></div>'
        . champs_questions($c) . '<label class="case"><input type="checkbox" name="relance" value="1"> Le ' . h(mot($c, 'client')) . ' accepte les messages de suivi (réactivation)</label><button class="btn">Créer le rendez-vous</button></form>';
    pied();
}

function page_patients(): void
{
    $c = clinique_courante();
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $p = une('SELECT * FROM patients WHERE id = ? AND clinique_id = ?', [$id, $c['id']]);
        if (!$p) {
            aller('patients');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (($_POST['action'] ?? '') === 'supprimer' && peut_gerer()) {
                executer('DELETE FROM patients WHERE id = ?', [$id]);
                journaliser('patient_supprime', $p['prenom'] . ' ' . $p['nom'], (int)$c['id']);
                flash('ok', ucfirst(mot($c, 'client')) . ' et historique supprimés (droit à l\'effacement).');
                aller('patients');
            }
            executer('UPDATE patients SET consentement_relance = ?, stop_sms = ? WHERE id = ?', [!empty($_POST['relance']) ? 1 : 0, !empty($_POST['stop']) ? 1 : 0, $id]);
            flash('ok', 'Préférences enregistrées.');
            aller('patients', ['id' => $id]);
        }
        $rdvs = toutes("SELECT r.*, pr.nom AS praticien, pr.titre FROM rdv r JOIN praticiens pr ON pr.id = r.praticien_id WHERE r.patient_id = ? ORDER BY r.debut DESC", [$id]);
        $msgs = toutes('SELECT * FROM messages WHERE patient_id = ? ORDER BY id DESC LIMIT 20', [$id]);
        entete($p['prenom'], 'patients');
        titre($p['prenom'] . ' ' . $p['nom'], telephone_lisible($p['telephone']) . ($p['email'] !== '' ? ' · ' . $p['email'] : ''));
        echo '<div class="grille-2"><section class="carte"><h2>Rendez-vous</h2><ul class="liste-simple">';
        foreach ($rdvs as $r) {
            echo '<li>' . h(local($c, $r['debut'], 'd/m/Y H:i')) . ' · ' . h(nom_pro(['titre' => $r['titre'], 'nom' => $r['praticien']])) . ' ' . badge(LIBELLES_STATUTS[$r['statut']], ton_statut($r['statut'])) . '</li>';
        }
        echo ($rdvs ? '' : '<li class="vide-mini">Aucun.</li>') . '</ul><h2 class="sep">Préférences</h2><form method="post" class="form">' . champ_csrf()
            . '<label class="case"><input type="checkbox" name="relance" value="1"' . ((int)$p['consentement_relance'] ? ' checked' : '') . '> Accepte les messages de suivi (réactivation)</label>'
            . '<label class="case"><input type="checkbox" name="stop" value="1"' . ((int)$p['stop_sms'] ? ' checked' : '') . '> A répondu STOP (plus aucun message de suivi)</label><button class="btn contour">Enregistrer</button></form>';
        if (peut_gerer()) {
            echo '<form method="post" data-confirmer="Supprimer définitivement ce ' . h(mot($c, 'client')) . ' et tout son historique ?">' . champ_csrf() . '<input type="hidden" name="action" value="supprimer"><button class="lien danger">Supprimer ce ' . h(mot($c, 'client')) . ' (droit à l\'effacement)</button></form>';
        }
        echo '</section><section class="carte"><h2>Messages reçus</h2><div class="telephones">';
        foreach ($msgs as $m) {
            echo bulle_message($c, $m, false);
        }
        echo ($msgs ? '' : '<p class="vide-mini">Aucun.</p>') . '</div></section></div>';
        pied();
        return;
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $liste = toutes("SELECT p.*, (SELECT MAX(debut) FROM rdv WHERE patient_id = p.id AND statut != 'annule') AS dernier FROM patients p WHERE p.clinique_id = ?"
        . ($q !== '' ? " AND (p.prenom || ' ' || p.nom || ' ' || p.telephone) LIKE ?" : '') . ' ORDER BY p.nom, p.prenom LIMIT 300', $q !== '' ? [$c['id'], '%' . $q . '%'] : [$c['id']]);
    entete(mot($c, 'Clients'), 'patients');
    titre(mot($c, 'Clients'), count($liste) . ' ' . mot($c, 'client') . '(s)');
    echo '<form class="filtres-ligne" method="get"><input type="hidden" name="p" value="patients"><input type="search" name="q" value="' . h($q) . '" placeholder="Nom ou téléphone" aria-label="Rechercher"><button class="btn contour">Rechercher</button></form>'
        . '<div class="table"><table><thead><tr><th>' . h(ucfirst(mot($c, 'client'))) . '</th><th>Mobile</th><th>Dernier rendez-vous</th><th>Suivi</th></tr></thead><tbody>';
    foreach ($liste as $p) {
        echo '<tr><td><a href="' . h(url('patients', ['id' => $p['id']])) . '"><b>' . h($p['prenom'] . ' ' . $p['nom']) . '</b></a></td><td>' . h(telephone_lisible($p['telephone'])) . '</td><td>' . ($p['dernier'] ? h(local($c, $p['dernier'], 'd/m/Y')) : '—') . '</td><td>'
            . ((int)$p['stop_sms'] ? badge('STOP', 'rouge') : ((int)$p['consentement_relance'] ? badge('accepte', 'vert') : badge('non', 'gris'))) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    pied();
}

function page_attente(): void
{
    $c = clinique_courante();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['action'] ?? '') === 'retirer') {
            executer("UPDATE attente SET statut = 'retiree' WHERE id = ? AND clinique_id = ?", [(int)$_POST['id'], $c['id']]);
            flash('ok', "Retiré de la liste d'attente.");
        } else {
            $tel = telephone((string)($_POST['telephone'] ?? ''), $c['pays']);
            if (trim((string)($_POST['prenom'] ?? '')) === '' || $tel === '') {
                flash('erreur', 'Prénom et mobile valides nécessaires.');
            } else {
                $p = patient_trouver_ou_creer($c, trim((string)$_POST['prenom']), trim((string)($_POST['nom'] ?? '')), $tel, '', false);
                inserer('INSERT INTO attente (clinique_id, patient_id, praticien_id, preference, cree_le) VALUES (?, ?, ?, ?, ?)',
                    [$c['id'], $p['id'], ((int)($_POST['praticien'] ?? 0)) ?: null, mb_substr(trim((string)($_POST['preference'] ?? '')), 0, 120), iso(maintenant($c))]);
                flash('ok', "Ajouté à la liste d'attente : il sera prévenu dès qu'un créneau se libère.");
            }
        }
        aller('attente');
    }
    $liste = toutes("SELECT a.*, p.prenom, p.nom, p.telephone, pr.nom AS praticien, pr.titre FROM attente a JOIN patients p ON p.id = a.patient_id LEFT JOIN praticiens pr ON pr.id = a.praticien_id
        WHERE a.clinique_id = ? AND a.statut IN ('active', 'servie') ORDER BY a.statut, a.cree_le", [$c['id']]);
    entete("Liste d'attente", 'attente');
    titre("Liste d'attente", "Quand un rendez-vous est annulé, les premiers " . mot($c, 'clients') . " compatibles reçoivent un SMS : le premier qui confirme obtient le créneau.");
    echo '<div class="table"><table><thead><tr><th>Depuis</th><th>' . h(ucfirst(mot($c, 'client'))) . '</th><th>' . h(mot($c, 'Pro')) . '</th><th>Préférence</th><th>État</th><th></th></tr></thead><tbody>';
    foreach ($liste as $a) {
        echo '<tr><td>' . h(local($c, $a['cree_le'], 'd/m')) . '</td><td><b>' . h($a['prenom'] . ' ' . $a['nom']) . '</b><br><span class="gris">' . h(telephone_lisible($a['telephone'])) . '</span></td><td>' . h($a['praticien'] ? nom_pro(['titre' => $a['titre'], 'nom' => $a['praticien']]) : 'Peu importe') . '</td><td>' . h($a['preference']) . '</td><td>'
            . ($a['statut'] === 'servie' ? badge('Créneau obtenu', 'vert') : badge('En attente', 'bleu')) . '</td><td>'
            . ($a['statut'] === 'active' ? '<form method="post">' . champ_csrf() . '<input type="hidden" name="action" value="retirer"><input type="hidden" name="id" value="' . (int)$a['id'] . '"><button class="lien">Retirer</button></form>' : '') . '</td></tr>';
    }
    echo ($liste ? '' : '<tr><td colspan="6" class="vide-mini">Personne en attente.</td></tr>') . '</tbody></table></div>';
    echo '<section class="carte"><h2>Ajouter un ' . h(mot($c, 'client')) . '</h2><form method="post" class="form grille-form">' . champ_csrf()
        . '<label>Prénom<input name="prenom" required></label><label>Nom<input name="nom"></label><label>Mobile<input name="telephone" type="tel" required></label>'
        . '<label>' . h(mot($c, 'Pro')) . '<select name="praticien"><option value="">Peu importe</option>';
    foreach (praticiens($c) as $p) {
        echo '<option value="' . (int)$p['id'] . '">' . h(nom_pro($p)) . '</option>';
    }
    echo '</select></label><label>Préférence<input name="preference" placeholder="Ex. matin de préférence"></label><button class="btn">Ajouter</button></form></section>';
    pied();
}

function page_appels(): void
{
    $c = clinique_courante();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tel = telephone((string)($_POST['numero'] ?? ''), $c['pays']);
        if ($tel === '') {
            flash('erreur', 'Numéro invalide.');
        } else {
            inserer('INSERT INTO appels (clinique_id, numero, recu_le, source) VALUES (?, ?, ?, ?)', [$c['id'], $tel, iso(maintenant($c) - 5 * 60), 'simulation']);
            evenement($c, 'appel_manque', telephone_lisible($tel));
            $bilan = executer_automatisations($c);
            flash('ok', 'Appel manqué simulé. ' . ($bilan ? implode(' · ', $bilan) : 'Aucun SMS : ce numéro en a déjà reçu un dans les 24 h.'));
        }
        aller('appels');
    }
    $liste = toutes('SELECT * FROM appels WHERE clinique_id = ? ORDER BY recu_le DESC LIMIT 100', [$c['id']]);
    entete('Appels manqués', 'appels');
    titre('Appels manqués', "Chaque appel sans réponse reçoit un SMS avec le lien de réservation, " . (int)reglages($c)['appel_manque']['delai_min'] . " min plus tard.");
    echo '<section class="carte"><h2>Simuler un appel manqué</h2><p class="petit">En production, votre standard téléphonique (Twilio, Ringover, OVH…) signale chaque appel manqué à la plateforme. Ici, saisissez un numéro pour voir la suite.</p>'
        . '<form method="post" class="form ligne">' . champ_csrf() . '<input name="numero" type="tel" value="06 39 98 30 01" aria-label="Numéro"><button class="btn">Simuler</button></form></section>';
    echo '<div class="table"><table><thead><tr><th>Reçu</th><th>Numéro</th><th>SMS</th><th>Résultat</th></tr></thead><tbody>';
    foreach ($liste as $a) {
        $p = une('SELECT prenom, nom FROM patients WHERE clinique_id = ? AND telephone = ?', [$c['id'], $a['numero']]);
        echo '<tr><td>' . h(local($c, $a['recu_le'], 'd/m H:i')) . '</td><td>' . h(telephone_lisible($a['numero'])) . ($p ? '<br><span class="gris">' . h($p['prenom'] . ' ' . $p['nom']) . '</span>' : '') . '</td><td>'
            . ($a['sms_envoye_le'] !== '' ? h(local($c, $a['sms_envoye_le'], 'H:i')) : badge('en attente', 'orange')) . '</td><td>' . ($a['statut'] === 'rdv_pris' ? badge('Rendez-vous pris', 'vert') : badge('Pas de rendez-vous', 'gris')) . '</td></tr>';
    }
    echo ($liste ? '' : '<tr><td colspan="4" class="vide-mini">Aucun appel manqué.</td></tr>') . '</tbody></table></div>';
    pied();
}

function page_messages(): void
{
    $c = clinique_courante();
    $type = array_key_exists($_GET['type'] ?? '', LIBELLES_MESSAGES) ? (string)$_GET['type'] : '';
    $liste = toutes('SELECT * FROM messages WHERE clinique_id = ?' . ($type !== '' ? ' AND type = ?' : '') . ' ORDER BY id DESC LIMIT 60', $type !== '' ? [$c['id'], $type] : [$c['id']]);
    entete('Messages', 'messages');
    titre('Messages envoyés', cfg('mode_envoi') === 'reel' ? 'SMS et e-mails envoyés aux ' . mot($c, 'clients') : 'Tels que les ' . mot($c, 'clients') . ' les recevraient. Les liens sont cliquables : essayez-les.');
    echo '<div class="filtres"><a href="' . h(url('messages')) . '"' . ($type === '' ? ' aria-current="true"' : '') . '>Tous</a>';
    foreach (LIBELLES_MESSAGES as $k => $l) {
        echo '<a href="' . h(url('messages', ['type' => $k])) . '"' . ($type === $k ? ' aria-current="true"' : '') . '>' . h($l) . '</a>';
    }
    echo '</div><div class="telephones grille">';
    foreach ($liste as $m) {
        echo bulle_message($c, $m);
    }
    echo ($liste ? '' : '<p class="vide-mini">Aucun message.</p>') . '</div>';
    pied();
}

/** Champs des questions de réservation du métier (formulaire du secrétariat). */
function champs_questions(array $c): string
{
    $h = '';
    foreach (metier($c)['questions'] as $q) {
        $nom = 'q_' . $q['cle'];
        if ($q['type'] === 'choix') {
            $h .= '<label>' . h($q['libelle']) . '<select name="' . h($nom) . '"><option value="">—</option>';
            foreach ($q['options'] as $o) {
                $h .= '<option>' . h($o) . '</option>';
            }
            $h .= '</select></label>';
        } else {
            $h .= '<label>' . h($q['libelle']) . '<input name="' . h($nom) . '" maxlength="160"' . (!empty($q['requis']) ? ' required' : '') . '></label>';
        }
    }
    return $h;
}

function page_devis(): void
{
    $c = clinique_courante();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'creer') {
            $tel = telephone((string)($_POST['telephone'] ?? ''), $c['pays']);
            $lib = trim((string)($_POST['libelle'] ?? ''));
            $montant = max(0, (float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant'] ?? '0')));
            if (trim((string)($_POST['prenom'] ?? '')) === '' || $tel === '' || $lib === '') {
                flash('erreur', 'Prénom, mobile valide et intitulé du devis sont nécessaires.');
            } else {
                $patient = patient_trouver_ou_creer($c, trim((string)$_POST['prenom']), trim((string)($_POST['nom'] ?? '')), $tel, '', false);
                creer_devis($c, $patient, $lib, $montant);
                journaliser('devis_cree', $lib, (int)$c['id']);
                flash('ok', 'Devis enregistré : il sera relancé automatiquement à J+' . (int)reglages($c)['relance_devis']['premier_j'] . ' puis J+' . (int)reglages($c)['relance_devis']['second_j'] . ' sans réponse.');
            }
        } elseif (in_array($a, ['accepte', 'refuse'], true)) {
            $dv = une('SELECT * FROM devis WHERE id = ? AND clinique_id = ?', [(int)$_POST['id'], $c['id']]);
            if ($dv) {
                repondre_devis($c, $dv, $a === 'accepte');
                flash('ok', $a === 'accepte' ? 'Devis marqué accepté.' : 'Devis marqué refusé.');
            }
        }
        aller('devis');
    }
    $liste = toutes('SELECT d.*, p.prenom, p.nom, p.telephone FROM devis d JOIN patients p ON p.id = d.patient_id WHERE d.clinique_id = ? ORDER BY d.envoye_le DESC LIMIT 200', [$c['id']]);
    $r = reglages($c)['relance_devis'];
    entete('Devis', 'devis');
    titre('Devis', $r['actif'] ? 'Chaque devis sans réponse est relancé par SMS à J+' . (int)$r['premier_j'] . ' puis J+' . (int)$r['second_j'] . '. Le client l\'accepte en un clic.' : 'Relance automatique désactivée (voir « Automatisations »).');
    $etats = ['envoye' => ['En attente', 'bleu'], 'accepte' => ['Accepté', 'vert'], 'refuse' => ['Refusé', 'gris'], 'sans_suite' => ['Sans suite', 'orange']];
    echo '<div class="table"><table><thead><tr><th>Envoyé</th><th>Client</th><th>Devis</th><th>Montant</th><th>Relances</th><th>État</th><th></th></tr></thead><tbody>';
    foreach ($liste as $d) {
        $nbRel = count(array_filter(explode(',', (string)$d['relances'])));
        echo '<tr><td>' . h(local($c, $d['envoye_le'], 'd/m')) . '</td><td><b>' . h($d['prenom'] . ' ' . $d['nom']) . '</b><br><span class="gris">' . h(telephone_lisible($d['telephone'])) . '</span></td><td>' . h($d['libelle']) . '</td>'
            . '<td>' . h(euros((float)$d['montant'])) . '</td><td>' . $nbRel . '</td><td>' . badge($etats[$d['statut']][0] ?? $d['statut'], $etats[$d['statut']][1] ?? '') . '</td><td class="actions-table">';
        if (in_array($d['statut'], ['envoye', 'sans_suite'], true)) {
            foreach (['accepte' => 'Accepté', 'refuse' => 'Refusé'] as $act => $lib) {
                echo '<form method="post">' . champ_csrf() . '<input type="hidden" name="id" value="' . (int)$d['id'] . '"><input type="hidden" name="action" value="' . $act . '"><button class="lien">' . $lib . '</button></form>';
            }
        }
        echo '</td></tr>';
    }
    echo ($liste ? '' : '<tr><td colspan="7" class="vide-mini">Aucun devis.</td></tr>') . '</tbody></table></div>';
    echo '<section class="carte"><h2>Enregistrer un devis envoyé</h2><form method="post" class="form grille-form">' . champ_csrf() . '<input type="hidden" name="action" value="creer">'
        . '<label>Prénom<input name="prenom" required></label><label>Nom<input name="nom"></label><label>Mobile<input name="telephone" type="tel" required></label>'
        . '<label>Intitulé<input name="libelle" required maxlength="120" placeholder="Ex. Devis n° 2026-120"></label><label>Montant (€ HT)<input name="montant" inputmode="decimal"></label><button class="btn">Enregistrer</button></form></section>';
    pied();
}
