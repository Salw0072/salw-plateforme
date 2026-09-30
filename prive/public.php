<?php
/**
 * Plateforme SALW : pages des clients (mise en page commune, garde-fous), tous métiers.
 */

declare(strict_types=1);

require __DIR__ . '/noyau.php';
require __DIR__ . '/messages.php';
require __DIR__ . '/agenda.php';
require __DIR__ . '/moteur.php';

// --- Présentation de la page de réservation ------------------------------------------------

/** Ville sans le code postal (« 69003 Lyon » → « Lyon »). */
function ville_seule(array $c): string
{
    return trim((string)preg_replace('/^\s*\d{4,5}\s*/', '', (string)$c['ville']));
}

/** Accroche, texte et atouts du client ; à défaut, ceux proposés pour son métier (sans les atouts, qui lui sont propres). */
function vitrine(array $c): array
{
    $d = vitrine_metier((string)($c['metier'] ?? 'sante'));
    $vars = ['{structure}' => $c['nom'], '{ville}' => ville_seule($c) ?: 'votre ville'];
    $atouts = array_values(array_filter(array_map('trim', explode("\n", (string)$c['atouts'])), 'strlen'));
    return [
        'accroche' => strtr(trim((string)$c['accroche']) ?: $d['accroche'], $vars),
        'presentation' => strtr(trim((string)$c['presentation']) ?: $d['presentation'], $vars),
        'atouts' => array_map(function ($a) use ($vars) { return strtr($a, $vars); }, array_slice($atouts, 0, 4)),
    ];
}

/** Garanties affichées : seulement ce que les automatisations actives de ce client font vraiment. */
function garanties(array $c): array
{
    $r = reglages($c);
    $g = [['M4 10.5l4 4 8-9', 'Confirmation immédiate par SMS']];
    if ($r['rappel_j2']['actif'] || $r['rappel_j1']['actif'] || $r['rappel_h3']['actif']) {
        $g[] = ['M10 3a5 5 0 0 0-5 5v3l-1.5 3h13L15 11V8a5 5 0 0 0-5-5zM8 16a2 2 0 0 0 4 0', 'Rappel avant le rendez-vous'];
    }
    $g[] = ['M4 10a6 6 0 1 1 1.8 4.3M4 15v-4h4', 'Report ou annulation en un clic'];
    if ($r['liste_attente']['actif']) {
        $g[] = ['M10 3v4M10 13v4M3 10h4M13 10h4', 'Prévenu si un créneau se libère'];
    } else {
        $g[] = ['M10 4a6 6 0 1 0 0 12 6 6 0 0 0 0-12zM10 7v3l2 2', 'Réservation 24 h sur 24'];
    }
    return $g;
}

