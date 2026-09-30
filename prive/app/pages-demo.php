<?php
/**
 * Plateforme SALW Santé : page de démonstration (scénario guidé et horloge).
 */

declare(strict_types=1);

function page_demo(): void
{
    $c = clinique_courante();
    // Passage d'une démonstration à l'autre (équipe SALW).
    if (est_salw() && isset($_GET['metier']) && array_key_exists($_GET['metier'], metiers())) {
        $demo = clinique_par_slug(slug_demo((string)$_GET['metier'])) ?: creer_demo((string)$_GET['metier']);
        $_SESSION['clinique_id'] = (int)$demo['id'];
        aller('demo');
    }
    if (!$c || ((int)$c['demo'] !== 1)) {
        if (est_salw()) {
            $demo = clinique_par_slug(slug_demo(($c['metier'] ?? '') ?: 'sante')) ?: clinique_par_slug(slug_demo('sante'));
            if ($demo) {
                $_SESSION['clinique_id'] = (int)$demo['id'];
                aller('demo');
            }
        }
        entete('Démonstration', 'demo');
        titre('Démonstration', "Aucune démonstration : créez-la depuis « Clients (SALW) ».");
        pied();
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'etape') {
            $n = (int)($_POST['n'] ?? 0);
            $s = etat_scenario($c);
            if ($n !== ((int)($s['etape'] ?? 0)) + 1) {
                flash('erreur', 'Jouez les étapes dans l\'ordre (ou recommencez la démonstration).');
            } else {
                [$fait, $ids] = jouer_etape($c, $n);
                $_SESSION['demo_resultat'] = ['n' => $n, 'fait' => $fait, 'ids' => $ids];
            }
        } elseif ($a === 'horloge') {
            $delta = ['h' => 3600, 'j' => 86400][(string)($_POST['pas'] ?? '')] ?? 0;
            $c = avancer_horloge($c, maintenant($c) + $delta);
            $bilan = executer_automatisations($c);
            flash('ok', 'Horloge avancée. ' . ($bilan ? 'Automatisations déclenchées : ' . implode(' · ', array_slice($bilan, 0, 6)) . (count($bilan) > 6 ? '…' : '') : 'Aucune automatisation à ce moment-là.'));
        } elseif ($a === 'recommencer') {
            $c = creer_demo((string)$c['metier']);
            $_SESSION['clinique_id'] = (int)$c['id'];
            unset($_SESSION['scenario'][$c['id']], $_SESSION['demo_resultat']);
            flash('ok', 'Démonstration remise à zéro.');
        }
        aller('demo');
    }

    $c = clinique((int)$c['id']);
    $s = etat_scenario($c);
    $faite = (int)($s['etape'] ?? 0);
    $etapes = etapes_scenario($c);
    $res = $_SESSION['demo_resultat'] ?? null;
    entete('Démonstration', 'demo');
    titre('Démonstration · ' . metier($c)['libelle'], $c['nom'] . ' · une semaine en ' . count($etapes) . ' étapes. Le moteur est réel ; seule l\'horloge avance, et les SMS s\'affichent au lieu de partir.',
        '<form method="post" data-confirmer="Remettre la démonstration à zéro ?">' . champ_csrf() . '<input type="hidden" name="action" value="recommencer"><button class="btn contour">Recommencer</button></form>');

    if (est_salw()) {
        echo '<nav class="filtres" aria-label="Métier de la démonstration">';
        foreach (metiers() as $k => $m) {
            echo '<a href="' . h(url('demo', ['metier' => $k])) . '"' . ($k === $c['metier'] ? ' aria-current="true"' : '') . '>' . h($m['libelle']) . '</a>';
        }
        echo '</nav>';
    }
    echo '<section class="carte horloge"><div><span class="tuile-lib">Horloge de la démonstration</span><span class="chiffre">' . h(date_longue($c, iso(maintenant($c))) . ', ' . local($c, iso(maintenant($c)), 'H:i')) . '</span></div>'
        . '<form method="post" class="ligne">' . champ_csrf() . '<input type="hidden" name="action" value="horloge"><button class="btn contour" name="pas" value="h">+ 1 heure</button><button class="btn contour" name="pas" value="j">+ 1 jour</button></form></section>';

    echo '<div class="scenario">';
    foreach ($etapes as $n => [$titre, $texte, $bouton]) {
        $etat = $n <= $faite ? 'faite' : ($n === $faite + 1 ? 'suivante' : 'a-venir');
        echo '<section class="carte etape ' . $etat . '"><div class="etape-num">' . ($n <= $faite ? '✓' : $n) . '</div><div class="etape-corps"><h2>' . h($titre) . '</h2><p>' . h($texte) . '</p>';
        if ($etat === 'suivante') {
            echo '<form method="post">' . champ_csrf() . '<input type="hidden" name="action" value="etape"><input type="hidden" name="n" value="' . $n . '"><button class="btn">' . h($bouton) . ' ▶</button></form>';
        }
        if ($res && (int)$res['n'] === $n) {
            echo '<div class="resultat"><h3>Ce qui s\'est passé</h3><ul class="liste-simple">';
            foreach ($res['fait'] ?: ['Aucune action automatique à ce moment-là.'] as $l) {
                echo '<li>' . h($l) . '</li>';
            }
            echo '</ul>';
            if ($res['ids']) {
                $msgs = toutes('SELECT * FROM messages WHERE id IN (' . implode(',', array_map('intval', $res['ids'])) . ') ORDER BY id');
                // Les messages du scénario d'abord, puis les autres envois du même moment.
                $cles = ['Camille', 'Lucas', 'Inès', 'Hugo', 'Nadia', 'Julien', 'Martine', 'Karim'];
                usort($msgs, function ($a, $b) use ($cles) {
                    $pa = $a['patient_id'] ? (string)valeur('SELECT prenom FROM patients WHERE id = ?', [$a['patient_id']]) : 'Nadia';
                    $pb = $b['patient_id'] ? (string)valeur('SELECT prenom FROM patients WHERE id = ?', [$b['patient_id']]) : 'Nadia';
                    return (in_array($pa, $cles, true) ? 0 : 1) <=> (in_array($pb, $cles, true) ? 0 : 1) ?: $a['id'] <=> $b['id'];
                });
                echo '<h3>SMS reçus (' . count($msgs) . ')</h3><div class="telephones">';
                foreach (array_slice($msgs, 0, 6) as $m) {
                    echo bulle_message($c, $m);
                }
                echo '</div>' . (count($msgs) > 6 ? '<p class="petit">Et ' . (count($msgs) - 6) . ' autre(s) message(s) envoyé(s) au même moment : voir « Messages envoyés ».</p>' : '');
            }
            echo '</div>';
        }
        echo '</div></section>';
    }
    echo '</div>';
    if ($faite >= count($etapes)) {
        echo '<section class="carte fin"><h2>Et pour ' . h($c['nom']) . ' ?</h2><p>Tout ce qui précède s\'est fait sans un appel de l\'accueil : un créneau annulé repris, un appel manqué transformé en rendez-vous, un client absent relancé, un ancien client réactivé' . (reglages($c)['relance_devis']['actif'] ? ', un devis relancé puis accepté' : '') . '. Le tableau de bord l\'additionne.</p>'
            . '<a class="btn" href="' . h(url('tableau')) . '">Voir le tableau de bord</a> <a class="btn contour" href="' . h(lien_reservation($c)) . '" target="_blank" rel="noopener">Page de réservation des clients ↗</a></section>';
    }
    pied();
}
