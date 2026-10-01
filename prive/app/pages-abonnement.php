<?php
/**
 * Plateforme SALW : abonnement du client (formule au volume, prix, consommation du mois, historique).
 * Le responsable consulte ; seule l'équipe SALW modifie la formule, l'engagement et le prix négocié.
 */

declare(strict_types=1);

function page_abonnement(): void
{
    if (!peut_gerer()) {
        interdit();
    }
    $c = clinique_courante();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && est_salw() && ($_POST['action'] ?? '') === 'formule') {
        [$f, $engagement, $prix] = lire_offre($_POST);
        executer('UPDATE cliniques SET formule = ?, engagement = ?, prix_negocie = ? WHERE id = ?', [$f, $engagement, $prix, $c['id']]);
        journaliser('abonnement_modifie', ($f ?: 'automatique') . ' · ' . ($prix > 0 ? $prix . ' €' : 'grille'), (int)$c['id']);
        flash('ok', 'Abonnement enregistré.');
        aller('abonnement');
    }
    $c = clinique((int)$c['id']);
    $k = consommation($c);
    $f = $k['formule'];
    $prix = prix_mensuel($c);
    $conseil = formule_conseillee($c, $k);
    $metier = (string)$c['metier'];

    entete('Abonnement', 'abonnement');
    titre('Abonnement', 'Formule, consommation du mois et prix, en euros hors taxes. Toutes les automatisations sont incluses dans toutes les formules : seul le volume change.');

    // Formule et prix.
    $details = isset(FORMULES[$f])
        ? 'Jusqu\'à ' . FORMULES[$f]['pros_max'] . ' ' . mot($c, 'pros') . ' · ' . number_format(FORMULES[$f]['sms'], 0, ',', ' ') . ' SMS inclus par mois'
        : 'Au-delà de ' . FORMULES['structure']['pros_max'] . ' ' . mot($c, 'pros') . ' : volume et prix fixés au cas par cas';
    $mentions = [];
    if ((float)$c['prix_negocie'] > 0) {
        $mentions[] = 'prix négocié (grille : ' . (isset(FORMULES[$f]) ? euros((float)prix_formule($f, $metier)) : 'sur mesure') . ')';
    } elseif (isset(FORMULES[$f])) {
        $mentions[] = (int)$c['engagement'] ? 'engagement 12 mois' : 'sans engagement (+' . (int)(MAJORATION_SANS_ENGAGEMENT * 100) . ' %)';
    }
    if ((string)$c['formule'] === '') {
        $mentions[] = "formule choisie automatiquement selon la taille de l'équipe";
    }
    echo '<section class="carte heros-kpi"><div><span class="tuile-lib">Formule</span><span class="chiffre-heros">' . h(libelle_formule($f)) . '</span><span class="tuile-det">' . h($details) . '</span></div>'
        . '<div><span class="tuile-lib">Abonnement mensuel</span><span class="chiffre-heros">' . ($prix > 0 ? h(montant($prix)) : 'À fixer') . '</span><span class="tuile-det">' . h(ucfirst(implode(' · ', $mentions))) . '</span></div></section>';

    // Consommation du mois.
    echo '<div class="grille-2"><section class="carte"><h2>Consommation de ' . h(mois_libelle($k['mois'])) . ($k['en_cours'] ? ' (en cours)' : '') . '</h2>';
    if ($k['quota']) {
        echo jauge('SMS envoyés', $k['sms'], $k['quota'], 'SMS',
            $k['depassement'] ? $k['depassement'] . ' SMS au-delà du forfait, soit ' . euros_centimes($k['cout_depassement']) . ' (' . euros_centimes(SMS_SUPPLEMENT) . ' l\'unité). Les envois ne sont jamais coupés.'
                : ($k['en_cours'] && $k['projection'] > $k['quota'] ? 'À ce rythme : environ ' . $k['projection'] . ' SMS en fin de mois, soit ' . euros_centimes($k['cout_projete']) . ' de dépassement.'
                : ($k['en_cours'] ? 'À ce rythme : environ ' . $k['projection'] . ' SMS en fin de mois, dans le forfait.' : 'Dans le forfait.')));
        echo jauge(ucfirst(mot($c, 'pros')) . ' actifs', $k['pros'], $k['pros_max'], '', $k['pros'] > $k['pros_max'] ? 'Plus que prévu par la formule.' : '');
    } else {
        echo '<p>' . $k['sms'] . ' SMS envoyés ce mois-ci · ' . $k['pros'] . ' ' . h(mot($c, 'pros')) . ' actifs.</p>';
    }
    echo '<p class="petit">Les SMS sont comptés comme l\'opérateur les facture : un message long ou accentué compte pour plusieurs SMS.' . (cfg('mode_envoi') !== 'reel' ? ' Mode simulation : les SMS simulés sont comptés pour montrer la consommation.' : '') . '</p>';
    if ($conseil) {
        echo '<div class="msg info">' . (($conseil['raison'] ?? '') === 'pros'
            ? 'Avec ' . $k['pros'] . ' ' . h(mot($c, 'pros')) . ', la formule adaptée est <b>' . h(libelle_formule($conseil['formule'])) . '</b>.'
            : 'Pour ce volume, la formule <b>' . h(libelle_formule($conseil['formule'])) . '</b> reviendrait à ' . h(euros($conseil['cout'])) . ' par mois dépassements compris, soit ' . h(euros($conseil['economie'])) . ' de moins.') . '</div>';
    }
    echo '</section>';

    // Grille du métier.
    echo '<section class="carte"><h2>Formules · ' . h(metier($c)['libelle']) . '</h2><div class="table"><table><thead><tr><th>Formule</th><th>' . h(ucfirst(mot($c, 'pros'))) . '</th><th>SMS inclus</th><th>Par mois</th><th>Mise en place</th></tr></thead><tbody>';
    foreach (FORMULES as $cle => $d) {
        echo '<tr' . ($cle === $f ? ' class="ligne-active"' : '') . '><td><b>' . h($d['libelle']) . '</b>' . ($cle === $f ? ' ' . badge('actuelle', 'vert') : '') . '</td><td>jusqu\'à ' . $d['pros_max'] . '</td><td>' . number_format($d['sms'], 0, ',', ' ') . '</td><td>' . h(euros((float)prix_formule($cle, $metier))) . '</td><td>' . h(euros((float)$d['mise_en_place'])) . '</td></tr>';
    }
    echo '<tr' . ($f === 'sur_mesure' ? ' class="ligne-active"' : '') . '><td><b>Sur mesure</b></td><td>plus de ' . FORMULES['structure']['pros_max'] . '</td><td>à définir</td><td>sur devis</td><td>sur devis</td></tr></tbody></table></div>'
        . '<p class="petit">Prix avec engagement de 12 mois ; sans engagement : +' . (int)(MAJORATION_SANS_ENGAGEMENT * 100) . ' %. SMS au-delà du forfait : ' . euros_centimes(SMS_SUPPLEMENT) . ' l\'unité.'
        . (est_salw() ? ' Coefficient du métier : ×' . str_replace('.', ',', (string)coef_metier($metier)) . ' (valeur d\'un rendez-vous), la mise en place n\'est pas ajustée.' : '') . '</p></section></div>';

    // Historique.
    echo '<section class="carte"><h2>Six derniers mois</h2><div class="table"><table><thead><tr><th>Mois</th><th>SMS</th><th>Forfait</th><th>Dépassement</th><th>Coût du dépassement</th></tr></thead><tbody>';
    $m = (new DateTimeImmutable('@' . maintenant($c)))->setTimezone(tz($c))->modify('first day of this month');
    for ($i = 0; $i < 6; $i++) {
        $x = consommation($c, $m->modify('-' . $i . ' month')->format('Y-m'));
        echo '<tr><td>' . h(ucfirst(mois_libelle($x['mois']))) . ($x['en_cours'] ? ' <span class="gris">(en cours)</span>' : '') . '</td><td>' . $x['sms'] . '</td><td>' . ($x['quota'] ?: '–') . '</td><td>' . ($x['depassement'] ?: '–') . '</td><td>' . ($x['depassement'] ? h(euros_centimes($x['cout_depassement'])) : '–') . '</td></tr>';
    }
    echo '</tbody></table></div><p class="petit">Le forfait appliqué à chaque mois est celui de la formule actuelle.</p></section>';

    // Modification (équipe SALW).
    if (est_salw()) {
        echo '<section class="carte" id="changer"><h2>Changer d\'offre <span class="badge orange">équipe SALW</span></h2><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="formule">'
            . cartes_formules($metier, (string)$c['formule'], true) . champs_engagement((int)$c['engagement'], (float)$c['prix_negocie'])
            . '<button class="btn">Enregistrer</button></form></section>';
    } else {
        echo '<p class="petit">Pour changer de formule, contactez SALW CONSULTING.</p>';
    }
    pied();
}