function ico_public(string $d): string
{
    return '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

/** Heures d'ouverture à la réservation, d'après les horaires des professionnels : « Lundi au vendredi : 9 h – 18 h ». */
function horaires_publics(array $c): array
{
    $jours = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $plages = [];
    foreach (praticiens($c) as $p) {
        foreach ((array)json_decode((string)$p['horaires'], true) as $j => $ps) {
            foreach ((array)$ps as $pl) {
                $plages[(int)$j][0] = min($plages[(int)$j][0] ?? '99:99', $pl[0]);
                $plages[(int)$j][1] = max($plages[(int)$j][1] ?? '00:00', $pl[1]);
            }
        }
    }
    $fmt = function (string $h): string {
        [$hh, $mm] = explode(':', $h);
        return (int)$hh . ' h' . ($mm !== '00' ? ' ' . $mm : '');
    };
    $lignes = [];
    $groupe = null;
    for ($j = 1; $j <= 8; $j++) {
        $cle = isset($plages[$j]) ? $plages[$j][0] . '-' . $plages[$j][1] : null;
        if ($groupe && $cle === $groupe['cle']) {
            $groupe['fin'] = $j;
            continue;
        }
        if ($groupe) {
            $n = $groupe['fin'] - $groupe['debut'];
            $lib = $n === 0 ? $jours[$groupe['debut']] : $jours[$groupe['debut']] . ($n === 1 ? ' et ' : ' au ') . $jours[$groupe['fin']];
            [$a, $b] = explode('-', $groupe['cle']);
            $lignes[] = [ucfirst($lib), $fmt($a) . ' – ' . $fmt($b)];
        }
        $groupe = $cle !== null ? ['cle' => $cle, 'debut' => $j, 'fin' => $j] : null;
    }
    return $lignes;
}

/** Colonne d'informations : adresse, téléphone, horaires, atouts. */
function infos_pratiques(array $c, array $v): string
{
    $adresse = trim($c['adresse'] . ', ' . $c['ville'], ', ');
    $h = '<section class="carte infos"><h2>Infos pratiques</h2><ul class="infos-liste">';
    if ($adresse !== '') {
        $h .= '<li>' . ico_public('M10 18s6-5.3 6-10a6 6 0 1 0-12 0c0 4.7 6 10 6 10zM10 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4z') . '<a href="https://www.google.com/maps/search/?api=1&amp;query=' . h(rawurlencode($c['nom'] . ' ' . $adresse)) . '" target="_blank" rel="noopener">' . h($adresse) . '</a></li>';
    }
    if ((string)$c['telephone'] !== '') {
        $h .= '<li>' . ico_public('M6.2 2.8l2 3.4-1.5 1.5a10 10 0 0 0 5.6 5.6l1.5-1.5 3.4 2-.8 2.6c-.2.6-.8 1-1.4.9C8.6 16.6 3.4 11.4 2.7 5c-.1-.6.3-1.2.9-1.4z') . '<a href="tel:' . h($c['telephone']) . '">' . h(telephone_lisible((string)$c['telephone'])) . '</a></li>';
    }
    $hor = horaires_publics($c);
    if ($hor) {
        $h .= '<li>' . ico_public('M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM10 6v4l3 2') . '<div class="horaires">';
        foreach ($hor as [$j, $p]) {
            $h .= '<div><span>' . h($j) . '</span><b>' . h($p) . '</b></div>';
        }
        $h .= '</div></li>';
    }
    $h .= '</ul></section>';
    if ($v['atouts']) {
        $h .= '<section class="carte infos"><h2>Pourquoi nous choisir</h2><ul class="atouts">';
        foreach ($v['atouts'] as $a) {
            $h .= '<li>' . ico_public('M4 10.5l4 4 8-9') . h($a) . '</li>';
        }
        $h .= '</ul></section>';
    }
    return $h;
}

/** Bandeau d'accueil : accroche, texte, bouton vers la réservation, garanties. */
function bandeau_accueil(array $c, array $v): string
{
    $photo = image_url($c, 'couverture');
    $h = '<section class="accueil' . ($photo !== '' ? ' avec-photo" style="--photo:url(&quot;' . h($photo) . '&quot;);--cadrage:' . h(cadrage_css($c)) : '') . '"><div class="int"><p class="surtitre">Rendez-vous en ligne</p><h1>' . h($v['accroche']) . '</h1><p class="accueil-texte">' . h($v['presentation']) . '</p>'
        . '<a class="btn clair" href="#reserver">Choisir mon créneau</a><ul class="garanties">';
    foreach (garanties($c) as [$ico, $lib]) {
        $h .= '<li>' . ico_public($ico) . h($lib) . '</li>';
    }
    return $h . '</ul></div></section>';
}

function page_patient(array $c, string $titre, string $contenu, bool $assistant = false, string $avant = '', bool $large = false): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . h($titre) . ' · ' . h($c['nom']) . '</title><link rel="stylesheet" href="p.css">' . style_client($c) . '</head><body' . ($large ? ' class="large"' : '') . '>'
        . entete_client($c) . $avant
        . '<main class="int">' . $contenu . '</main>'
        . '<footer class="int pied">Rendez-vous en ligne opéré par SALW CONSULTING pour ' . h($c['nom']) . '. Vos données servent uniquement à gérer vos rendez-vous.'
        . (metier($c)['urgence'] ? ' Urgence : ' . ($c['pays'] === 'BE' ? '112' : '15 ou 112') . '.' : '') . '</footer>';
    if ($assistant) {
        echo '<div class="assistant" id="assistant" data-clinique="' . h($c['slug']) . '"><button class="assistant-bouton" id="assistant-ouvrir" aria-expanded="false" aria-controls="assistant-fenetre">Une question ?</button>'
            . '<section class="assistant-fenetre" id="assistant-fenetre" hidden aria-label="Assistant en ligne"><header><b>Assistant en ligne</b><span>Questions pratiques, 24 h sur 24' . (metier($c)['urgence'] ? ' · pas d\'avis médical' : '') . '</span></header>'
            . '<div class="assistant-fil" id="assistant-fil" aria-live="polite"><p class="a-sam">Bonjour, je réponds aux questions pratiques : horaires, accès, documents, rendez-vous.' . (metier($c)['urgence'] ? ' Pour une urgence, appelez le ' . ($c['pays'] === 'BE' ? '112' : '15') . '.' : '') . '</p></div>'
            . '<form id="assistant-form"><label class="sr" for="assistant-saisie">Votre question</label><input id="assistant-saisie" maxlength="500" placeholder="Ex. Où se garer ?" autocomplete="off"><button>Envoyer</button></form></section></div>';
    }
    echo '<script src="p.js"></script></body></html>';
    exit;
}

