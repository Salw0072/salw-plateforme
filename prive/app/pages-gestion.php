<?php
/**
 * Plateforme SALW Santé : automatisations, réglages de la clinique, équipe, cliniques (SALW).
 */

declare(strict_types=1);

/** [clé, titre, explication, paramètres [clé => [libellé, min, max]], texte(s)] */
function descriptif_automatisations(): array
{
    return [
        ['appel_manque', 'Appel manqué → SMS', "Chaque appel sans réponse reçoit un SMS avec le lien de réservation. Un seul SMS par numéro et par 24 h.", ['delai_min' => ['Délai avant le SMS (min)', 0, 120]], ['appel_manque']],
        ['confirmation', 'Confirmation de rendez-vous', 'Envoyée dès la prise de rendez-vous, avec un lien pour confirmer, annuler ou déplacer.', [], ['confirmation']],
        ['rappel_j2', 'Rappel 48 h avant', "Premier rappel, avec les boutons Confirmer / Annuler. Une annulation libère le créneau pour la liste d'attente.", ['heures' => ['Heures avant le rendez-vous', 12, 120]], ['rappel_j2']],
        ['rappel_j1', 'Rappel la veille', "Envoyé seulement si le client n'a pas confirmé (réglable).", ['heures' => ['Heures avant le rendez-vous', 6, 48]], ['rappel_j1']],
        ['rappel_h3', 'Rappel le jour même', 'Court rappel avec l\'adresse, quelques heures avant.', ['heures' => ['Heures avant le rendez-vous', 1, 8]], ['rappel_h3']],
        ['liste_attente', "Liste d'attente automatique", "Un créneau annulé est proposé aux premiers clients en attente ; le premier qui confirme l'obtient.", ['simultanes' => ['Clients prévenus en même temps', 1, 10], 'validite_min' => ['Validité de la proposition (min)', 10, 720], 'marge_min' => ['Pas de proposition à moins de (min) du rendez-vous', 30, 1440]], ['offre_attente']],
        ['avis', 'Demande d\'avis Google', "Même message pour tous les clients après le rendez-vous (Google interdit de ne solliciter que les clients satisfaits). Une fois par an au plus par client.", ['heures_apres' => ['Heures après le rendez-vous', 1, 72], 'intervalle_mois' => ['Pas plus d\'une demande tous les (mois)', 1, 24]], ['avis']],
        ['absence', 'Message après absence', 'Invite le client absent à reprendre rendez-vous, sans reproche.', [], ['absence']],
        ['reactivation', 'Réactivation des clients', "Uniquement les clients qui ont accepté les messages de suivi. Mention STOP obligatoire.", ['mois' => ['Non revus depuis (mois)', 3, 36], 'intervalle_mois' => ['Pas plus d\'un message tous les (mois)', 1, 24]], ['reactivation']],
        ['relance_devis', 'Relance des devis', "Un devis sans réponse est relancé deux fois par SMS ; le client l'accepte en un clic. Sans réponse 14 jours après la seconde relance : « sans suite ».", ['premier_j' => ['Première relance après (jours)', 1, 30], 'second_j' => ['Seconde relance après (jours)', 2, 60]], ['relance_devis']],
    ];
}

