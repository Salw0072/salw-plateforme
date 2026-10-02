<?php
/**
 * Plateforme SALW : réservation en ligne des clients (p/rdv.php?c=<identifiant>), tous métiers.
 * Étapes : type de rendez-vous → personne → jour → heure → coordonnées (+ questions du métier). Liste d'attente si rien ne convient.
 */

declare(strict_types=1);
require dirname(__DIR__) . '/prive/public.php';
require dirname(__DIR__) . '/prive/assistant.php';

$c = isset($_GET['c']) ? clinique_par_slug((string)$_GET['c']) : clinique_par_domaine((string)($_SERVER['HTTP_HOST'] ?? ''));
if (!$c) {
    page_erreur('Page introuvable', 'Ce lien de réservation n\'est pas valide.');
}
$csrf = jeton_csrf_public();
$types = types_rdv($c, true);
$type = null;
foreach ($types as $t) {
    if ((int)$t['id'] === (int)($_GET['type'] ?? 0)) {
        $type = $t;
    }
}
if (!$type && count($types) === 1) {
    $type = $types[0];
}
$prat = (int)($_GET['praticien'] ?? 0) ?: null;
$base = ['c' => $c['slug']];
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_public_valide()) {
        $erreur = 'Votre session a expiré : recommencez.';
    } elseif (!limite_ip('reservation', 6)) {
        $erreur = 'Trop de demandes depuis votre connexion. Réessayez dans une heure ou appelez-nous.';
    } else {
        $tel = telephone((string)($_POST['telephone'] ?? ''), $c['pays']);
        $prenom = mb_substr(trim((string)($_POST['prenom'] ?? '')), 0, 60);
        $nom = mb_substr(trim((string)($_POST['nom'] ?? '')), 0, 60);
        if ($prenom === '' || $nom === '' || $tel === '') {
            $erreur = 'Prénom, nom et numéro de mobile valide sont nécessaires (pour la confirmation et les rappels).';
        } elseif (($_POST['action'] ?? '') === 'attente') {
            $p = patient_trouver_ou_creer($c, $prenom, $nom, $tel, '', !empty($_POST['relance']));
                $p = accord_whatsapp($c, $p, !empty($_POST['whatsapp']));
            inserer('INSERT INTO attente (clinique_id, patient_id, praticien_id, type_id, preference, cree_le) VALUES (?, ?, ?, ?, ?, ?)',
                [$c['id'], $p['id'], $prat, $type['id'] ?? null, mb_substr(trim((string)($_POST['preference'] ?? '')), 0, 120), iso(maintenant($c))]);
            evenement($c, 'attente_inscription', $prenom . ' ' . $nom);
            page_patient($c, "Liste d'attente", '<div class="carte ok"><h1>C\'est noté, ' . h($prenom) . '</h1><p>Dès qu\'un créneau se libère, vous recevez un SMS. Le premier qui confirme l\'obtient.</p></div>');
        } else {
            [$praticienId, $debut] = explode('|', (string)($_POST['creneau'] ?? '|') . '|');
            [$infos, $errQ] = lire_questions($c, $_POST);
            if (!$type || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $debut)) {
                $erreur = 'Choisissez un créneau.';
            } elseif ($errQ !== '') {
                $erreur = $errQ;
            } else {
                $p = patient_trouver_ou_creer($c, $prenom, $nom, $tel, '', !empty($_POST['relance']));
                $p = accord_whatsapp($c, $p, !empty($_POST['whatsapp']));
                [$rdv, $err] = creer_rdv($c, $p, (int)$praticienId, (int)$type['id'], ts($debut), (int)$type['duree'], 'en_ligne', null, $infos);
                if ($rdv) {
                    $pr = une('SELECT * FROM praticiens WHERE id = ?', [$rdv['praticien_id']]);
                    page_patient($c, 'Rendez-vous confirmé', '<div class="carte ok"><div class="coche" aria-hidden="true">✓</div><h1>Rendez-vous confirmé</h1>'
                        . '<p class="grand">' . h(ucfirst(date_longue($c, $rdv['debut']))) . ' à ' . h(heure($c, $rdv['debut'])) . '</p><p>' . h(nom_pro($pr)) . ' · ' . h($type['libelle']) . '</p>'
                        . '<p>Un SMS de confirmation vous a été envoyé, puis un rappel avant le rendez-vous. Pour annuler ou déplacer, utilisez le lien du SMS.</p>'
                        . '<a class="btn contour" href="g.php?t=' . h($rdv['jeton']) . '">Gérer ce rendez-vous</a></div>', true);
                }
                $erreur = $err;
            }
        }
    }
}