/** Couleur du client : remplace l'accent SALW sur ses pages (boutons, sélections, assistant). */
function style_client(array $c): string
{
    if ((string)$c['couleur'] === '') {
        return '';
    }
    $a = couleur_accent($c);
    return '<style>:root{--accent:' . $a . ';--accent-c:' . couleur_claire($a) . '}</style>';
}

/** En-tête : bandeau sombre SALW par défaut ; bandeau clair avec le logo dès que le client a une image. */
function entete_client(array $c): string
{
    $adresse = '<span>' . h(trim($c['adresse'] . ', ' . $c['ville'], ', ')) . '</span>';
    $logo = logo_url($c);
    if ($logo === '' && (string)$c['couleur'] === '') {
        return '<header class="tete"><div class="int"><b>' . h($c['nom']) . '</b>' . $adresse . '</div></header>';
    }
    return '<header class="tete marque-client"><div class="int">' . ($logo !== '' ? '<img src="' . h($logo) . '" alt="" class="logo">' : '')
        . '<div><b>' . h($c['nom']) . '</b>' . $adresse . '</div></div></header>';
}

function page_erreur(string $titre, string $texte): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($titre) . '</title><link rel="stylesheet" href="p.css"></head><body><main class="int"><div class="carte"><h1>' . h($titre) . '</h1><p>' . h($texte) . '</p></div></main></body></html>';
    exit;
}

/** Limite d'actions par adresse IP et par heure (réservations, messages à l'assistant). */
function limite_ip(string $action, int $max): bool
{
    $cle = $action . ':' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')) . ':' . gmdate('YmdH');
    db()->prepare("INSERT INTO compteurs (cle, valeur, maj) VALUES (?, 1, ?) ON CONFLICT(cle) DO UPDATE SET valeur = valeur + 1")->execute([$cle, gmdate('Y-m-d')]);
    db()->prepare("DELETE FROM compteurs WHERE cle LIKE ? AND maj < ?")->execute([$action . ':%', gmdate('Y-m-d', time() - 2 * 86400)]);
    return (int)valeur('SELECT valeur FROM compteurs WHERE cle = ?', [$cle]) <= $max;
}

function jeton_csrf_public(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('salw_patient');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
    }
    if (empty($_SESSION['csrf_p'])) {
        $_SESSION['csrf_p'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf_p'];
}

function csrf_public_valide(): bool
{
    return hash_equals(jeton_csrf_public(), (string)($_POST['csrf'] ?? ''));
}