function page_automatisations(): void
{
    if (!peut_gerer()) {
        interdit();
    }
    $c = clinique_courante();
    $r = reglages($c);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nouveau = $r;
        foreach (descriptif_automatisations() as [$cle, , , $params, $textes]) {
            $nouveau[$cle]['actif'] = !empty($_POST[$cle . '__actif']);
            foreach ($params as $p => [$lib, $min, $max]) {
                $nouveau[$cle][$p] = max($min, min($max, (int)($_POST[$cle . '__' . $p] ?? $r[$cle][$p])));
            }
            foreach ($textes as $t) {
                $v = trim(str_replace(["\r", "\n"], ' ', (string)($_POST['texte__' . $t] ?? '')));
                $nouveau['textes'][$t] = $v !== '' ? mb_substr($v, 0, 480) : textes_defaut($c)[$t];
            }
        }
        $nouveau['rappel_j1']['si_non_confirme'] = !empty($_POST['rappel_j1__si_non_confirme']);
        $nouveau['avis']['honore_par_defaut'] = !empty($_POST['avis__honore_par_defaut']);
        $nouveau['mentionner_praticien'] = !empty($_POST['mentionner_praticien']);
        if (strpos($nouveau['textes']['reactivation'], 'STOP') === false) {
            $nouveau['textes']['reactivation'] .= ' STOP pour ne plus recevoir ces messages.';
        }
        executer('UPDATE cliniques SET reglages = ? WHERE id = ?', [json_encode($nouveau, JSON_UNESCAPED_UNICODE), $c['id']]);
        journaliser('automatisations_modifiees', '', (int)$c['id']);
        flash('ok', 'Automatisations enregistrées.');
        aller('automatisations');
    }
    entete('Automatisations', 'automatisations');
    titre('Automatisations', 'Ce que la plateforme fait toute seule, et quand. Variables utilisables dans les textes : {prenom} {date} {heure} {structure} {adresse} {praticien} {devis} {lien}.');
    echo '<form method="post" class="form">' . champ_csrf();
    foreach (descriptif_automatisations() as [$cle, $titre, $expl, $params, $textes]) {
        echo '<section class="carte auto"><div class="auto-tete"><label class="interrupteur"><input type="checkbox" name="' . $cle . '__actif" value="1"' . ($r[$cle]['actif'] ? ' checked' : '') . '><span></span></label><div><h2>' . h($titre) . '</h2><p class="petit">' . h($expl) . '</p></div></div>';
        if ($params) {
            echo '<div class="params">';
            foreach ($params as $p => [$lib, $min, $max]) {
                echo '<label>' . h($lib) . '<input type="number" name="' . $cle . '__' . $p . '" value="' . (int)$r[$cle][$p] . '" min="' . $min . '" max="' . $max . '"></label>';
            }
            echo '</div>';
        }
        if ($cle === 'rappel_j1') {
            echo '<label class="case"><input type="checkbox" name="rappel_j1__si_non_confirme" value="1"' . (!empty($r['rappel_j1']['si_non_confirme']) ? ' checked' : '') . '> Seulement si le client n\'a pas encore confirmé</label>';
        }
        if ($cle === 'avis') {
            echo '<label class="case"><input type="checkbox" name="avis__honore_par_defaut" value="1"' . (!empty($r['avis']['honore_par_defaut']) ? ' checked' : '') . '> Considérer un rendez-vous passé comme honoré, sauf absence signalée</label>'
                . ($c['avis_url'] === '' ? '<p class="msg erreur">Lien Google à renseigner dans « ' . h(mot($c, 'Structure')) . ' » pour activer les demandes d\'avis.</p>' : '');
        }
        foreach ($textes as $t) {
            $v = (string)$r['textes'][$t];
            echo '<label>Texte<textarea name="texte__' . $t . '" rows="2" maxlength="480" data-compteur>' . h($v) . '</textarea><small class="compteur">' . mb_strlen($v) . ' caractères · ' . segments_sms($v) . ' SMS (hors lien)</small></label>';
        }
        echo '</section>';
    }
    echo '<section class="carte"><label class="case"><input type="checkbox" name="mentionner_praticien" value="1"' . ($r['mentionner_praticien'] ? ' checked' : '') . '> Mentionner ' . h(mot($c, 'pro') === 'poste' ? 'le poste' : 'le ' . mot($c, 'pro')) . ' dans les SMS (déconseillé : un SMS peut être lu par un proche)</label></section>'
        . '<button class="btn plein">Enregistrer</button></form>';
    pied();
}