/**
 * Choix de l'offre en cartes : agendas, SMS inclus, prix du métier, mise en place.
 * Les prix suivent le métier choisi dans le même formulaire (app.js lit data-prix).
 * $auto : ajoute la carte « Automatique » (formule selon le nombre d'agendas).
 */
function cartes_formules(string $metier, string $choisie, bool $auto = false): string
{
    $prix = [];
    foreach (array_keys(metiers()) as $m) {
        foreach (FORMULES as $f => $d) {
            $prix[$m][$f] = prix_formule($f, $m);
        }
    }
    $h = '<fieldset class="offres-choix" data-prix="' . h((string)json_encode($prix)) . '"><legend>Offre</legend>';
    $carte = function (string $val, string $titre, array $lignes, string $prixTxt, string $sous) use ($choisie): string {
        return '<label class="offre-carte"><input type="radio" name="formule" value="' . h($val) . '"' . ($val === $choisie ? ' checked' : '') . ' required>'
            . '<span class="offre-corps"><b class="offre-nom">' . h($titre) . '</b>'
            . ($prixTxt !== '' ? '<span class="offre-prix" data-formule="' . h($val) . '">' . h($prixTxt) . '</span><small class="offre-sous">' . h($sous) . '</small>' : '<small class="offre-sous">' . h($sous) . '</small>')
            . '<span class="offre-lignes">' . implode('', array_map(function ($l) { return '<span>' . h($l) . '</span>'; }, $lignes)) . '</span></span></label>';
    };
    $pros = 0;
    foreach (FORMULES as $f => $d) {
        $h .= $carte($f, $d['libelle'], [($pros + 1) . ' à ' . $d['pros_max'] . ' agendas', number_format($d['sms'], 0, ',', ' ') . ' SMS inclus par mois', 'Mise en place : ' . euros((float)$d['mise_en_place']), 'Toutes les automatisations'],
            euros((float)prix_formule($f, $metier)), 'HT par mois, engagement 12 mois');
        $pros = $d['pros_max'];
    }
    $h .= $carte('sur_mesure', 'Sur mesure', ['Plus de ' . FORMULES['structure']['pros_max'] . ' agendas ou plusieurs sites', 'Volume de SMS à définir', 'Mise en place sur devis'], '', 'Prix à fixer : saisissez le prix négocié');
    if ($auto) {
        $h .= $carte('', 'Automatique', ['Suit le nombre d\'agendas actifs', 'Change seule si l\'équipe grandit'], '', 'Formule déduite de la taille de l\'équipe');
    }
    return $h . '</fieldset>';
}

