<?php
/**
 * Plateforme SALW Santé : installation, connexion, compte, tableau de bord.
 */

declare(strict_types=1);

/** Premier lancement : le premier compte est un compte SALW. Page fermée dès qu'il existe. */
function page_installation(): void
{
    $erreur = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ((string)($_POST['mdp'] ?? '') !== (string)($_POST['mdp2'] ?? '')) {
            $erreur = 'Les deux mots de passe ne correspondent pas.';
        } else {
            [$ok, $res] = creer_compte((string)($_POST['identifiant'] ?? ''), (string)($_POST['nom'] ?? ''), 'salw', (string)($_POST['mdp'] ?? ''), null, false);
            if ($ok) {
                if (!empty($_POST['demo'])) {
                    creer_toutes_demos();
                    $_SESSION['clinique_id'] = (int)clinique_par_slug(slug_demo('sante'))['id'];
                }
                ouvrir_session($res);
                journaliser('installation', $res['identifiant']);
                flash('ok', 'Compte créé.' . (!empty($_POST['demo']) ? ' Les démonstrations des 7 métiers sont prêtes : ouvrez « Démonstration ».' : ''));
                aller(!empty($_POST['demo']) ? 'demo' : 'cliniques');
            }
            $erreur = $res;
        }
    }
    entete('Installation', '');
    echo '<div class="carte-seule"><div class="marque">SALW <span>PLATEFORME</span></div><h1>Créer votre compte</h1><p>Premier lancement : ce compte SALW aura accès à tous les clients de la plateforme.</p>'
        . ($erreur !== '' ? '<div class="msg erreur">' . h($erreur) . '</div>' : '')
        . '<form method="post" class="form">' . champ_csrf()
        . '<label>Votre nom<input name="nom" required maxlength="80" autocomplete="name"></label>'
        . '<label>Adresse e-mail (identifiant)<input name="identifiant" type="email" required autocomplete="username"></label>'
        . '<label>Mot de passe (12 caractères minimum)<input name="mdp" type="password" required minlength="12" autocomplete="new-password"></label>'
        . '<label>Confirmer le mot de passe<input name="mdp2" type="password" required minlength="12" autocomplete="new-password"></label>'
        . '<label class="case"><input type="checkbox" name="demo" value="1" checked> Créer les démonstrations des 7 métiers (données fictives)</label>'
        . '<button class="btn plein">Créer le compte</button></form></div>';
    pied();
}

function page_connexion(): void
{
    $erreur = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        [$statut, $erreur] = isset($_POST['code']) ? valider_totp((string)$_POST['code']) : tenter_connexion((string)($_POST['identifiant'] ?? ''), (string)($_POST['mdp'] ?? ''));
        if ($statut === 'ok') {
            aller('tableau');
        }
    }
    entete('Connexion', '');
    echo '<div class="carte-seule"><div class="marque">SALW <span>PLATEFORME</span></div>';
    if (!empty($_SESSION['flash'])) {
        [$t, $m] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="msg ' . h($t) . '">' . h($m) . '</div>';
    }
    echo $erreur !== '' ? '<div class="msg erreur" role="alert">' . h($erreur) . '</div>' : '';
    if (isset($_SESSION['en_attente'])) {
        echo '<h1>Code de vérification</h1><form method="post" class="form" action="' . h(url('connexion')) . '">' . champ_csrf()
            . '<label>Code à 6 chiffres de votre application<input name="code" inputmode="numeric" maxlength="7" required autofocus autocomplete="one-time-code"></label><button class="btn plein">Valider</button></form>';
    } else {
        echo '<h1>Connexion</h1><form method="post" class="form" action="' . h(url('connexion')) . '">' . champ_csrf()
            . '<label>Adresse e-mail<input name="identifiant" type="email" required autofocus autocomplete="username"></label>'
            . '<label>Mot de passe<input name="mdp" type="password" required autocomplete="current-password"></label><button class="btn plein">Se connecter</button></form>'
            . '<p class="petit"><a href="' . h(url('oubli')) . '">Mot de passe oublié ?</a></p>';
    }
    echo '</div>';
    pied();
}

