<?php
/**
 * Plateforme SALW : image du client (logo, couleur, adresse web), codes à intégrer sur son site, rapport mensuel.
 */

declare(strict_types=1);

const LOGO_MAX_OCTETS = 300 * 1024;
const COUVERTURE_MAX_OCTETS = 8 * 1048576;   // avec GD : réduite ensuite à quelques centaines de Ko
const COUVERTURE_MAX_SANS_GD = 2 * 1048576;

function page_integration(): void
{
    if (!peut_gerer()) {
        interdit();
    }
    $c = clinique_courante();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'couleur') {
            $v = strtolower(trim((string)($_POST['couleur'] ?? '')));
            executer('UPDATE cliniques SET couleur = ? WHERE id = ?', [preg_match('/^#[0-9a-f]{6}$/', $v) && empty($_POST['aucune']) ? $v : '', $c['id']]);
            flash('ok', empty($_POST['aucune']) ? 'Couleur enregistrée.' : 'Couleurs SALW rétablies.');
        } elseif ($a === 'logo') {
            [$ok, $msg] = enregistrer_logo($c, $_FILES['logo'] ?? null);
            flash($ok ? 'ok' : 'erreur', $msg);
        } elseif ($a === 'vitrine') {
            $atouts = array_slice(array_filter(array_map(function ($l) { return mb_substr(trim($l), 0, 70); }, explode("\n", str_replace("\r", '', (string)($_POST['atouts'] ?? '')))), 'strlen'), 0, 4);
            executer('UPDATE cliniques SET accroche = ?, presentation = ?, atouts = ? WHERE id = ?', [
                mb_substr(trim((string)($_POST['accroche'] ?? '')), 0, 90), mb_substr(trim(str_replace(["\r", "\n"], ' ', (string)($_POST['presentation'] ?? ''))), 0, 400), implode("\n", $atouts), $c['id']]);
            flash('ok', 'Présentation enregistrée.');
        } elseif ($a === 'couverture') {
            [$ok, $msg] = enregistrer_image($c, $_FILES['couverture'] ?? null, 'couverture');
            if (in_array($_POST['cadrage'] ?? '', ['haut', 'centre', 'bas'], true)) {
                executer('UPDATE cliniques SET couverture_cadrage = ? WHERE id = ?', [$_POST['cadrage'], $c['id']]);
            }
            flash($ok ? 'ok' : 'erreur', $msg);
        } elseif ($a === 'cadrage' && in_array($_POST['cadrage'] ?? '', ['haut', 'centre', 'bas'], true)) {
            executer('UPDATE cliniques SET couverture_cadrage = ? WHERE id = ?', [$_POST['cadrage'], $c['id']]);
            flash('ok', 'Cadrage enregistré.');
        } elseif ($a === 'couverture_supprimer') {
            $f = image_fichier($c, 'couverture');
            if ($f !== '' && is_file($f)) {
                @unlink($f);
            }
            executer("UPDATE cliniques SET couverture = '' WHERE id = ?", [$c['id']]);
            flash('ok', 'Photo de couverture retirée.');
        } elseif ($a === 'logo_supprimer') {
            $f = logo_fichier($c);
            if ($f !== '' && is_file($f)) {
                @unlink($f);
            }
            executer("UPDATE cliniques SET logo = '' WHERE id = ?", [$c['id']]);
            flash('ok', 'Logo retiré.');
        } elseif ($a === 'domaine' && est_salw()) {
            $d = strtolower(trim(preg_replace('#^https?://|/.*$#i', '', trim((string)($_POST['domaine'] ?? '')))));
            if ($d !== '' && !preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $d)) {
                flash('erreur', 'Adresse invalide : saisissez par exemple rdv.cabinet-durand.fr, sans https://.');
            } elseif ($d !== '' && valeur('SELECT id FROM cliniques WHERE domaine = ? AND id != ?', [$d, $c['id']])) {
                flash('erreur', 'Cette adresse est déjà attribuée à un autre client.');
            } else {
                executer('UPDATE cliniques SET domaine = ? WHERE id = ?', [$d, $c['id']]);
                flash('ok', $d !== '' ? 'Adresse enregistrée. Les nouveaux liens de réservation l\'utilisent.' : 'Adresse retirée : retour à l\'adresse de la plateforme.');
            }
        }
        journaliser('integration_' . $a, '', (int)$c['id']);
        aller('integration');
    }
    $c = clinique((int)$c['id']);
    $lienRdv = lien_reservation($c);
    $accent = couleur_accent($c);
    $logo = logo_url($c);

    entete('Page publique', 'integration');
    titre('Page publique et intégration', 'Ce que voient les clients de ' . $c['nom'] . ' : sa page de réservation à ses couleurs, et les boutons à poser sur son site.', '<a class="btn contour" href="' . h($lienRdv) . '" target="_blank" rel="noopener">Voir la page ↗</a>');

    // Aperçu de l'en-tête tel qu'il apparaîtra.
    $apercu = ($logo !== '' || $c['couleur'] !== '')
        ? '<div class="apercu-tete" style="border-bottom-color:' . h($accent) . '">' . ($logo !== '' ? '<img src="' . h($logo) . '" alt="">' : '') . '<div><b>' . h($c['nom']) . '</b><span>' . h(trim($c['adresse'] . ', ' . $c['ville'], ', ')) . '</span></div></div>'
        : '<div class="apercu-tete sombre"><div><b>' . h($c['nom']) . '</b><span>' . h(trim($c['adresse'] . ', ' . $c['ville'], ', ')) . '</span></div></div>';
    echo '<section class="carte"><h2>Image</h2><p class="petit">Aperçu de l\'en-tête de la page de réservation :</p>' . $apercu
        . '<p><span class="bouton-apercu" style="background:' . h($accent) . '">Confirmer le rendez-vous</span></p>'
        . '<div class="grille-2"><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="couleur">'
        . '<label>Couleur principale<input type="color" name="couleur" value="' . h($c['couleur'] !== '' ? $c['couleur'] : '#6b2150') . '" class="couleur"><small>Celle du logo ou de l\'enseigne. Si elle est trop claire pour du texte blanc, la plateforme l\'assombrit automatiquement.</small></label>'
        . ($c['couleur'] !== '' && $accent !== strtolower($c['couleur']) ? '<p class="petit">Assombrie à <code>' . h($accent) . '</code> pour rester lisible.</p>' : '')
        . '<div class="deux-boutons"><button class="btn">Enregistrer</button>' . ($c['couleur'] !== '' ? '<button class="btn contour" name="aucune" value="1">Revenir aux couleurs SALW</button>' : '') . '</div></form>'
        . '<form method="post" class="form" enctype="multipart/form-data">' . champ_csrf() . '<input type="hidden" name="action" value="logo">'
        . '<label>Logo<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required><small>PNG, JPEG ou WebP, 300 Ko au plus. Idéalement horizontal, sur fond blanc ou transparent.</small></label>'
        . '<button class="btn">Envoyer le logo</button></form></div>';
    if ($logo !== '') {
        echo '<form method="post" data-confirmer="Retirer le logo ?">' . champ_csrf() . '<input type="hidden" name="action" value="logo_supprimer"><button class="lien danger">Retirer le logo</button></form>';
    }
    echo '</section>';

    // Photo de couverture : fond du bandeau d'accueil, sous un voile à la couleur du client qui garde le texte lisible.
    $couv = image_url($c, 'couverture');
    $cadrages = ['haut' => 'Garder le haut', 'centre' => 'Centrer', 'bas' => 'Garder le bas'];
    $options = '';
    foreach ($cadrages as $k => $lib) {
        $options .= '<option value="' . $k . '"' . ((string)$c['couverture_cadrage'] === $k ? ' selected' : '') . '>' . $lib . '</option>';
    }
    echo '<section class="carte"><h2>Photo de couverture</h2><p class="petit">En fond du bandeau d\'accueil : les locaux, l\'équipe, l\'atelier, la façade. Un voile à la couleur du client passe par-dessus pour que le texte reste lisible, quelle que soit la photo.</p>';
    if ($couv !== '') {
        echo '<div class="apercu-couverture" style="--photo:url(&quot;' . h($couv) . '&quot;);--cadrage:' . h(cadrage_css($c)) . ';--accent:' . h($accent) . '"><b>' . h($c['nom']) . '</b><span>Aperçu du bandeau</span></div>'
            . '<div class="deux-boutons reglage-couverture"><form method="post" class="ligne">' . champ_csrf() . '<input type="hidden" name="action" value="cadrage"><label for="cadrage">Cadrage</label><select id="cadrage" name="cadrage" data-envoi-auto>' . $options . '</select><noscript><button class="btn contour">Appliquer</button></noscript></form>'
            . '<form method="post" data-confirmer="Retirer la photo de couverture ?">' . champ_csrf() . '<input type="hidden" name="action" value="couverture_supprimer"><button class="lien danger">Retirer la photo</button></form></div>';
    }
    $gd = function_exists('imagecreatetruecolor');
    echo '<form method="post" class="form" enctype="multipart/form-data">' . champ_csrf() . '<input type="hidden" name="action" value="couverture">'
        . '<div class="champ-duo"><label>' . ($couv !== '' ? 'Remplacer la photo' : 'Photo') . '<input type="file" name="couverture" accept="image/jpeg,image/webp,image/png" required><small>JPEG, WebP ou PNG, au moins 1 000 px de large, format paysage. '
        . ($gd ? 'Jusqu\'à 8 Mo : elle est réduite automatiquement et ses métadonnées (dont la position GPS) sont effacées.' : '2 Mo au plus. L\'hébergement n\'a pas l\'extension GD : la photo est gardée telle quelle, métadonnées comprises. Envoyez une photo exportée sans position GPS.') . '</small></label>'
        . ($couv === '' ? '<label>Cadrage<select name="cadrage">' . $options . '</select><small>La partie de la photo à garder visible.</small></label>' : '') . '</div>'
        . '<p class="petit">Utilisez une photo dont le client détient les droits, et sans personne reconnaissable sans son accord.</p>'
        . '<button class="btn">Envoyer la photo</button></form></section>';

    // Présentation : accroche et texte du bandeau d'accueil, atouts de la structure.
    $vm = vitrine_metier((string)$c['metier']);
    echo '<section class="carte"><h2>Présentation</h2><p class="petit">Le haut de la page de réservation : une accroche, deux phrases, puis les garanties (confirmation par SMS, rappel, report en un clic…), affichées d\'office selon les automatisations actives. Laissez un champ vide pour garder le texte proposé pour le métier. <code>{structure}</code> et <code>{ville}</code> sont remplacés automatiquement.</p>'
        . '<form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="vitrine">'
        . '<label>Accroche<input name="accroche" maxlength="90" value="' . h((string)$c['accroche']) . '" placeholder="' . h($vm['accroche']) . '"><small>90 caractères au plus. Une promesse simple, du point de vue du visiteur.</small></label>'
        . '<label>Texte de présentation<textarea name="presentation" rows="3" maxlength="400" placeholder="' . h($vm['presentation']) . '">' . h((string)$c['presentation']) . '</textarea><small>400 caractères au plus.</small></label>'
        . '<label>Pourquoi nous choisir (facultatif)<textarea name="atouts" rows="4" placeholder="' . h(implode("\n", $vm['atouts_demo'])) . '">' . h((string)$c['atouts']) . '</textarea><small>Un atout par ligne, 4 au plus, 70 caractères chacun. <b>Uniquement des faits vérifiables</b> (spécialités, accès, expérience) : pas de « meilleur de la ville ». Vide : le bloc n\'apparaît pas.</small></label>'
        . '<button class="btn">Enregistrer</button></form></section>';

    // Adresse web.
    echo '<section class="carte"><h2>Adresse de la page</h2><p>Lien actuel : <a href="' . h($lienRdv) . '" target="_blank" rel="noopener">' . h($lienRdv) . '</a></p>';
    if (est_salw()) {
        echo '<form method="post" class="ligne champ-domaine">' . champ_csrf() . '<input type="hidden" name="action" value="domaine"><input name="domaine" value="' . h((string)$c['domaine']) . '" placeholder="rdv.' . h(preg_replace('/[^a-z0-9-]/', '', strtolower((string)$c['slug']))) . '.fr" aria-label="Adresse web du client"><button class="btn contour">Enregistrer</button></form>'
            . '<p class="petit">Facultatif. Pour que la page s\'ouvre sur une adresse au nom du client : 1. le client (ou son prestataire) crée un sous-domaine, par exemple <code>rdv</code>, qui pointe vers l\'hébergement SALW ; 2. dans hPanel, ajoutez ce sous-domaine comme domaine parqué sur le dossier de la plateforme et activez le certificat SSL ; 3. saisissez-le ici. Tant que ce n\'est pas fait, laissez vide.</p>';
    } else {
        echo '<p class="petit">Pour une adresse à votre nom (ex. rdv.votre-site.fr), demandez à SALW.</p>';
    }
    echo '</section>';

    // Codes à intégrer.
    $script = '<script src="' . url_base() . '/p/bouton.js" data-url="' . h($lienRdv) . '" data-couleur="' . h($accent) . '" data-texte="Prendre rendez-vous" data-position="droite" defer></script>';
    $bouton = '<a href="' . h($lienRdv) . '" style="display:inline-block;background:' . h($accent) . ';color:#ffffff;padding:13px 24px;border-radius:999px;font:700 16px/1.2 Arial,sans-serif;text-decoration:none">Prendre rendez-vous</a>';
    echo '<section class="carte"><h2>Sur le site du client</h2><p>Trois façons de mener ses visiteurs vers la réservation. Chaque code se colle tel quel ; aucun cookie, aucune donnée collectée sur le site.</p><div class="codes">'
        . bloc_code('code-flottant', '1. Bouton flottant', 'Toujours visible en bas de l\'écran, sur toutes les pages. À coller une fois, juste avant <code>&lt;/body&gt;</code> (WordPress : extension « Insert Headers and Footers » ou réglages du thème). Mettez <code>data-position="gauche"</code> si un autre bouton occupe déjà la droite.', $script)
        . bloc_code('code-bouton', '2. Bouton dans une page', 'À placer où l\'on veut : page Contact, en-tête… (Wix, Squarespace, WordPress : bloc « HTML personnalisé »). Rendu :', $bouton, '<p>' . $bouton . '</p>')
        . bloc_code('code-lien', '3. Lien simple', 'Pour Google Business Profile (bouton « Prendre rendez-vous »), Facebook, Instagram, la signature e-mail ou un SMS.', $lienRdv)
        . '</div><h2 class="sep">Code QR</h2><div class="qr-bloc"><div class="qr" data-qr="' . h($lienRdv) . '" data-qr-taille="8" data-qr-nom="qr-rendez-vous-' . h($c['slug']) . '.gif"></div><p class="petit">Pour la vitrine, le comptoir, les cartes de visite, les devis et les factures : il ouvre directement la page de réservation.</p></div></section>';
    pied();
}

