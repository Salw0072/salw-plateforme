<?php
/**
 * Plateforme SALW Santé : mise en page de l'espace de travail.
 */

declare(strict_types=1);

function url(string $page, array $p = []): string
{
    return './?' . http_build_query(array_merge(['p' => $page], $p));
}

function aller(string $page, array $p = []): void
{
    header('Location: ' . url($page, $p));
    exit;
}

function flash(string $type, string $texte): void
{
    $_SESSION['flash'] = [$type, $texte];
}

function badge(string $texte, string $ton = ''): string
{
    return '<span class="badge ' . h($ton) . '">' . h($texte) . '</span>';
}

function ico(string $d): string
{
    return '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

function entete(string $titre, string $actif): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . h($titre) . ' · Plateforme SALW</title><link rel="stylesheet" href="app.css"></head><body>';
    $u = moi();
    if (!$u) {
        echo '<main class="seul">';
        return;
    }
    $c = clinique_courante();
    $cv = $c ?: ['metier' => 'sante'];
    $nav = [
        ['tableau', 'Tableau de bord', 'M3 10.5L10 4l7 6.5V17h-5v-4.5H8V17H3z', true],
        ['agenda', 'Agenda', 'M3.5 5h13v11.5h-13zM3.5 8.5h13M7 3v4M13 3v4', true],
        ['patients', mot($cv, 'Clients'), 'M7.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM2.5 16c.5-3 2.5-4.5 5-4.5s4.5 1.5 5 4.5M13.5 9a2 2 0 1 0 0-4M14.5 11.8c1.6.4 2.7 1.7 3 4.2', true],
        ['attente', "Liste d'attente", 'M4 5h12M4 10h12M4 15h7', true],
        ['appels', 'Appels manqués', 'M6.2 2.8l2 3.4-1.5 1.5a10 10 0 0 0 5.6 5.6l1.5-1.5 3.4 2-.8 2.6c-.2.6-.8 1-1.4.9C8.6 16.6 3.4 11.4 2.7 5c-.1-.6.3-1.2.9-1.4z', true],
        ['devis', 'Devis', 'M5 3h10v14H5zM7.5 7h5M7.5 10h5M7.5 13h3', $c && (reglages($c)['relance_devis']['actif'] || (int)valeur('SELECT COUNT(*) FROM devis WHERE clinique_id = ?', [$c['id']]) > 0)],
        ['messages', 'Messages envoyés', 'M3 5.5h14v9H3zM3 5.5l7 5 7-5', true],
        ['automatisations', 'Automatisations', 'M11 2L4 11h5l-1 7 7-9h-5z', peut_gerer()],
        ['clinique', mot($cv, 'Structure'), 'M3 17V7l7-4 7 4v10M8 17v-5h4v5', peut_gerer()],
        ['integration', 'Page publique', 'M3 4h14v12H3zM3 7.5h14M6 11h4', peut_gerer()],
        ['reseaux', 'Réseaux sociaux', 'M5 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM15 3a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM15 13a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM6.8 9l6.4-3.2M6.8 11l6.4 3.2', peut_gerer()],
        ['rapport', 'Rapport mensuel', 'M4 16V9M8.5 16V5M13 16v-4M17 16H3', peut_gerer()],
        ['abonnement', 'Abonnement', 'M3 6h14v9H3zM3 9h14M6 12.5h3', peut_gerer()],
        ['equipe', 'Équipe', 'M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM4 17c.6-3.4 3-5 6-5s5.4 1.6 6 5', peut_gerer()],
        ['demo', 'Démonstration', 'M6 4l10 6-10 6z', $c && ((int)$c['demo'] === 1 || est_salw())],
        ['cliniques', 'Clients (SALW)', 'M3 4h6v6H3zM11 4h6v6h-6zM3 12h6v5H3zM11 12h6v5h-6z', est_salw()],
    ];
    echo '<div class="cadre"><aside class="nav" id="nav"><a class="marque" href="' . h(url('tableau')) . '">SALW <span>PLATEFORME</span></a>';
    if ($c) {
        echo '<div class="clinique-nom">' . h($c['nom']) . '<small class="metier">' . h(metier($c)['libelle']) . '</small>' . ((int)$c['demo'] === 1 ? '<small>Horloge de démonstration : ' . h(local($c, iso(maintenant($c)), 'd/m H:i')) . '</small>' : '') . '</div>';
    }
    echo '<nav aria-label="Navigation"><ul>';
    foreach ($nav as $n) {
        if (!$n[3]) {
            continue;
        }
        echo '<li><a href="' . h(url($n[0])) . '"' . ($n[0] === $actif ? ' aria-current="page"' : '') . '>' . ico($n[2]) . '<span>' . h($n[1]) . '</span></a></li>';
    }
    echo '</ul></nav><div class="nav-pied"><a href="' . h(url('compte')) . '"><b>' . h($u['nom'] ?: $u['identifiant']) . '</b><small>' . h(ROLES[$u['role']]) . '</small></a>'
        . '<form method="post" action="' . h(url('deconnexion')) . '">' . champ_csrf() . '<button class="lien">Se déconnecter</button></form></div></aside>'
        . '<div class="contenu"><header class="barre"><button class="menu" id="menu" aria-expanded="false" aria-controls="nav">' . ico('M3 5h14M3 10h14M3 15h14') . '<span class="sr">Menu</span></button>'
        . ($c ? '<a href="' . h(lien_reservation($c)) . '" target="_blank" rel="noopener">Page de réservation ↗</a>' : '') . '</header><main>';
    if (cfg('mode_envoi') !== 'reel' || ($c && (int)$c['demo'])) {
        echo '<div class="msg info">' . (cfg('mode_envoi') === 'reel' ? 'Démonstration : toujours en simulation, ' : 'Mode simulation : ') . 'aucun SMS ni e-mail ne part. Les messages s\'affichent dans « Messages envoyés ».</div>';
    }
    if (!empty($_SESSION['flash'])) {
        [$t, $m] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="msg ' . h($t) . '" role="status">' . h($m) . '</div>';
    }
}