// --- Mot de passe oublié ------------------------------------------------------------------
// Lien envoyé à l'adresse du compte, valable 1 heure et utilisable une seule fois. Seule son
// empreinte (SHA-256) est conservée. La réponse est la même, que l'adresse existe ou non.

const REINIT_DUREE = 3600;

function page_oubli(): void
{
    $envoye = false;
    $erreur = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Cinq demandes par heure et par adresse IP.
        $cle = 'oubli:' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')) . ':' . gmdate('YmdH');
        db()->prepare('INSERT INTO compteurs (cle, valeur, maj) VALUES (?, 1, ?) ON CONFLICT(cle) DO UPDATE SET valeur = valeur + 1')->execute([$cle, gmdate('Y-m-d')]);
        if ((int)valeur('SELECT valeur FROM compteurs WHERE cle = ?', [$cle]) > 5) {
            $erreur = 'Trop de demandes depuis votre connexion : réessayez dans une heure.';
        } else {
            $u = une('SELECT * FROM utilisateurs WHERE identifiant = ? AND actif = 1', [mb_strtolower(trim((string)($_POST['identifiant'] ?? '')))]);
            if ($u) {
                $jeton = bin2hex(random_bytes(32));
                executer('UPDATE utilisateurs SET reinit_empreinte = ?, reinit_expire = ? WHERE id = ?', [hash('sha256', $jeton), time() + REINIT_DUREE, $u['id']]);
                $lien = url_base() . '/app/?p=nouveaumdp&t=' . $jeton;
                $voie = envoyer_mail_compte($u['identifiant'], 'Réinitialisation de votre mot de passe · Plateforme SALW',
                    'Bonjour ' . ($u['nom'] ?: '') . ",\n\nPour choisir un nouveau mot de passe pour la Plateforme SALW, ouvrez ce lien :\n\n" . $lien
                    . "\n\nIl est valable 1 heure et ne sert qu'une fois.\n\nSi vous n'avez rien demandé, ignorez ce message : votre mot de passe actuel reste valable.\n\nSALW CONSULTING");
                journaliser('mdp_oubli_' . $voie, $u['identifiant'], $u['clinique_id'] !== null ? (int)$u['clinique_id'] : null);
            }
            $envoye = true;
        }
    }
    entete('Mot de passe oublié', '');
    echo '<div class="carte-seule"><div class="marque">SALW <span>PLATEFORME</span></div><h1>Mot de passe oublié</h1>';
    if ($envoye) {
        echo '<div class="msg ok" role="status">Si un compte correspond à cette adresse, un e-mail avec un lien de réinitialisation vient de partir. Il est valable 1 heure. Pensez à regarder dans les indésirables.</div>';
    } else {
        echo ($erreur !== '' ? '<div class="msg erreur" role="alert">' . h($erreur) . '</div>' : '')
            . '<p>Saisissez l\'adresse e-mail de votre compte : vous recevrez un lien pour choisir un nouveau mot de passe.</p>'
            . '<form method="post" class="form" action="' . h(url('oubli')) . '">' . champ_csrf()
            . '<label>Adresse e-mail<input name="identifiant" type="email" required autofocus autocomplete="username"></label>'
            . '<button class="btn plein">Recevoir le lien</button></form>';
    }
    echo '<p class="petit"><a href="' . h(url('connexion')) . '">Retour à la connexion</a></p></div>';
    pied();
}

function compte_par_jeton(string $jeton): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $jeton)) {
        return null;
    }
    $u = une("SELECT * FROM utilisateurs WHERE reinit_empreinte = ? AND reinit_empreinte != '' AND reinit_expire > ? AND actif = 1", [hash('sha256', $jeton), time()]);
    return $u ?: null;
}

