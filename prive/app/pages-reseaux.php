<?php
/**
 * Plateforme SALW : page « Réseaux sociaux » d'un client (WhatsApp, Facebook, Instagram, LinkedIn).
 * Connexions : équipe SALW. Publications, réglages et prospects : responsable et équipe SALW.
 */

declare(strict_types=1);

const CHAMPS_RESEAUX = [
    'whatsapp' => ['Identifiant du numéro (Phone number ID)', 'Jeton d\'accès permanent (utilisateur système Meta)', 'WhatsApp Manager › Téléphones. Le numéro doit être enregistré sur la plateforme WhatsApp Business de Meta.'],
    'facebook' => ['Identifiant de la page', 'Jeton d\'accès de la page', 'Page Facebook du client, gérée par l\'application Meta de SALW. Sert aussi aux prospects des publicités.'],
    'instagram' => ['Identifiant du compte Instagram professionnel', 'Jeton (facultatif : vide = celui de la page Facebook)', 'Compte Instagram professionnel relié à la page Facebook. Publication avec une image JPEG seulement.'],
    'linkedin' => ['Identifiant de la page entreprise (organisation)', 'Jeton d\'accès LinkedIn', 'Droit « w_organization_social » ; le jeton dure 60 jours, à renouveler.'],
];

function page_reseaux(): void
{
    if (!peut_gerer()) {
        interdit();
    }
    $c = clinique_courante();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        $reseau = (string)($_POST['reseau'] ?? '');
        if ($a === 'connexion' && est_salw() && isset(RESEAUX[$reseau])) {
            $ident = trim((string)($_POST['identifiant'] ?? ''));
            $expire = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['expire'] ?? '')) ? (string)$_POST['expire'] : '';
            enregistrer_connexion($c, $reseau, $ident, trim((string)($_POST['jeton'] ?? '')), $expire);
            journaliser('reseau_connecte', RESEAUX[$reseau], (int)$c['id']);
            flash('ok', RESEAUX[$reseau] . ' enregistré. ' . tester_connexion($c, $reseau));
        } elseif ($a === 'deconnecter' && est_salw() && isset(RESEAUX[$reseau])) {
            executer('DELETE FROM reseaux WHERE clinique_id = ? AND reseau = ?', [$c['id'], $reseau]);
            journaliser('reseau_deconnecte', RESEAUX[$reseau], (int)$c['id']);
            flash('ok', RESEAUX[$reseau] . ' déconnecté.');
        } elseif ($a === 'options') {
            $o = options_reseaux($c);
            foreach (['semaine', 'derniere_minute'] as $k) {
                $o[$k]['actif'] = !empty($_POST[$k . '_actif']);
                $o[$k]['reseaux'] = array_values(array_intersect(['facebook', 'instagram', 'linkedin'], (array)($_POST[$k . '_reseaux'] ?? [])));
                $t = trim(str_replace("\r", '', (string)($_POST[$k . '_texte'] ?? '')));
                if ($t !== '') {
                    $o[$k]['texte'] = mb_substr($t, 0, 1000);
                }
            }
            $o['prospects']['actif'] = !empty($_POST['prospects_actif']);
            executer('UPDATE cliniques SET reseaux_options = ? WHERE id = ?', [(string)json_encode($o, JSON_UNESCAPED_UNICODE), $c['id']]);
            flash('ok', 'Réglages des réseaux enregistrés.');
        } elseif ($a === 'publier') {
            $texte = trim(str_replace("\r", '', (string)($_POST['texte'] ?? '')));
            $lien = !empty($_POST['avec_lien']) ? lien_reservation($c) : '';
            $choix = (array)($_POST['reseaux'] ?? []);
            if ($texte === '' || !$choix) {
                flash('erreur', 'Écrivez un texte et choisissez au moins un réseau.');
            } else {
                $res = publier($c, $choix, mb_substr($texte, 0, 2000), $lien, 'manuel', 'manuel-' . bin2hex(random_bytes(4)));
                $echecs = array_filter($res, function ($r) { return in_array($r[0], ['echec', 'ignore'], true); });
                flash($echecs ? 'erreur' : 'ok', implode(' · ', array_map(function ($r, $v) {
                    return RESEAUX[$r] . ' : ' . ['publie' => 'publié', 'simule' => 'publié (simulation)', 'echec' => 'échec (' . $v[1] . ')', 'ignore' => $v[1]][$v[0]];
                }, array_keys($res), $res)) ?: 'Aucun réseau connecté.');
            }
        }
        aller('reseaux');
    }
    $c = clinique((int)$c['id']);
    $o = options_reseaux($c);

    entete('Réseaux sociaux', 'reseaux');
    titre('Réseaux sociaux et WhatsApp', 'Rappels par WhatsApp quand le client l\'accepte, publications automatiques sur Facebook, Instagram et LinkedIn, prospects des publicités contactés aussitôt.');
    if (reseaux_simulation($c)) {
        echo '<div class="msg info">' . ((int)$c['demo'] ? 'Démonstration' : 'Mode simulation') . ' : publications et messages WhatsApp sont enregistrés ici, sans rien envoyer.</div>';
    }

    // Connexions.
    echo '<section class="carte"><h2>Connexions</h2><div class="reseaux-grille">';
    foreach (RESEAUX as $r => $nom) {
        $ligne = une('SELECT * FROM reseaux WHERE clinique_id = ? AND reseau = ?', [$c['id'], $r]);
        $cx = connexion($c, $r);
        $expire = $ligne && $ligne['expire_le'] !== '' ? strtotime($ligne['expire_le']) : 0;
        echo '<div class="reseau-carte"><h3>' . h($nom) . '</h3><p>' . ($cx ? badge('connecté', 'vert') . ($ligne['nom'] !== '' ? ' <b>' . h($ligne['nom']) . '</b>' : '') : badge('non connecté', 'gris')) . '</p>'
            . ($expire && $expire < time() + 7 * 86400 ? '<div class="msg erreur">Jeton ' . ($expire < time() ? 'expiré' : 'qui expire le ' . h(date('d/m/Y', $expire))) . ' : à renouveler.</div>' : '')
            . '<p class="petit">' . h(CHAMPS_RESEAUX[$r][2]) . '</p>';
        if (est_salw()) {
            echo '<form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="connexion"><input type="hidden" name="reseau" value="' . $r . '">'
                . '<label>' . h(CHAMPS_RESEAUX[$r][0]) . '<input name="identifiant" value="' . h((string)($ligne['identifiant'] ?? '')) . '" autocomplete="off"></label>'
                . '<label>' . h(CHAMPS_RESEAUX[$r][1]) . '<input name="jeton" type="password" autocomplete="new-password" placeholder="' . ($ligne && $ligne['jeton'] !== '' ? 'enregistré : laisser vide pour le garder' : '') . '"></label>'
                . ($r === 'linkedin' ? '<label>Expire le<input name="expire" type="date" value="' . h((string)($ligne['expire_le'] ?? '')) . '"></label>' : '')
                . '<div class="deux-boutons"><button class="btn contour">Enregistrer et tester</button></div></form>';
            if ($ligne) {
                echo '<form method="post" data-confirmer="Déconnecter ' . h($nom) . ' ?">' . champ_csrf() . '<input type="hidden" name="action" value="deconnecter"><input type="hidden" name="reseau" value="' . $r . '"><button class="lien danger">Déconnecter</button></form>';
            }
        }
        echo '</div>';
    }
    echo '</div>' . (est_salw() ? '<p class="petit">Notifications Meta (prospects, statuts WhatsApp) : déclarer une seule fois l\'adresse <code>' . h(url_base() . '/webhooks/meta.php') . '</code> dans l\'application Meta de SALW, champs « leadgen » (pages) et « messages » (WhatsApp).</p>' : '') . '</section>';

    // Modèles WhatsApp.
    if (connexion($c, 'whatsapp') || est_salw()) {
        echo '<section class="carte"><h2>Modèles WhatsApp à faire approuver</h2><p class="petit">Meta n\'autorise que des modèles approuvés pour les messages envoyés en premier. Créez-les dans WhatsApp Manager › Modèles de message, en français, avec exactement ce nom et ce texte. Ils reprennent les textes de la page Automatisations : si vous modifiez un texte, refaites approuver son modèle. Sans modèle approuvé, le message part par SMS.</p>'
            . '<div class="table"><table><thead><tr><th>Message</th><th>Nom du modèle</th><th>Catégorie</th><th>Texte à soumettre</th></tr></thead><tbody>';
        foreach (MODELES_WHATSAPP as $type => $cat) {
            $m = modele_whatsapp((string)(reglages($c)['textes'][$type] ?? textes_defaut($c)[$type] ?? ''));
            $vars = implode(', ', array_map(function ($v, $i) { return '{{' . ($i + 1) . '}} = ' . $v; }, $m['variables'], array_keys($m['variables'])));
            echo '<tr><td>' . h(LIBELLES_MESSAGES[$type] ?? $type) . '</td><td><code>' . h(nom_modele_whatsapp($type)) . '</code></td><td>' . h($cat === 'MARKETING' ? 'Marketing' : 'Utilitaire') . '</td>'
                . '<td><span class="modele-texte">' . nl2br(h($m['corps'])) . '</span>' . ($vars !== '' ? '<br><span class="gris">' . h($vars) . '</span>' : '') . '</td></tr>';
        }
        echo '</tbody></table></div><p class="petit">Les messages « Marketing » (avis, réactivation) supposent l\'accord du client pour ce type de message. Meta peut reclasser une catégorie à l\'examen.</p></section>';
    }

    // Publications automatiques et prospects.
    $cases = function (string $nom, array $coches): string {
        $h = '';
        foreach (['facebook', 'instagram', 'linkedin'] as $r) {
            $h .= '<label class="case"><input type="checkbox" name="' . $nom . '[]" value="' . $r . '"' . (in_array($r, $coches, true) ? ' checked' : '') . '> ' . RESEAUX[$r] . '</label>';
        }
        return '<div class="cases-ligne">' . $h . '</div>';
    };
    echo '<section class="carte"><h2>Automatisations</h2><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="options">'
        . '<fieldset class="bloc-option"><legend><label class="case"><input type="checkbox" name="semaine_actif" value="1"' . ($o['semaine']['actif'] ? ' checked' : '') . '> Créneaux libres de la semaine, chaque lundi à 9 h</label></legend>'
        . $cases('semaine_reseaux', $o['semaine']['reseaux']) . '<label>Texte<textarea name="semaine_texte" rows="2">' . h($o['semaine']['texte']) . '</textarea><small>{nombre} {structure} {lien}. Rien n\'est publié s\'il ne reste aucun créneau.</small></label></fieldset>'
        . '<fieldset class="bloc-option"><legend><label class="case"><input type="checkbox" name="derniere_minute_actif" value="1"' . ($o['derniere_minute']['actif'] ? ' checked' : '') . '> Créneau libéré à la dernière minute</label></legend>'
        . $cases('derniere_minute_reseaux', $o['derniere_minute']['reseaux']) . '<label>Texte<textarea name="derniere_minute_texte" rows="2">' . h($o['derniere_minute']['texte']) . '</textarea><small>{date} {heure} {structure} {lien}. Publié si la liste d\'attente n\'a pas repris le créneau en 70 minutes, dans les 48 h à venir, une fois par jour au plus.</small></label></fieldset>'
        . '<label class="case"><input type="checkbox" name="prospects_actif" value="1"' . ($o['prospects']['actif'] ? ' checked' : '') . '> Contacter aussitôt les prospects des publicités Facebook et Instagram (WhatsApp s\'ils l\'acceptent dans le formulaire, sinon SMS ou e-mail)</label>'
        . '<button class="btn">Enregistrer</button></form></section>';

    // Publier maintenant.
    echo '<section class="carte"><h2>Publier maintenant</h2><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="publier">'
        . '<label>Texte<textarea name="texte" rows="4" maxlength="2000" required placeholder="Ex. Nouveau : rendez-vous le samedi matin, dès ce mois-ci."></textarea></label>'
        . '<label class="case"><input type="checkbox" name="avec_lien" value="1" checked> Ajouter le lien de réservation</label>'
        . $cases('reseaux', array_values(array_filter(['facebook', 'instagram', 'linkedin'], function ($r) use ($c) { return connexion($c, $r) !== null; })))
        . '<p class="petit">Instagram publie avec la photo de couverture de la page publique (JPEG). LinkedIn : texte et lien.</p><button class="btn">Publier</button></form></section>';

    // Historique et prospects.
    $pubs = toutes('SELECT * FROM publications WHERE clinique_id = ? ORDER BY id DESC LIMIT 20', [$c['id']]);
    echo '<div class="grille-2"><section class="carte"><h2>Dernières publications</h2>';
    if (!$pubs) {
        echo '<p class="vide-mini">Aucune publication pour l\'instant.</p>';
    } else {
        echo '<ul class="liste-simple">';
        $tons = ['publie' => ['publiée', 'vert'], 'simule' => ['simulation', 'gris'], 'echec' => ['échec', 'rouge'], 'en_cours' => ['en cours', 'orange']];
        foreach ($pubs as $p) {
            [$lib, $t] = $tons[$p['statut']] ?? [$p['statut'], 'gris'];
            echo '<li><span class="gris">' . h(date('d/m H:i', strtotime($p['cree_le']))) . '</span> <b>' . h(RESEAUX[$p['reseau']] ?? $p['reseau']) . '</b> ' . badge($lib, $t)
                . '<br>' . h(mb_strimwidth($p['texte'], 0, 140, '…')) . ($p['erreur'] !== '' ? '<br><span class="gris">' . h($p['erreur']) . '</span>' : '') . '</li>';
        }
        echo '</ul>';
    }
    echo '</section>';
    $prospects = toutes('SELECT * FROM prospects WHERE clinique_id = ? ORDER BY id DESC LIMIT 30', [$c['id']]);
    $nb = une("SELECT COUNT(*) n, SUM(statut IN ('contacte', 'rdv')) contactes, SUM(statut = 'rdv') rdv FROM prospects WHERE clinique_id = ?", [$c['id']]);
    echo '<section class="carte"><h2>Prospects des publicités</h2><p>' . (int)$nb['n'] . ' reçu(s) · ' . (int)$nb['contactes'] . ' contacté(s) · <b>' . (int)$nb['rdv'] . ' devenu(s) rendez-vous</b></p>';
    if (!$prospects) {
        echo '<p class="vide-mini">Ils apparaîtront ici dès qu\'un formulaire de publicité Facebook ou Instagram sera rempli (page Facebook connectée et notifications Meta déclarées).</p>';
    } else {
        echo '<ul class="liste-simple">';
        foreach ($prospects as $p) {
            echo '<li><span class="gris">' . h(date('d/m H:i', strtotime($p['recu_le']))) . '</span> <b>' . h(trim($p['prenom'] . ' ' . $p['nom']) ?: 'Sans nom') . '</b> · ' . h(RESEAUX[$p['source']] ?? $p['source'])
                . ' ' . badge(['nouveau' => 'nouveau', 'contacte' => 'contacté' . ($p['canal'] !== '' ? ' (' . ($p['canal'] === 'whatsapp' ? 'WhatsApp' : ($p['canal'] === 'sms' ? 'SMS' : 'e-mail')) . ')' : ''), 'rdv' => 'rendez-vous pris'][$p['statut']] ?? $p['statut'], ['rdv' => 'vert', 'contacte' => 'bleu'][$p['statut']] ?? 'gris')
                . '<br><span class="gris">' . h(trim(telephone_lisible((string)$p['telephone']) . ' ' . $p['email'])) . '</span></li>';
        }
        echo '</ul>';
    }
    echo '</section></div>';
    pied();
}