function bloc_code(string $id, string $titre, string $aide, string $code, string $rendu = ''): string
{
    return '<div class="bloc-code"><h3>' . h($titre) . '</h3><p class="petit">' . $aide . '</p>' . $rendu
        . '<textarea id="' . h($id) . '" readonly rows="' . (strlen($code) > 120 ? 4 : 2) . '" class="mono" spellcheck="false">' . h($code) . '</textarea>'
        . '<button type="button" class="btn contour" data-copier="' . h($id) . '">Copier</button></div>';
}

/** Enregistre le logo (image telle quelle, 300 Ko au plus). */
function enregistrer_logo(array $c, ?array $f): array
{
    return enregistrer_image($c, $f, 'logo');
}

/**
 * Vérifie l'image envoyée (type réel, poids, dimensions) et la range dans prive/donnees/logos/.
 * Couverture : si GD est disponible, elle est remise droite (orientation du téléphone), réduite à 1 920 px
 * et réenregistrée en JPEG, ce qui l'allège et efface ses métadonnées (position GPS comprise).
 */
function enregistrer_image(array $c, ?array $f, string $quoi): array
{
    $couv = $quoi === 'couverture';
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return [false, 'Fichier trop lourd pour l\'hébergement (limite : ' . ini_get('upload_max_filesize') . ').'];
    }
    if (!$f || $err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        return [false, 'Aucun fichier reçu.'];
    }
    $gd = function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
    $max = $couv ? ($gd ? COUVERTURE_MAX_OCTETS : COUVERTURE_MAX_SANS_GD) : LOGO_MAX_OCTETS;
    if ((int)$f['size'] > $max) {
        return [false, ($couv ? 'Photo' : 'Logo') . ' trop lourd' . ($couv ? 'e' : '') . ' : ' . round($max / 1048576, 1) . ' Mo au plus' . ($couv && !$gd ? ' (réduisez-la avant l\'envoi)' : '') . '.'];
    }
    $tmp = (string)$f['tmp_name'];
    $info = @getimagesize($tmp);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? '';
    if (!$info || $ext === '') {
        return [false, 'Format non accepté : PNG, JPEG ou WebP.'];
    }
    if ($couv && ($info[0] < 1000 || $info[1] < 400)) {
        return [false, 'Photo trop petite (' . $info[0] . ' × ' . $info[1] . ' px) : elle serait floue. Il faut au moins 1 000 px de large.'];
    }
    if ($info[0] > ($couv ? 8000 : 4000) || $info[1] > ($couv ? 8000 : 4000) || (!$couv && ($info[0] < 32 || $info[1] < 16))) {
        return [false, 'Dimensions inattendues : ' . $info[0] . ' × ' . $info[1] . ' px.'];
    }
    $dossier = dirname(__DIR__) . '/donnees/logos';
    if (!is_dir($dossier)) {
        @mkdir($dossier, 0750, true);
    }
    $base = (int)$c['id'] . '-' . ($couv ? 'couv-' : '') . bin2hex(random_bytes(4));
    if ($couv && $gd) {
        $nom = $base . '.jpg';
        if (!photo_reduite($tmp, $info, $dossier . '/' . $nom)) {
            return [false, 'Image illisible : essayez un autre fichier.'];
        }
    } else {
        $nom = $base . '.' . $ext;
        if (!move_uploaded_file($tmp, $dossier . '/' . $nom)) {
            return [false, 'Enregistrement impossible : vérifiez les droits du dossier prive/donnees.'];
        }
    }
    $ancien = image_fichier($c, $quoi);
    if ($ancien !== '' && is_file($ancien)) {
        @unlink($ancien);
    }
    executer('UPDATE cliniques SET ' . ($couv ? 'couverture' : 'logo') . ' = ? WHERE id = ?', [$nom, $c['id']]);
    return [true, $couv ? 'Photo de couverture enregistrée.' : 'Logo enregistré.'];
}