function page_nouveau_mdp(): void
{
    $jeton = (string)($_POST['t'] ?? $_GET['t'] ?? '');
    $compte = compte_par_jeton($jeton);
    $erreur = '';
    if ($compte && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $mdp = (string)($_POST['mdp'] ?? '');
        if (mb_strlen($mdp) < 12) {
            $erreur = 'Le mot de passe doit faire au moins 12 caractères.';
        } elseif ($mdp !== (string)($_POST['mdp2'] ?? '')) {
            $erreur = 'Les deux saisies ne correspondent pas.';
        } else {
            executer("UPDATE utilisateurs SET hash = ?, reinit_empreinte = '', reinit_expire = 0, echecs = 0, bloque_jusqua = 0, changer_mdp = 0 WHERE id = ?", [password_hash($mdp, PASSWORD_DEFAULT), $compte['id']]);
            journaliser('mdp_reinitialise_par_lien', $compte['identifiant'], $compte['clinique_id'] !== null ? (int)$compte['clinique_id'] : null);
            flash('ok', 'Mot de passe enregistré : connectez-vous avec le nouveau.');
            aller('connexion');
        }
    }
    entete('Nouveau mot de passe', '');
    echo '<div class="carte-seule"><div class="marque">SALW <span>PLATEFORME</span></div><h1>Nouveau mot de passe</h1>';
    if (!$compte) {
        echo '<div class="msg erreur" role="alert">Ce lien n\'est plus valable : il a expiré ou a déjà servi.</div>'
            . '<p><a class="btn plein" href="' . h(url('oubli')) . '">Demander un nouveau lien</a></p>';
    } else {
        echo ($erreur !== '' ? '<div class="msg erreur" role="alert">' . h($erreur) . '</div>' : '')
            . '<p>Compte : <b>' . h($compte['identifiant']) . '</b></p>'
            . '<form method="post" class="form" action="' . h(url('nouveaumdp')) . '">' . champ_csrf() . '<input type="hidden" name="t" value="' . h($jeton) . '">'
            . '<input type="text" name="identifiant" value="' . h($compte['identifiant']) . '" autocomplete="username" hidden>'
            . '<label>Nouveau mot de passe (12 caractères minimum)<input name="mdp" type="password" required minlength="12" autofocus autocomplete="new-password"></label>'
            . '<label>Confirmer<input name="mdp2" type="password" required minlength="12" autocomplete="new-password"></label>'
            . '<button class="btn plein">Enregistrer</button></form>';
    }
    echo '</div>';
    pied();
}

/**
 * E-mail de compte (mot de passe oublié). Il part toujours, même en mode simulation : il sert à
 * l'équipe, pas aux clients. Si l'envoi échoue (poste local sans serveur de messagerie), le message
 * est déposé dans prive/donnees/boite-test/, inaccessible depuis le web. Renvoie 'envoye' ou 'depose'.
 */
function envoyer_mail_compte(string $a, string $sujet, string $texte): string
{
    $exp = (string)cfg('email_expediteur');
    $entetes = "From: =?UTF-8?B?" . base64_encode('SALW CONSULTING') . "?= <{$exp}>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    if (@mail($a, '=?UTF-8?B?' . base64_encode($sujet) . '?=', chunk_split(base64_encode($texte)), $entetes)) {
        return 'envoye';
    }
    $dossier = dirname(__DIR__) . '/donnees/boite-test';
    if (!is_dir($dossier)) {
        @mkdir($dossier, 0750, true);
    }
    @file_put_contents($dossier . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.txt', "À : {$a}\nObjet : {$sujet}\n\n{$texte}\n");
    return 'depose';
}