/** Horaires « 1: 09:00-12:00, 14:00-18:00 » (une ligne par jour, 1 = lundi) <-> JSON. */
function horaires_texte(string $json): string
{
    $h = json_decode($json, true) ?: [];
    $l = [];
    foreach ($h as $j => $ps) {
        $l[] = $j . ': ' . implode(', ', array_map(function ($p) { return $p[0] . '-' . $p[1]; }, $ps));
    }
    return implode("\n", $l);
}

function horaires_json(string $texte): ?string
{
    $h = [];
    foreach (preg_split('/\r?\n/', trim($texte)) as $ligne) {
        if (trim($ligne) === '') continue;
        if (!preg_match('/^\s*([1-7])\s*:\s*(.+)$/', $ligne, $m)) return null;
        foreach (explode(',', $m[2]) as $pl) {
            if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $pl, $x) || sprintf('%02d:%s', $x[3], $x[4]) <= sprintf('%02d:%s', $x[1], $x[2])) return null;
            $h[$m[1]][] = [sprintf('%02d:%s', $x[1], $x[2]), sprintf('%02d:%s', $x[3], $x[4])];
        }
    }
    return json_encode($h);
}

function page_clinique(): void
{
    if (!peut_gerer()) {
        interdit();
    }
    $c = clinique_courante();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'infos') {
            $avis = trim((string)($_POST['avis_url'] ?? ''));
            executer('UPDATE cliniques SET nom = ?, adresse = ?, ville = ?, pays = ?, telephone = ?, email = ?, avis_url = ?, valeur_consultation = ?, faq = ? WHERE id = ?', [
                mb_substr(trim((string)$_POST['nom']), 0, 120) ?: $c['nom'], mb_substr(trim((string)$_POST['adresse']), 0, 160), mb_substr(trim((string)$_POST['ville']), 0, 80),
                in_array($_POST['pays'] ?? '', ['FR', 'BE'], true) ? $_POST['pays'] : 'FR', telephone((string)$_POST['telephone'], (string)($_POST['pays'] ?? 'FR')),
                filter_var(trim((string)$_POST['email']), FILTER_VALIDATE_EMAIL) ?: '', preg_match('#^https://#', $avis) ? $avis : '',
                max(0, (float)str_replace(',', '.', (string)$_POST['valeur'])), mb_substr(str_replace("\r", '', (string)$_POST['faq']), 0, 20000), $c['id']]);
            flash('ok', 'Informations enregistrées.');
        } elseif ($a === 'praticien') {
            $json = horaires_json((string)($_POST['horaires'] ?? ''));
            if ($json === null || trim((string)($_POST['nom'] ?? '')) === '') {
                flash('erreur', 'Nom et horaires valides nécessaires (ex. « 1: 09:00-12:00, 14:00-18:00 », une ligne par jour, 1 = lundi).');
            } elseif ((int)($_POST['id'] ?? 0)) {
                executer('UPDATE praticiens SET nom = ?, titre = ?, horaires = ?, actif = ? WHERE id = ? AND clinique_id = ?', [trim((string)$_POST['nom']), trim((string)$_POST['titre']), $json, !empty($_POST['actif']) ? 1 : 0, (int)$_POST['id'], $c['id']]);
                flash('ok', mot($c, 'Pro') . ' enregistré.');
            } else {
                inserer('INSERT INTO praticiens (clinique_id, nom, titre, horaires) VALUES (?, ?, ?, ?)', [$c['id'], trim((string)$_POST['nom']), trim((string)$_POST['titre']), $json]);
                flash('ok', mot($c, 'Pro') . ' ajouté.');
            }
        } elseif ($a === 'type') {
            $d = max(5, min(240, (int)($_POST['duree'] ?? 20)));
            if (trim((string)($_POST['libelle'] ?? '')) === '') {
                flash('erreur', 'Libellé nécessaire.');
            } elseif ((int)($_POST['id'] ?? 0)) {
                executer('UPDATE types_rdv SET libelle = ?, duree = ?, en_ligne = ?, actif = ? WHERE id = ? AND clinique_id = ?', [trim((string)$_POST['libelle']), $d, !empty($_POST['en_ligne']) ? 1 : 0, !empty($_POST['actif']) ? 1 : 0, (int)$_POST['id'], $c['id']]);
                flash('ok', 'Type enregistré.');
            } else {
                inserer('INSERT INTO types_rdv (clinique_id, libelle, duree, en_ligne) VALUES (?, ?, ?, ?)', [$c['id'], trim((string)$_POST['libelle']), $d, !empty($_POST['en_ligne']) ? 1 : 0]);
                flash('ok', 'Type ajouté.');
            }
        } elseif ($a === 'fermer' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['jour'] ?? ''))) {
            inserer('INSERT INTO fermetures (clinique_id, jour, praticien_id) VALUES (?, ?, ?)', [$c['id'], $_POST['jour'], ((int)($_POST['praticien'] ?? 0)) ?: null]);
            flash('ok', 'Jour fermé à la réservation.');
        } elseif ($a === 'rouvrir') {
            executer('DELETE FROM fermetures WHERE id = ? AND clinique_id = ?', [(int)$_POST['id'], $c['id']]);
            flash('ok', 'Jour rouvert.');
        }
        journaliser('clinique_' . $a, '', (int)$c['id']);
        aller('clinique');
    }
    $c = clinique((int)$c['id']);
    entete(mot($c, 'Structure'), 'clinique');
    titre(mot($c, 'Structure'), metier($c)['libelle'] . ' · informations, ' . mot($c, 'pros') . ', types de rendez-vous et questions fréquentes (utilisées par l\'assistant).');
    echo '<section class="carte"><h2>Informations</h2><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="infos"><div class="champ-duo">'
        . '<label>Nom<input name="nom" value="' . h($c['nom']) . '" required></label><label>Téléphone<input name="telephone" value="' . h(telephone_lisible($c['telephone'])) . '"></label></div>'
        . '<div class="champ-duo"><label>Adresse<input name="adresse" value="' . h($c['adresse']) . '"></label><label>Code postal et ville<input name="ville" value="' . h($c['ville']) . '"></label></div>'
        . '<div class="champ-duo"><label>Pays<select name="pays"><option value="FR"' . ($c['pays'] === 'FR' ? ' selected' : '') . '>France</option><option value="BE"' . ($c['pays'] === 'BE' ? ' selected' : '') . '>Belgique</option></select></label>'
        . '<label>E-mail<input name="email" type="email" value="' . h($c['email']) . '"></label></div>'
        . '<div class="champ-duo"><label>Lien « laisser un avis » Google<input name="avis_url" value="' . h($c['avis_url']) . '" placeholder="https://g.page/r/…/review"><small>Google Business Profile › Demander des avis</small></label>'
        . '<label>Valeur moyenne d\'un rendez-vous (€)<input name="valeur" inputmode="decimal" value="' . h((string)(float)$c['valeur_consultation']) . '"><small>Sert à chiffrer le chiffre d\'affaires récupéré.</small></label></div>'
        . '<label>Questions fréquentes (assistant des clients)<textarea name="faq" rows="12" class="mono">' . h($c['faq']) . '</textarea><small>Un bloc par question : une ligne de titre, puis la réponse, et une ligne vide entre deux blocs. Pas d\'information confidentielle.</small></label>'
        . '<button class="btn">Enregistrer</button></form></section>';

    echo '<div class="grille-2"><section class="carte"><h2>' . h(ucfirst(mot($c, 'pros'))) . '</h2>';
    foreach (praticiens($c, false) as $p) {
        echo '<details class="bloc"><summary><b>' . h(nom_pro($p)) . '</b>' . ((int)$p['actif'] ? '' : ' ' . badge('inactif', 'gris')) . '</summary>' . form_praticien($p, $c) . '</details>';
    }
    echo '<details class="bloc"><summary>+ Ajouter : ' . h(mot($c, 'pro')) . '</summary>' . form_praticien(null, $c) . '</details></section>';
    echo '<section class="carte"><h2>Types de rendez-vous</h2>';
    foreach (toutes('SELECT * FROM types_rdv WHERE clinique_id = ? ORDER BY libelle', [$c['id']]) as $t) {
        echo '<details class="bloc"><summary><b>' . h($t['libelle']) . '</b> · ' . (int)$t['duree'] . ' min' . ((int)$t['en_ligne'] ? '' : ' ' . badge('secrétariat seulement', 'gris')) . '</summary>' . form_type($t) . '</details>';
    }
    echo '<details class="bloc"><summary>+ Ajouter un type</summary>' . form_type(null) . '</details>';
    echo '<h2 class="sep">Jours fermés</h2><form method="post" class="form ligne">' . champ_csrf() . '<input type="hidden" name="action" value="fermer"><input type="date" name="jour" required><select name="praticien"><option value="">Toute la structure</option>';
    foreach (praticiens($c) as $p) {
        echo '<option value="' . (int)$p['id'] . '">' . h($p['nom']) . '</option>';
    }
    echo '</select><button class="btn contour">Fermer</button></form><ul class="liste-simple">';
    foreach (toutes('SELECT f.*, p.nom FROM fermetures f LEFT JOIN praticiens p ON p.id = f.praticien_id WHERE f.clinique_id = ? ORDER BY jour', [$c['id']]) as $f) {
        echo '<li>' . h(date('d/m/Y', strtotime($f['jour']))) . ' · ' . h($f['nom'] ? $f['nom'] : 'toute la structure') . ' <form method="post" class="inline">' . champ_csrf() . '<input type="hidden" name="action" value="rouvrir"><input type="hidden" name="id" value="' . (int)$f['id'] . '"><button class="lien">rouvrir</button></form></li>';
    }
    echo '</ul></section></div>';
    pied();
}