/** Redresse, réduit (1 920 px de large au plus) et réenregistre une photo en JPEG qualité 82. */
function photo_reduite(string $source, array $info, string $cible): bool
{
    $charger = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$info['mime']] ?? '';
    if ($charger === '' || !function_exists($charger)) {
        return false;
    }
    $img = @$charger($source);
    if (!$img) {
        return false;
    }
    if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($source)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle !== 0) {
            $img = imagerotate($img, $angle, 0);
        }
    }
    $l = imagesx($img);
    $h = imagesy($img);
    $r = min(1, 1920 / $l);
    $nl = (int)round($l * $r);
    $nh = (int)round($h * $r);
    $sortie = imagecreatetruecolor($nl, $nh);
    imagefill($sortie, 0, 0, imagecolorallocate($sortie, 255, 255, 255));
    imagecopyresampled($sortie, $img, 0, 0, 0, 0, $nl, $nh, $l, $h);
    imageinterlace($sortie, true);
    return imagejpeg($sortie, $cible, 82);
}

function page_rapport(): void
{
    if (!peut_gerer()) {
        interdit();
    }
    $c = clinique_courante();
    // Démonstration : son historique couvre les 30 derniers jours, d'où le mois d'il y a 15 jours par défaut.
    $defaut = (int)$c['demo'] ? (new DateTimeImmutable('@' . (maintenant($c) - 15 * 86400)))->setTimezone(tz($c))->format('Y-m') : mois_precedent($c);
    $mois = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['mois'] ?? '')) ? (string)$_GET['mois'] : $defaut;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'reglages') {
            $e = trim((string)($_POST['rapport_email'] ?? ''));
            executer('UPDATE cliniques SET rapport_email = ?, rapport_actif = ? WHERE id = ?', [filter_var($e, FILTER_VALIDATE_EMAIL) ?: '', !empty($_POST['rapport_actif']) ? 1 : 0, $c['id']]);
            flash($e === '' || filter_var($e, FILTER_VALIDATE_EMAIL) ? 'ok' : 'erreur', $e === '' || filter_var($e, FILTER_VALIDATE_EMAIL) ? 'Réglages du rapport enregistrés.' : 'Adresse e-mail invalide : non enregistrée.');
        } elseif ($a === 'envoyer' && (int)$c['demo'] && cfg('mode_envoi') === 'reel') {
            flash('erreur', 'Structure de démonstration : envoi réel désactivé.');
        } elseif ($a === 'envoyer') {
            [$ok, $msg] = envoyer_rapport($c, $mois);
            flash($ok ? 'ok' : 'erreur', $msg);
        }
        journaliser('rapport_' . $a, $mois, (int)$c['id']);
        aller('rapport', ['mois' => $mois]);
    }
    $c = clinique((int)$c['id']);
    $r = rapport_donnees($c, $mois);
    $dest = rapport_destinataire($c);

    entete('Rapport mensuel', 'rapport');
    titre('Rapport mensuel', 'Envoyé par e-mail le 1er de chaque mois : ce que la plateforme a rapporté à ' . $c['nom'] . ' le mois précédent.');
    $options = '';
    $l = (new DateTimeImmutable('@' . maintenant($c)))->setTimezone(tz($c))->modify('first day of this month');
    for ($i = 0; $i < 13; $i++) {
        $m = $l->modify('-' . $i . ' month')->format('Y-m');
        $options .= '<option value="' . $m . '"' . ($m === $mois ? ' selected' : '') . '>' . h(ucfirst(mois_libelle($m))) . ($i === 0 ? ' (en cours)' : '') . '</option>';
    }
    echo '<div class="grille-2"><section class="carte"><h2>Envoi</h2><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="reglages">'
        . '<label>Adresse du destinataire<input name="rapport_email" type="email" value="' . h((string)$c['rapport_email']) . '" placeholder="' . h($c['email'] ?: 'direction@exemple.fr') . '"><small>Vide : l\'e-mail de la fiche (' . h($c['email'] ?: 'aucun') . ').</small></label>'
        . '<label class="case"><input type="checkbox" name="rapport_actif" value="1"' . ((int)$c['rapport_actif'] ? ' checked' : '') . '> Envoyer automatiquement le 1er du mois</label>'
        . '<button class="btn contour">Enregistrer</button></form>'
        . ($dest === '' ? '<div class="msg erreur">Aucune adresse : le rapport ne peut pas partir.</div>' : '')
        . ((int)$c['demo'] ? '<p class="petit">Structure de démonstration : pas d\'envoi automatique.</p>' : '') . '</section>'
        . '<section class="carte"><h2>Historique</h2>';
    $hist = toutes('SELECT * FROM rapports WHERE clinique_id = ? ORDER BY mois DESC LIMIT 12', [$c['id']]);
    if (!$hist) {
        echo '<p class="vide-mini">Aucun rapport envoyé pour l\'instant.</p>';
    } else {
        echo '<ul class="liste-simple">';
        foreach ($hist as $x) {
            echo '<li><b>' . h(ucfirst(mois_libelle($x['mois']))) . '</b> · ' . h($x['destinataire']) . ' · ' . badge(['simule' => 'simulé', 'envoye' => 'envoyé', 'echec' => 'échec', 'sans_activite' => 'aucune activité, non envoyé'][$x['statut']] ?? $x['statut'], ['envoye' => 'vert', 'echec' => 'rouge'][$x['statut']] ?? 'gris')
                . ' <span class="gris">le ' . h(date('d/m/Y', strtotime($x['envoye_le']))) . '</span></li>';
        }
        echo '</ul>';
    }
    echo '</section></div>';

    echo '<section class="carte"><div class="titre-bloc"><h2>Aperçu</h2><form method="get" class="form ligne"><input type="hidden" name="p" value="rapport"><label class="sr" for="mois">Mois</label><select id="mois" name="mois" data-envoi-auto>' . $options . '</select><noscript><button class="btn contour">Afficher</button></noscript></form>'
        . '<form method="post" data-confirmer="Envoyer le rapport de ' . h(mois_libelle($mois)) . ' à ' . h($dest) . ' ?">' . champ_csrf() . '<input type="hidden" name="action" value="envoyer"><button class="btn"' . ($dest === '' ? ' disabled' : '') . '>Envoyer ce rapport maintenant</button></form></div>'
        . '<p class="petit">Objet : « ' . h(rapport_objet($c, $mois)) . ' » · à ' . h($dest ?: 'personne') . '</p>'
        . '<div class="apercu-email">' . rapport_html($c, $r) . '</div></section>';
    pied();
}