function page_compte(): void
{
    $u = moi();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'mdp') {
            $n = (string)($_POST['nouveau'] ?? '');
            if (!password_verify((string)($_POST['actuel'] ?? ''), $u['hash'])) {
                flash('erreur', 'Mot de passe actuel incorrect.');
            } elseif (mb_strlen($n) < 12 || $n !== (string)($_POST['confirmation'] ?? '')) {
                flash('erreur', 'Nouveau mot de passe : 12 caractères minimum, saisi deux fois à l\'identique.');
            } else {
                executer('UPDATE utilisateurs SET hash = ?, changer_mdp = 0 WHERE id = ?', [password_hash($n, PASSWORD_DEFAULT), $u['id']]);
                journaliser('mot_de_passe_change', $u['identifiant'], $u['clinique_id'] !== null ? (int)$u['clinique_id'] : null);
                flash('ok', 'Mot de passe modifié.');
            }
        } elseif ($a === 'totp_preparer') {
            $_SESSION['totp_nouveau'] = base32_encoder(random_bytes(20));
        } elseif ($a === 'totp_activer') {
            $s = (string)($_SESSION['totp_nouveau'] ?? '');
            $pas = totp_verifier($s, (string)($_POST['code'] ?? ''), 0);
            if (!$pas) {
                flash('erreur', 'Code incorrect.');
            } else {
                executer('UPDATE utilisateurs SET totp = ?, totp_dernier = ? WHERE id = ?', [$s, $pas, $u['id']]);
                unset($_SESSION['totp_nouveau']);
                journaliser('double_auth_activee', $u['identifiant']);
                flash('ok', 'Double authentification activée.');
            }
        } elseif ($a === 'totp_desactiver' && password_verify((string)($_POST['actuel'] ?? ''), $u['hash'])) {
            executer('UPDATE utilisateurs SET totp = NULL WHERE id = ?', [$u['id']]);
            flash('ok', 'Double authentification désactivée.');
        }
        aller('compte');
    }
    entete('Mon compte', 'compte');
    titre('Mon compte', $u['identifiant'] . ' · ' . ROLES[$u['role']]);
    if ((int)$u['changer_mdp']) {
        echo '<div class="msg info">Pour continuer, choisissez votre propre mot de passe.</div>';
    }
    echo '<div class="grille-2"><section class="carte"><h2>Mot de passe</h2><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="mdp">'
        . '<label>Actuel<input name="actuel" type="password" required autocomplete="current-password"></label><label>Nouveau (12 caractères minimum)<input name="nouveau" type="password" required minlength="12" autocomplete="new-password"></label>'
        . '<label>Confirmer<input name="confirmation" type="password" required minlength="12" autocomplete="new-password"></label><button class="btn">Changer</button></form></section><section class="carte"><h2>Double authentification</h2>';
    if (!empty($u['totp'])) {
        echo '<p>' . badge('Activée', 'vert') . '</p><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="totp_desactiver"><label>Mot de passe, pour confirmer<input name="actuel" type="password" required></label><button class="btn contour">Désactiver</button></form>';
    } elseif (!empty($_SESSION['totp_nouveau'])) {
        $s = (string)$_SESSION['totp_nouveau'];
        $uri = 'otpauth://totp/' . rawurlencode('SALW Santé:' . $u['identifiant']) . '?secret=' . $s . '&issuer=' . rawurlencode('SALW Santé');
        echo '<p>Scannez ce code avec Google Authenticator, Microsoft Authenticator ou 1Password :</p><div class="qr" data-qr="' . h($uri) . '"></div>'
            . '<p class="petit">Clé : <code>' . h(trim(chunk_split($s, 4, ' '))) . '</code></p><form method="post" class="form ligne">' . champ_csrf() . '<input type="hidden" name="action" value="totp_activer"><input name="code" inputmode="numeric" maxlength="7" placeholder="Code à 6 chiffres" required><button class="btn">Activer</button></form>';
    } else {
        echo '<p>Recommandé : les comptes accèdent à des données personnelles de clients.</p><form method="post">' . champ_csrf() . '<input type="hidden" name="action" value="totp_preparer"><button class="btn">Configurer</button></form>';
    }
    echo '</section></div>';
    pied();
}