function form_praticien(?array $p, array $c): string
{
    return '<form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="praticien"><input type="hidden" name="id" value="' . (int)($p['id'] ?? 0) . '">'
        . '<div class="champ-duo"><label>Titre (Dr, Me…, facultatif)<input name="titre" value="' . h($p['titre'] ?? metier($c)['titre']) . '"></label><label>Nom<input name="nom" value="' . h($p['nom'] ?? '') . '" required></label></div>'
        . '<label>Horaires de rendez-vous<textarea name="horaires" rows="5" class="mono" placeholder="1: 09:00-12:00, 14:00-18:00">' . h(horaires_texte($p['horaires'] ?? '{}')) . '</textarea><small>Une ligne par jour (1 = lundi … 7 = dimanche).</small></label>'
        . ($p ? '<label class="case"><input type="checkbox" name="actif" value="1"' . ((int)$p['actif'] ? ' checked' : '') . '> Actif</label>' : '') . '<button class="btn contour">Enregistrer</button></form>';
}

function form_type(?array $t): string
{
    return '<form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="type"><input type="hidden" name="id" value="' . (int)($t['id'] ?? 0) . '">'
        . '<div class="champ-duo"><label>Libellé<input name="libelle" value="' . h($t['libelle'] ?? '') . '" required></label><label>Durée (min)<input type="number" name="duree" value="' . (int)($t['duree'] ?? 20) . '" min="5" max="240"></label></div>'
        . '<label class="case"><input type="checkbox" name="en_ligne" value="1"' . ((int)($t['en_ligne'] ?? 1) ? ' checked' : '') . '> Réservable en ligne par les clients</label>'
        . ($t ? '<label class="case"><input type="checkbox" name="actif" value="1"' . ((int)$t['actif'] ? ' checked' : '') . '> Actif</label>' : '') . '<button class="btn contour">Enregistrer</button></form>';
}