/** Lit l'offre envoyée par un formulaire : [formule, engagement, prix négocié]. */
function lire_offre(array $post): array
{
    $f = (string)($post['formule'] ?? '');
    $f = isset(FORMULES[$f]) || $f === 'sur_mesure' ? $f : '';
    $prix = max(0, round((float)str_replace([',', ' '], ['.', ''], (string)($post['prix_negocie'] ?? '0')), 2));
    return [$f, !empty($post['engagement']) ? 1 : 0, $prix];
}

/** Engagement et prix négocié, communs à la création et à la modification. */
function champs_engagement(int $engagement, float $prixNegocie): string
{
    return '<div class="champ-duo"><label class="case"><input type="checkbox" name="engagement" value="1"' . ($engagement ? ' checked' : '') . '> Engagement de 12 mois (sans engagement : +' . (int)(MAJORATION_SANS_ENGAGEMENT * 100) . ' % sur la grille)</label>'
        . '<label>Prix négocié (€ HT par mois, facultatif)<input name="prix_negocie" inputmode="decimal" value="' . ($prixNegocie > 0 ? h(str_replace('.', ',', (string)$prixNegocie)) : '') . '" placeholder="Vide : prix de la grille"><small>Pilote, remise contre témoignage, ou offre sur mesure. Remplace la grille.</small></label></div>';
}

/** Jauge de consommation : barre, valeur, état écrit (jamais la couleur seule). */
function jauge(string $lib, int $v, int $max, string $unite, string $note): string
{
    $taux = $max > 0 ? $v / $max : 0;
    [$etat, $ton] = $taux > 1 ? ['Dépassé', 'depasse'] : ($taux >= 0.8 ? ['Bientôt atteint', 'attention'] : ['Dans le forfait', 'ok']);
    return '<div class="jauge-bloc"><div class="jauge-tete"><span>' . h($lib) . '</span><b>' . number_format($v, 0, ',', ' ') . ' / ' . number_format($max, 0, ',', ' ') . ($unite !== '' ? ' ' . h($unite) : '') . '</b></div>'
        . '<div class="jauge ' . $ton . '" role="meter" aria-valuemin="0" aria-valuemax="' . $max . '" aria-valuenow="' . $v . '" aria-label="' . h($lib) . '"><span style="width:' . round(min(1, $taux) * 100, 1) . '%"></span></div>'
        . '<div class="jauge-pied"><span class="etat ' . $ton . '">' . ($ton === 'ok' ? '✓' : '!') . ' ' . h($etat) . '</span>' . ($note !== '' ? ' <span class="gris">' . h($note) . '</span>' : '') . '</div></div>';
}

function euros_centimes(float $n): string
{
    return number_format($n, 2, ',', ' ') . ' €';
}