// --- Tableau de bord ---------------------------------------------------------------------

function tuile(string $lib, string $val, string $det = ''): string
{
    return '<div class="tuile"><span class="tuile-lib">' . h($lib) . '</span><span class="tuile-val">' . h($val) . '</span>' . ($det !== '' ? '<span class="tuile-det">' . h($det) . '</span>' : '') . '</div>';
}

function page_tableau(): void
{
    $c = clinique_courante();
    if (!$c) {
        entete('Tableau de bord', 'tableau');
        titre('Aucun client', 'Créez un premier client dans « Clients (SALW) ».');
        pied();
        return;
    }
    $k = indicateurs($c, 30);
    $aujourdhui = (new DateTimeImmutable('@' . maintenant($c)))->setTimezone(tz($c));
    $jourDebut = iso($aujourdhui->setTime(0, 0)->getTimestamp());
    $jourFin = iso($aujourdhui->setTime(23, 59)->getTimestamp());
    $duJour = toutes("SELECT r.*, p.prenom, p.nom, pr.nom AS praticien, pr.titre FROM rdv r JOIN patients p ON p.id = r.patient_id JOIN praticiens pr ON pr.id = r.praticien_id
        WHERE r.clinique_id = ? AND r.debut BETWEEN ? AND ? AND r.statut != 'annule' ORDER BY r.debut", [$c['id'], $jourDebut, $jourFin]);
    $evts = toutes("SELECT * FROM evenements WHERE clinique_id = ? AND detail != 'historique de démonstration' ORDER BY id DESC LIMIT 12", [$c['id']]);

    entete('Tableau de bord', 'tableau');
    titre('Tableau de bord', '30 derniers jours · ' . $c['nom']);
    $alerte = peut_gerer() ? alerte_consommation($c) : null;
    if ($alerte) {
        echo '<div class="msg ' . h($alerte[0]) . '" role="status">' . h($alerte[1]) . ' <a href="' . h(url('abonnement')) . '">Voir l\'abonnement</a></div>';
    }
    $evol = $k['absence'] !== null && $k['absence_avant'] !== null ? ($k['absence'] <= $k['absence_avant'] ? 'contre ' : 'hausse, contre ') . pct($k['absence_avant']) . ' les 30 jours précédents' : '';
    echo '<section class="carte heros-kpi"><div><span class="tuile-lib">Chiffre d\'affaires récupéré par les automatisations</span><span class="chiffre-heros">' . h(euros($k['valeur'])) . '</span>'
        . '<span class="tuile-det">' . $k['recuperes'] . ' créneau(x) annulé(s) repris par la liste d\'attente + ' . $k['rattrapes'] . ' appel(s) manqué(s) transformé(s) en rendez-vous' . ($k['devis_acceptes'] ? ' + ' . $k['devis_acceptes'] . ' devis relancé(s) accepté(s)' : '') . ', valeur moyenne ' . h(euros((float)$c['valeur_consultation'])) . ' par rendez-vous</span></div>'
        . '<div><span class="tuile-lib">Taux d\'absence</span><span class="chiffre-heros">' . h(pct($k['absence'])) . '</span><span class="tuile-det">' . h($evol) . '</span></div></section>';
    echo '<div class="tuiles">'
        . tuile('Rendez-vous pris', (string)$k['rdv_crees'], $k['rdv_crees'] ? round($k['en_ligne'] / $k['rdv_crees'] * 100) . ' % en ligne, sans le secrétariat' : '')
        . tuile('Appels manqués', (string)$k['appels'], $k['rattrapes'] . ' rattrapé(s) par SMS')
        . tuile('Créneaux repris', (string)$k['recuperes'], 'grâce à la liste d\'attente')
        . tuile('Avis demandés', (string)$k['avis'], $k['avis_clics'] . ' clic(s) vers Google')
        . tuile('Messages envoyés', (string)$k['messages'], 'automatiquement')
        . '</div>';
    echo '<div class="grille-2"><section class="carte"><h2>Aujourd\'hui</h2>';
    if (!$duJour) {
        echo '<p class="vide-mini">Aucun rendez-vous aujourd\'hui.</p>';
    } else {
        echo '<ul class="liste-simple">';
        foreach ($duJour as $r) {
            echo '<li><b>' . h(local($c, $r['debut'], 'H:i')) . '</b> ' . h($r['prenom'] . ' ' . $r['nom']) . ' <span class="gris">· ' . h(nom_pro(['titre' => $r['titre'], 'nom' => $r['praticien']])) . '</span> ' . badge(LIBELLES_STATUTS[$r['statut']], ton_statut($r['statut'])) . '</li>';
        }
        echo '</ul>';
    }
    echo '<a class="btn contour" href="' . h(url('agenda')) . '">Ouvrir l\'agenda</a></section>';
    echo '<section class="carte"><h2>Messages par automatisation</h2>' . barres($k['par_type']) . '</section></div>';
    echo '<section class="carte"><h2>Dernières actions automatiques</h2><ul class="liste-simple">';
    $libs = ['rdv_cree' => 'Rendez-vous pris', 'rdv_annule' => 'Rendez-vous annulé', 'creneau_libere' => 'Créneau libéré', 'creneau_recupere' => 'Créneau repris par la liste d\'attente',
        'appel_manque' => 'Appel manqué', 'appel_rattrape' => 'Appel manqué transformé en rendez-vous', 'avis_clic' => 'Clic vers l\'avis Google', 'rdv_honore' => 'Marqué honoré', 'rdv_absent' => 'Marqué absent', 'rdv_confirme_patient' => 'Confirmé par le client', 'devis_envoye' => 'Devis enregistré', 'devis_relance_accepte' => 'Devis relancé accepté', 'devis_accepte' => 'Devis accepté', 'devis_refuse' => 'Devis refusé', 'attente_inscription' => 'Inscription en liste d\'attente',
        'paiement_actif' => 'Abonnement payé : actif', 'paiement_essai' => 'Essai gratuit démarré','paiement_en_attente' => 'Paiement en attente de confirmation', 'paiement_recu' => 'Paiement reçu', 'paiement_impaye' => 'Paiement échoué', 'paiement_annule' => 'Abonnement résilié',
        'rapport_simule' => 'Rapport mensuel enregistré (simulation)', 'rapport_envoye' => 'Rapport mensuel envoyé', 'rapport_echec' => 'Échec d\'envoi du rapport mensuel'];
    foreach ($evts as $e) {
        $lib = $libs[$e['type']] ?? (strpos($e['type'], 'message_') === 0 ? 'Message « ' . (LIBELLES_MESSAGES[substr($e['type'], 8)] ?? '') . ' »' : $e['type']);
        echo '<li><span class="gris">' . h(local($c, $e['t'], 'd/m H:i')) . '</span> ' . h($lib) . ($e['detail'] !== '' ? ' · ' . h($e['detail']) : '') . '</li>';
    }
    echo $evts ? '' : '<li class="vide-mini">Rien pour l\'instant.</li>';
    echo '</ul></section>';
    pied();
}

/** Barres horizontales (une série), couleur validée pour la dataviz. */
function barres(array $items): string
{
    if (!$items) {
        return '<p class="vide-mini">Aucun message sur la période.</p>';
    }
    $max = max($items);
    $h = '<div class="barres" role="list">';
    foreach ($items as $lib => $v) {
        $h .= '<div class="barre-l" role="listitem"><span class="barre-lib">' . h((string)$lib) . '</span><span class="barre-piste"><span class="barre-rempli" style="width:' . round($v / $max * 100, 1) . '%"></span></span><span class="barre-val">' . $v . '</span></div>';
    }
    return $h . '</div>';
}