function mdp_temporaire(): string
{
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 16; $i++) {
        $s .= $a[random_int(0, strlen($a) - 1)];
    }
    return $s;
}

function page_equipe(): void
{
    if (!peut_gerer()) {
        interdit();
    }
    $c = clinique_courante();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'creer') {
            $role = in_array($_POST['role'] ?? '', ['responsable', 'secretariat'], true) ? $_POST['role'] : 'secretariat';
            $mdp = mdp_temporaire();
            [$ok, $res] = creer_compte((string)$_POST['identifiant'], (string)$_POST['nom'], $role, $mdp, (int)$c['id'], true);
            if ($ok) {
                $_SESSION['mdp_temp'] = [$res['identifiant'], $mdp];
                journaliser('compte_cree', $res['identifiant'], (int)$c['id']);
            }
            flash($ok ? 'ok' : 'erreur', $ok ? 'Compte créé.' : $res);
        } elseif ($a === 'desactiver' || $a === 'activer') {
            executer('UPDATE utilisateurs SET actif = ? WHERE id = ? AND clinique_id = ? AND id != ?', [$a === 'activer' ? 1 : 0, (int)$_POST['id'], $c['id'], moi()['id']]);
            flash('ok', $a === 'activer' ? 'Compte réactivé.' : 'Compte désactivé.');
        } elseif ($a === 'reinitialiser') {
            $u = une('SELECT * FROM utilisateurs WHERE id = ? AND clinique_id = ?', [(int)$_POST['id'], $c['id']]);
            if ($u) {
                $mdp = mdp_temporaire();
                executer('UPDATE utilisateurs SET hash = ?, changer_mdp = 1, echecs = 0, bloque_jusqua = 0 WHERE id = ?', [password_hash($mdp, PASSWORD_DEFAULT), $u['id']]);
                $_SESSION['mdp_temp'] = [$u['identifiant'], $mdp];
                journaliser('mdp_reinitialise', $u['identifiant'], (int)$c['id']);
            }
        }
        aller('equipe');
    }
    entete('Équipe', 'equipe');
    titre('Équipe', 'Comptes de ' . $c['nom']);
    if (!empty($_SESSION['mdp_temp'])) {
        [$i, $m] = $_SESSION['mdp_temp'];
        unset($_SESSION['mdp_temp']);
        echo '<div class="msg info"><b>Mot de passe temporaire de ' . h($i) . ' :</b> <code>' . h($m) . '</code><br>Affiché une seule fois. À changer à la première connexion.</div>';
    }
    echo '<div class="table"><table><thead><tr><th>Compte</th><th>Rôle</th><th>Dernière connexion</th><th></th></tr></thead><tbody>';
    foreach (toutes('SELECT * FROM utilisateurs WHERE clinique_id = ? ORDER BY nom', [$c['id']]) as $u) {
        echo '<tr' . ((int)$u['actif'] ? '' : ' class="attenuee"') . '><td><b>' . h($u['nom']) . '</b><br><span class="gris">' . h($u['identifiant']) . '</span></td><td>' . h(ROLES[$u['role']]) . (empty($u['totp']) ? '' : ' ' . badge('double auth.', 'vert')) . '</td><td>' . h($u['derniere_connexion'] ? date('d/m/Y H:i', strtotime($u['derniere_connexion'])) : 'jamais') . '</td><td class="actions-table">'
            . '<form method="post">' . champ_csrf() . '<input type="hidden" name="id" value="' . (int)$u['id'] . '"><input type="hidden" name="action" value="' . ((int)$u['actif'] ? 'desactiver' : 'activer') . '"><button class="lien">' . ((int)$u['actif'] ? 'Désactiver' : 'Réactiver') . '</button></form>'
            . '<form method="post">' . champ_csrf() . '<input type="hidden" name="id" value="' . (int)$u['id'] . '"><input type="hidden" name="action" value="reinitialiser"><button class="lien">Nouveau mot de passe</button></form></td></tr>';
    }
    echo '</tbody></table></div><section class="carte"><h2>Ajouter un membre</h2><form method="post" class="form grille-form">' . champ_csrf() . '<input type="hidden" name="action" value="creer">'
        . '<label>Nom<input name="nom" required></label><label>E-mail<input name="identifiant" type="email" required></label><label>Rôle<select name="role"><option value="secretariat">Secrétariat</option><option value="responsable">Responsable</option></select></label><button class="btn">Créer</button></form></section>';
    pied();
}