// Première visite : bandeau d'accueil (accroche, garanties) ; ensuite, la page va droit à la réservation.
$accueil = $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['type']) && !isset($_GET['praticien']) && !isset($_GET['jour']);
$vitrine = vitrine($c);
$h = ($accueil ? '<h2 class="titre-resa" id="reserver">Prendre rendez-vous</h2>' : '<h1 id="reserver">Prendre rendez-vous</h1>')
    . ($erreur !== '' ? '<p class="erreur" role="alert">' . h($erreur) . '</p>' : '');
// Étape 1 : type de consultation.
$h .= '<section class="carte"><h2>1. ' . h(mot($c, 'motif')) . '</h2><div class="choix">';
foreach ($types as $t) {
    $h .= '<a class="option' . ($type && (int)$type['id'] === (int)$t['id'] ? ' actif' : '') . '" href="?' . h(http_build_query($base + ['type' => $t['id']])) . '">' . h($t['libelle']) . '<span>' . (int)$t['duree'] . ' min</span></a>';
}
$h .= '</div></section>';

if ($type) {
    $h .= '<section class="carte"><h2>2. ' . h(mot($c, 'Pro')) . '</h2><div class="choix"><a class="option' . (!$prat ? ' actif' : '') . '" href="?' . h(http_build_query($base + ['type' => $type['id']])) . '">Le premier disponible</a>';
    $noms = [];
    foreach (praticiens($c) as $p) {
        $noms[$p['id']] = nom_pro($p);
        $h .= '<a class="option' . ($prat === (int)$p['id'] ? ' actif' : '') . '" href="?' . h(http_build_query($base + ['type' => $type['id'], 'praticien' => $p['id']])) . '">' . h($noms[$p['id']]) . '</a>';
    }
    $h .= '</div></section>';

    $creneaux = creneaux_libres($c, $type, $prat, 200);
    $parJour = [];
    foreach ($creneaux as $cr) {
        $parJour[local($c, $cr['debut'], 'Y-m-d')][] = $cr;
    }
    $jour = isset($parJour[$_GET['jour'] ?? '']) ? (string)$_GET['jour'] : (string)array_key_first_compat($parJour);
    $h .= '<section class="carte"><h2>3. Jour et heure</h2>';
    if (!$parJour) {
        $h .= '<p>Aucun créneau disponible pour le moment. Inscrivez-vous sur la liste d\'attente ci-dessous : vous serez prévenu par SMS dès qu\'une place se libère.</p>';
    } else {
        $h .= '<div class="jours">';
        foreach (array_slice(array_keys($parJour), 0, 14) as $j) {
            $iso = $parJour[$j][0]['debut'];
            $h .= '<a class="jour' . ($j === $jour ? ' actif' : '') . '" href="?' . h(http_build_query($base + ['type' => $type['id'], 'praticien' => $prat, 'jour' => $j])) . '">' . h(ucfirst(mb_substr(date_longue($c, $iso), 0, 3)) . '. ' . local($c, $iso, 'd/m')) . '</a>';
        }
        $h .= '</div><form method="post" class="form"><input type="hidden" name="csrf" value="' . h($csrf) . '"><fieldset class="heures"><legend class="sr">Heure</legend>';
        foreach ($parJour[$jour] as $i => $cr) {
            $h .= '<label class="heure"><input type="radio" name="creneau" value="' . $cr['praticien_id'] . '|' . h($cr['debut']) . '"' . ($i === 0 ? ' required' : '') . '><span>' . h(local($c, $cr['debut'], 'H:i')) . (!$prat ? '<small>' . h($noms[$cr['praticien_id']] ?? '') . '</small>' : '') . '</span></label>';
        }
        $h .= '</fieldset><h2>4. Vos coordonnées</h2>' . champs_patient($c) . champs_questions_public($c) . '<button class="btn">Confirmer le rendez-vous</button></form>';
    }
    $h .= '</section>';

    $h .= '<details class="carte"><summary><b>Aucun créneau ne vous convient ?</b> Rejoignez la liste d\'attente</summary><form method="post" class="form"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="attente">'
        . '<p class="petit">Si quelqu\'un annule, vous recevez un SMS. Le premier qui confirme obtient le créneau.</p>' . champs_patient($c)
        . '<label>Préférence (facultatif)<input name="preference" maxlength="120" placeholder="Ex. plutôt le matin"></label><button class="btn contour">M\'inscrire sur la liste d\'attente</button></form></details>';
}
page_patient($c, 'Prendre rendez-vous', '<div class="mise-en-page"><div class="colonne">' . $h . '</div><aside class="cote">' . infos_pratiques($c, $vitrine) . '</aside></div>',
    true, $accueil ? bandeau_accueil($c, $vitrine) : '', true);