function pied(): void
{
    echo moi() ? '</main></div></div>' : '</main>';
    echo '<script src="app.js"></script></body></html>';
}

function titre(string $t, string $sous = '', string $actions = ''): void
{
    echo '<div class="titre-page"><div><h1>' . h($t) . '</h1>' . ($sous !== '' ? '<p>' . h($sous) . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="actions">' . $actions . '</div>' : '') . '</div>';
}

function interdit(): void
{
    http_response_code(403);
    entete('Accès refusé', '');
    titre('Accès refusé', "Votre rôle ne donne pas accès à cette page.");
    pied();
    exit;
}

/** Un message tel que le patient le voit sur son téléphone ; les liens restent cliquables. */
function bulle_message(array $c, array $m, bool $avecEntete = true): string
{
    $texte = h($m['contenu']);
    $texte = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" target="_blank" rel="noopener">$1</a>', $texte);
    $patient = $m['patient_id'] ? une('SELECT prenom, nom FROM patients WHERE id = ?', [$m['patient_id']]) : null;
    return '<article class="tel">' . ($avecEntete ? '<div class="tel-tete"><b>' . h($patient ? $patient['prenom'] . ' ' . $patient['nom'] : telephone_lisible($m['destinataire'])) . '</b><span>' . h($m['canal'] === 'sms' ? telephone_lisible($m['destinataire']) : $m['destinataire']) . '</span></div>' : '')
        . '<div class="tel-corps"><span class="tel-exp">' . h($c['nom']) . '</span><p class="sms">' . $texte . '</p><span class="tel-meta">' . h(LIBELLES_MESSAGES[$m['type']] ?? $m['type']) . ' · ' . h(local($c, $m['envoye_le'], 'd/m H:i'))
        . ' · ' . ($m['canal'] === 'sms' ? segments_sms($m['contenu']) . ' SMS' : ($m['canal'] === 'whatsapp' ? 'WhatsApp' : 'e-mail')) . ' · ' . (['simule' => 'simulé', 'envoye' => 'envoyé', 'distribue' => 'distribué', 'lu' => 'lu', 'echec' => 'échec'][$m['statut']] ?? $m['statut']) . '</span></div></article>';
}

function ton_statut(string $s): string
{
    return ['confirme' => 'bleu', 'honore' => 'vert', 'absent' => 'rouge', 'annule' => 'gris'][$s] ?? '';
}

const LIBELLES_STATUTS = ['confirme' => 'Confirmé', 'honore' => 'Honoré', 'absent' => 'Absent', 'annule' => 'Annulé'];