function page_cliniques(): void
{
    if (!est_salw()) {
        interdit();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'choisir') {
            $_SESSION['clinique_id'] = (int)$_POST['id'];
            aller(($_POST['vers'] ?? '') === 'abonnement' ? 'abonnement' : 'tableau');
        } elseif ($a === 'demo') {
            $m = array_key_exists($_POST['metier'] ?? '', metiers()) ? (string)$_POST['metier'] : 'sante';
            $c = creer_demo($m);
            unset($_SESSION['scenario'][$c['id']]);
            $_SESSION['clinique_id'] = (int)$c['id'];
            journaliser('demo_reinitialisee', $m);
            flash('ok', 'Démonstration « ' . metiers()[$m]['libelle'] . ' » prête.');
            aller('demo');
        } elseif ($a === 'creer') {
            $nom = trim((string)($_POST['nom'] ?? ''));
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $nom) ?: $nom)), '-');
            if ($nom === '' || $slug === '' || clinique_par_slug($slug)) {
                flash('erreur', 'Nom manquant ou déjà utilisé.');
            } else {
                $m = array_key_exists($_POST['metier'] ?? '', metiers()) ? (string)$_POST['metier'] : 'sante';
                [$formule, $engagement, $prixNegocie, $offerte] = lire_offre($_POST);
                $id = inserer('INSERT INTO cliniques (slug, nom, pays, fuseau, metier, formule, engagement, prix_negocie, mise_en_place_offerte, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$slug, $nom, $_POST['pays'] === 'BE' ? 'BE' : 'FR', $_POST['pays'] === 'BE' ? 'Europe/Brussels' : 'Europe/Paris', $m, $formule, $engagement, $prixNegocie, $offerte, iso(time())]);
                // Types de rendez-vous et FAQ du modèle du métier, à adapter.
                foreach (metiers()[$m]['demo']['types'] as [$lib, $duree, $enLigne]) {
                    inserer('INSERT INTO types_rdv (clinique_id, libelle, duree, en_ligne) VALUES (?, ?, ?, ?)', [$id, $lib, $duree, $enLigne]);
                }
                $_SESSION['clinique_id'] = $id;
                journaliser('clinique_creee', $nom . ' (' . $m . ', ' . libelle_formule($formule) . ')', $id);
                $c = clinique($id);
                $p = prix_mensuel($c);
                flash('ok', 'Client créé (' . metiers()[$m]['libelle'] . ', offre ' . libelle_formule($formule) . ($p > 0 ? ' à ' . montant($p) . ' HT par mois' : ', prix à fixer') . ') : ajoutez ses ' . metiers()[$m]['mots']['pros'] . ' et leurs horaires.');
                aller('clinique');
            }
        }
        aller('cliniques');
    }
    entete('Clients', 'cliniques');
    titre('Clients de la plateforme', 'Toutes les structures opérées par SALW sur cette installation, tous métiers confondus.');
    // Revenu mensuel récurrent : clients réels et actifs seulement.
    $clients = toutes('SELECT * FROM cliniques ORDER BY demo, metier, nom');
    $mrr = 0.0;
    $nbReels = 0;
    foreach ($clients as $x) {
        if (!(int)$x['demo'] && (int)$x['actif']) {
            $mrr += prix_mensuel($x);
            $nbReels++;
        }
    }
    echo '<div class="tuiles">' . tuile('Revenu mensuel récurrent', montant($mrr), $nbReels . ' client(s) réel(s), démonstrations exclues') . tuile('Sur 12 mois', euros($mrr * 12), 'à abonnements constants, hors mises en place et SMS supplémentaires') . '</div>';
    echo '<div class="table"><table><thead><tr><th>Client</th><th>Métier</th><th>Formule</th><th>Par mois</th><th>Paiement</th><th>SMS du mois</th><th>Rendez-vous (30 j)</th><th></th></tr></thead><tbody>';
    foreach ($clients as $c) {
        $d = iso(maintenant($c) - 30 * 86400);
        $k = consommation($c);
        $p = prix_mensuel($c);
        echo '<tr><td><b>' . h($c['nom']) . '</b>' . ((int)$c['demo'] ? ' ' . badge('démonstration', 'orange') : '') . '<br><span class="gris">' . h(lien_reservation($c)) . '</span></td><td>' . h(metier($c)['libelle']) . ' <span class="gris">· ' . h($c['pays']) . '</span></td>'
            . '<td>' . h(libelle_formule($k['formule'])) . ((float)$c['prix_negocie'] > 0 ? ' ' . badge('négocié', 'bleu') : '') . '</td><td>' . ($p > 0 ? h(montant($p)) : 'à fixer') . '</td>'
            . '<td>' . ((int)$c['demo'] ? '<span class="gris">démo</span>' : badge(STATUTS_PAIEMENT[$c['paiement_statut']] ?? $c['paiement_statut'], ['actif' => 'vert', 'impaye' => 'rouge', 'en_attente' => 'orange', 'essai' => 'bleu'][$c['paiement_statut']] ?? 'gris')
                . ($c['paiement_fournisseur'] !== '' && $c['paiement_statut'] !== '' ? ' <span class="gris">' . h(ucfirst($c['paiement_fournisseur'])) . '</span>' : '')) . '</td>'
            . '<td>' . $k['sms'] . ($k['quota'] ? ' / ' . $k['quota'] . ($k['sms'] > $k['quota'] ? ' ' . badge('dépassé', 'rouge') : '') : '') . '</td>'
            . '<td>' . (int)valeur('SELECT COUNT(*) FROM rdv WHERE clinique_id = ? AND cree_le > ?', [$c['id'], $d]) . '</td>'
            . '<td><div class="deux-boutons"><form method="post">' . champ_csrf() . '<input type="hidden" name="action" value="choisir"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><button class="btn contour">Ouvrir</button></form>'
            . '<form method="post">' . champ_csrf() . '<input type="hidden" name="action" value="choisir"><input type="hidden" name="vers" value="abonnement"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><button class="lien">Offre</button></form></div></td></tr>';
    }
    echo '</tbody></table></div><section class="carte"><h2>Nouveau client</h2><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="creer">'
        . '<div class="champ-trio"><label>Nom<input name="nom" required></label><label>Métier' . select_metier() . '</label><label>Pays<select name="pays"><option value="FR">France</option><option value="BE">Belgique</option></select></label></div>'
        . cartes_formules('sante', 'essentiel') . champs_engagement(1, 0.0)
        . '<button class="btn">Créer le client</button></form></section>'
        . '<div class="grille-2"><section class="carte"><h2>Démonstrations</h2><p>Recrée la structure fictive d\'un métier, avec 60 jours d\'historique, et remet son horloge à zéro.</p><form method="post" class="form" data-confirmer="Recréer cette démonstration ? Ses données actuelles seront effacées.">' . champ_csrf() . '<input type="hidden" name="action" value="demo"><label>Métier' . select_metier() . '</label><button class="btn contour">Recréer la démonstration</button></form></section></div>';
    echo '<section class="carte"><h2>Journal</h2><ul class="liste-simple">';
    foreach (toutes('SELECT * FROM journal ORDER BY id DESC LIMIT 40') as $j) {
        echo '<li><span class="gris">' . h(date('d/m H:i', strtotime($j['t']))) . '</span> ' . h($j['utilisateur'] ?: '—') . ' · ' . h($j['action']) . ($j['detail'] !== '' ? ' · ' . h($j['detail']) : '') . '</li>';
    }
    echo '</ul></section>';
    pied();
}

function select_metier(): string
{
    $h = '<select name="metier">';
    foreach (metiers() as $k => $m) {
        $h .= '<option value="' . h($k) . '">' . h($m['libelle']) . '</option>';
    }
    return $h . '</select>';
}