function champs_patient(array $c): string
{
    return '<div class="duo"><label>Prénom<input name="prenom" required maxlength="60" autocomplete="given-name"></label><label>Nom<input name="nom" required maxlength="60" autocomplete="family-name"></label></div>'
        . '<label>Mobile<input name="telephone" type="tel" required autocomplete="tel" placeholder="06 12 34 56 78"><small>Pour la confirmation et les rappels. Aucune information confidentielle n\'est envoyée par SMS.</small></label>'
        . (connexion($c, 'whatsapp') ? '<label class="case"><input type="checkbox" name="whatsapp" value="1"> Je préfère recevoir ma confirmation et mes rappels sur WhatsApp (sinon par SMS)</label>' : '')
        . '<label class="case"><input type="checkbox" name="relance" value="1"> J\'accepte que ' . h($c['nom']) . ' me propose, de temps en temps, de reprendre rendez-vous (désinscription par STOP)</label>';
}

/** Enregistre l'accord WhatsApp donné à la réservation (une case non cochée ne retire pas un accord déjà donné). */
function accord_whatsapp(array $c, array $p, bool $accord): array
{
    if ($accord && connexion($c, 'whatsapp')) {
        executer('UPDATE patients SET whatsapp = 1 WHERE id = ?', [$p['id']]);
        $p['whatsapp'] = 1;
    }
    return $p;
}

function array_key_first_compat(array $a)
{
    foreach ($a as $k => $v) {
        return $k;
    }
    return null;
}

/** Questions de réservation du métier (domaine, adresse d'intervention, immatriculation…). */
function champs_questions_public(array $c): string
{
    $h = '';
    foreach (metier($c)['questions'] as $q) {
        $nom = 'q_' . $q['cle'];
        if ($q['type'] === 'choix') {
            $h .= '<label>' . h($q['libelle']) . '<select name="' . h($nom) . '"' . (!empty($q['requis']) ? ' required' : '') . '><option value="">Choisir…</option>';
            foreach ($q['options'] as $o) {
                $h .= '<option>' . h($o) . '</option>';
            }
            $h .= '</select></label>';
        } else {
            $h .= '<label>' . h($q['libelle']) . (!empty($q['requis']) ? '' : ' <small>(facultatif)</small>') . '<input name="' . h($nom) . '" maxlength="160"' . (!empty($q['requis']) ? ' required' : '') . '></label>';
        }
    }
    return $h;
}