/** Vérifie une connexion en lisant le nom du compte ; renvoie un message à afficher. */
function tester_connexion(array $c, string $reseau): string
{
    $cx = connexion($c, $reseau);
    if (!$cx) {
        return 'Identifiant ou jeton manquant.';
    }
    if ($reseau === 'linkedin' || reseaux_simulation($c)) {
        return $reseau === 'linkedin' ? 'Le jeton sera vérifié à la première publication.' : 'Test réel désactivé en simulation.';
    }
    try {
        $champs = ['whatsapp' => 'display_phone_number,verified_name', 'facebook' => 'name', 'instagram' => 'username'][$reseau];
        $r = meta('GET', '/' . $cx['identifiant'], ['fields' => $champs], (string)$cx['jeton']);
        $nom = (string)($r['verified_name'] ?? $r['name'] ?? $r['username'] ?? '');
        $nom .= isset($r['display_phone_number']) ? ' (' . $r['display_phone_number'] . ')' : '';
        executer('UPDATE reseaux SET nom = ? WHERE clinique_id = ? AND reseau = ?', [$nom, $c['id'], $reseau]);
        return 'Connexion vérifiée : ' . $nom . '.';
    } catch (RuntimeException $e) {
        return 'Mais le test a échoué : ' . $e->getMessage();
    }
}
