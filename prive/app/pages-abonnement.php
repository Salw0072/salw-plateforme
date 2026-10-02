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
    $action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';
    if ($action === 'formule' && est_salw()) {
        [$f, $engagement, $prix, $offerte] = lire_offre($_POST);
        executer('UPDATE cliniques SET formule = ?, engagement = ?, prix_negocie = ?, mise_en_place_offerte = ? WHERE id = ?', [$f, $engagement, $prix, $offerte, $c['id']]);
        journaliser('abonnement_modifie', ($f ?: 'automatique') . ' · ' . ($prix > 0 ? $prix . ' €' : 'grille'), (int)$c['id']);
        $message = 'Abonnement enregistré.';
        try {
            $suite = synchroniser_prix(clinique((int)$c['id']));
            $message .= $suite !== '' ? ' ' . $suite : '';
            flash('ok', $message);
        } catch (RuntimeException $e) {
            flash('erreur', $message . ' Mais le prix n\'a pas pu être mis à jour chez ' . ucfirst((string)$c['paiement_fournisseur']) . ' : ' . $e->getMessage());
        }
        aller('abonnement');
    }
    // Paiement en ligne : le client part chez Stripe ou PayPal, puis revient sur cette page.
    if (in_array($action, ['payer_stripe', 'payer_paypal', 'portail'], true)) {
        try {
            if ((int)$c['demo']) {
                throw new RuntimeException('structure de démonstration.');
            }
            $email = filter_var((string)$c['email'], FILTER_VALIDATE_EMAIL) ? (string)$c['email'] : (string)moi()['identifiant'];
            $vers = $action === 'payer_stripe' && stripe_actif() ? stripe_ouvrir_paiement($c, $email)
                : ($action === 'payer_paypal' && paypal_actif() ? paypal_ouvrir_abonnement($c, $email)
                : ($action === 'portail' && stripe_actif() && (string)$c['paiement_client_ref'] !== '' ? stripe_portail($c) : ''));
            if ($vers === '') {
                throw new RuntimeException('Ce moyen de paiement n\'est pas configuré.');
            }
            journaliser('paiement_' . $action, '', (int)$c['id']);
            header('Location: ' . $vers, true, 303);
            exit;
        } catch (RuntimeException $e) {
            flash('erreur', 'Paiement impossible pour le moment : ' . $e->getMessage());
            aller('abonnement');
        }
    }
    $retour = (string)($_GET['paiement'] ?? '');
    if ($retour !== '') {
        try {
            if ($retour === 'stripe' && stripe_actif()) {
                flash('ok', stripe_confirmer_retour($c, (string)($_GET['session'] ?? '')));
            } elseif ($retour === 'paypal' && paypal_actif()) {
                flash('ok', paypal_confirmer_retour($c, (string)($_GET['subscription_id'] ?? '')));
            } elseif ($retour === 'annule') {
                flash('info', 'Paiement interrompu : rien n\'a été prélevé. Vous pouvez reprendre quand vous voulez.');
            }
        } catch (RuntimeException $e) {
            flash('erreur', 'Le paiement n\'a pas pu être vérifié : ' . $e->getMessage() . ' Le statut sera mis à jour dès la confirmation du service de paiement.');
        }
        aller('abonnement');
    }
    $c = clinique((int)$c['id']);
    $k = consommation($c);
    $f = $k['formule'];
    $prix = prix_mensuel($c);
    $conseil = formule_conseillee($c, $k);
    $metier = (string)$c['metier'];

    entete('Abonnement', 'abonnement');
    titre('Abonnement', 'Formule, consommation du mois et prix, en euros hors taxes. Automatisations de base dans toutes les formules ; WhatsApp et réseaux sociaux à partir d\'Équipe.');

    // Formule et prix.
    $details = isset(FORMULES[$f])
        ? 'Jusqu\'à ' . FORMULES[$f]['pros_max'] . ' ' . mot($c, 'pros') . ' · ' . number_format(FORMULES[$f]['sms'], 0, ',', ' ') . ' ' . libelle_messages($f) . (AVANTAGES_FORMULES[$f] ? ' · ' . implode(' · ', AVANTAGES_FORMULES[$f]) : '')
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
    echo section_paiement($c);

    // Consommation du mois.
    echo '<div class="grille-2"><section class="carte"><h2>Consommation de ' . h(mois_libelle($k['mois'])) . ($k['en_cours'] ? ' (en cours)' : '') . '</h2>';
    if ($k['quota']) {
        echo jauge('Messages envoyés', $k['sms'], $k['quota'], 'messages',
            $k['depassement'] ? $k['depassement'] . ' messages au-delà du forfait, soit ' . euros_centimes($k['cout_depassement']) . ' (' . euros_centimes(SMS_SUPPLEMENT) . ' l\'unité). Les envois ne sont jamais coupés.'
                : ($k['en_cours'] && $k['projection'] > $k['quota'] ? 'À ce rythme : environ ' . $k['projection'] . ' messages en fin de mois, soit ' . euros_centimes($k['cout_projete']) . ' de dépassement.'
                : ($k['en_cours'] ? 'À ce rythme : environ ' . $k['projection'] . ' messages en fin de mois, dans le forfait.' : 'Dans le forfait.')));
        echo jauge(ucfirst(mot($c, 'pros')) . ' actifs', $k['pros'], $k['pros_max'], '', $k['pros'] > $k['pros_max'] ? 'Plus que prévu par la formule.' : '');
    } else {
        echo '<p>' . $k['sms'] . ' messages envoyés ce mois-ci · ' . $k['pros'] . ' ' . h(mot($c, 'pros')) . ' actifs.</p>';
    }
    echo '<p class="petit">Les SMS sont comptés comme l\'opérateur les facture : un message long ou accentué compte pour plusieurs SMS. Un WhatsApp compte pour un message.' . (cfg('mode_envoi') !== 'reel' ? ' Mode simulation : les SMS simulés sont comptés pour montrer la consommation.' : '') . '</p>';
    if ($conseil) {
        echo '<div class="msg info">' . (($conseil['raison'] ?? '') === 'pros'
            ? 'Avec ' . $k['pros'] . ' ' . h(mot($c, 'pros')) . ', la formule adaptée est <b>' . h(libelle_formule($conseil['formule'])) . '</b>.'
            : 'Pour ce volume, la formule <b>' . h(libelle_formule($conseil['formule'])) . '</b> reviendrait à ' . h(euros($conseil['cout'])) . ' par mois dépassements compris, soit ' . h(euros($conseil['economie'])) . ' de moins.') . '</div>';
    }
    echo '</section>';

    // Grille du métier.
    echo '<section class="carte"><h2>Formules · ' . h(metier($c)['libelle']) . '</h2><div class="table"><table><thead><tr><th>Formule</th><th>' . h(ucfirst(mot($c, 'pros'))) . '</th><th>Messages inclus</th><th>Réseaux sociaux</th><th>Par mois</th><th>Mise en place</th></tr></thead><tbody>';
    foreach (FORMULES as $cle => $d) {
        echo '<tr' . ($cle === $f ? ' class="ligne-active"' : '') . '><td><b>' . h($d['libelle']) . '</b>' . ($cle === $f ? ' ' . badge('actuelle', 'vert') : '') . '</td><td>jusqu\'à ' . $d['pros_max'] . '</td><td>' . number_format($d['sms'], 0, ',', ' ') . '</td><td>' . (AVANTAGES_FORMULES[$cle] ? implode('<br>', array_map('h', AVANTAGES_FORMULES[$cle])) : '<span class="gris">non inclus</span>') . '</td><td>' . h(euros((float)prix_formule($cle, $metier))) . '</td><td>' . h(euros((float)$d['mise_en_place'])) . '</td></tr>';
    }
    echo '<tr' . ($f === 'sur_mesure' ? ' class="ligne-active"' : '') . '><td><b>Sur mesure</b></td><td>plus de ' . FORMULES['structure']['pros_max'] . '</td><td>à définir</td><td>' . implode('<br>', array_map('h', AVANTAGES_FORMULES['sur_mesure'])) . '</td><td>sur devis</td><td>sur devis</td></tr></tbody></table></div>'
        . '<p class="petit">Prix avec engagement de 12 mois ; sans engagement : +' . (int)(MAJORATION_SANS_ENGAGEMENT * 100) . ' %. Message au-delà du forfait : ' . euros_centimes(SMS_SUPPLEMENT) . ' l\'unité.'
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
            . cartes_formules($metier, (string)$c['formule'], true) . champs_engagement((int)$c['engagement'], (float)$c['prix_negocie'], (int)$c['mise_en_place_offerte'])
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
        $essai = in_array($f, FORMULES_AVEC_ESSAI, true) ? [ESSAI_JOURS . ' jours d\'essai gratuit'] : [];
        $h .= $carte($f, $d['libelle'], array_merge([($pros + 1) . ' à ' . $d['pros_max'] . ' agendas', number_format($d['sms'], 0, ',', ' ') . ' ' . libelle_messages($f), 'Mise en place : ' . euros((float)$d['mise_en_place']), 'Automatisations de base'], AVANTAGES_FORMULES[$f], $essai),
            euros((float)prix_formule($f, $metier)), 'HT par mois, engagement 12 mois');
        $pros = $d['pros_max'];
    }
    $h .= $carte('sur_mesure', 'Sur mesure', array_merge(['Plus de ' . FORMULES['structure']['pros_max'] . ' agendas ou plusieurs sites', 'Volume de messages à définir', 'Mise en place sur devis'], AVANTAGES_FORMULES['sur_mesure'],
        in_array('sur_mesure', FORMULES_AVEC_ESSAI, true) ? [ESSAI_JOURS . ' jours d\'essai gratuit'] : []), '', 'Prix à fixer : saisissez le prix négocié');
    if ($auto) {
        $h .= $carte('', 'Automatique', ['Suit le nombre d\'agendas actifs', 'Change seule si l\'équipe grandit'], '', 'Formule déduite de la taille de l\'équipe');
    }
    return $h . '</fieldset>';
}

/** Lit l'offre envoyée par un formulaire : [formule, engagement, prix négocié, mise en place offerte]. */
function lire_offre(array $post): array
{
    $f = (string)($post['formule'] ?? '');
    $f = isset(FORMULES[$f]) || $f === 'sur_mesure' ? $f : '';
    $prix = max(0, round((float)str_replace([',', ' '], ['.', ''], (string)($post['prix_negocie'] ?? '0')), 2));
    return [$f, !empty($post['engagement']) ? 1 : 0, $prix, !empty($post['mise_en_place_offerte']) ? 1 : 0];
}

/** Engagement, mise en place offerte et prix négocié, communs à la création et à la modification. */
function champs_engagement(int $engagement, float $prixNegocie, int $miseEnPlaceOfferte = 0): string
{
    return '<div class="champ-duo"><div class="form"><label class="case"><input type="checkbox" name="engagement" value="1"' . ($engagement ? ' checked' : '') . '> Engagement de 12 mois (sans engagement : +' . (int)(MAJORATION_SANS_ENGAGEMENT * 100) . ' % sur la grille)</label>'
        . '<label class="case"><input type="checkbox" name="mise_en_place_offerte" value="1"' . ($miseEnPlaceOfferte ? ' checked' : '') . '> Mise en place offerte (client pilote, geste commercial)</label></div>'
        . '<label>Prix négocié (€ HT par mois, facultatif)<input name="prix_negocie" inputmode="decimal" value="' . ($prixNegocie > 0 ? h(str_replace('.', ',', (string)$prixNegocie)) : '') . '" placeholder="Vide : prix de la grille"><small>Pilote, remise contre témoignage, ou offre sur mesure. Remplace la grille.</small></label></div>';
}

/** Bloc « Paiement » : ce que le client paie, comment, et où il en est. */
function section_paiement(array $c): string
{
    $h = '<section class="carte paiement" id="paiement"><h2>Paiement</h2>';
    if ((int)$c['demo']) {
        return $h . '<p class="petit">Structure de démonstration : le paiement en ligne est désactivé.</p></section>';
    }
    if (!stripe_actif() && !paypal_actif()) {
        return $h . (est_salw()
            ? '<p>Paiement en ligne non configuré : renseignez les clés Stripe (et, en option, PayPal) dans <code>prive/config.php</code>. En attendant, facturez à la main.</p>'
            : '<p>SALW CONSULTING vous adresse ses factures. Le paiement en ligne sera bientôt disponible ici.</p>') . '</section>';
    }
    $r = recap_paiement($c);
    $statut = (string)$c['paiement_statut'];
    $f = (string)$c['paiement_fournisseur'];
    $ton = ['actif' => 'vert', 'impaye' => 'rouge', 'en_attente' => 'orange', 'essai' => 'bleu'][$statut] ?? 'gris';
    $h .= '<p>Statut : ' . badge(STATUTS_PAIEMENT[$statut] ?? $statut, $ton) . ($f !== '' && $statut !== '' ? ' <span class="gris">· ' . ($f === 'stripe' ? 'Stripe' : 'PayPal') . '</span>' : '') . '</p>';

    if (in_array($statut, ['actif', 'impaye', 'essai'], true)) {
        if ($statut === 'essai') {
            $fin = strtotime((string)$c['paiement_essai_fin']) ?: fin_essai_previsionnelle();
            $premiere = $r['mensuel'] + (float)(FORMULES[$r['formule']]['mise_en_place'] ?? 0) * ((int)$c['mise_en_place_offerte'] ? 0 : 1);
            $h .= '<div class="msg info">' . ($fin > time()
                    ? 'Essai gratuit jusqu\'au <b>' . h(date('d/m/Y', $fin)) . '</b> : aucun prélèvement avant cette date. Ensuite, premier prélèvement de ' . h(montant($premiere)) . ' HT, puis ' . h(montant($r['mensuel'])) . ' HT par mois. Résiliation possible d\'ici là sans rien payer.'
                    : 'Essai terminé le ' . h(date('d/m/Y', $fin)) . ' : premier prélèvement en cours.') . '</div>';
        }
        if ($statut === 'impaye') {
            $h .= '<div class="msg erreur">Le dernier prélèvement a échoué. ' . ($f === 'stripe' ? 'Mettez à jour le moyen de paiement ci-dessous : Stripe retentera automatiquement.' : 'Vérifiez le compte PayPal : PayPal retentera automatiquement.') . '</div>';
        }
        $h .= $f === 'stripe' && stripe_actif()
            ? form_paiement('portail', 'Moyen de paiement et factures', 'btn contour') . '<p class="petit">Portail sécurisé Stripe : carte ou compte bancaire, adresse de facturation, téléchargement des factures.</p>'
            : '<p class="petit">Abonnement géré depuis le compte PayPal du client (Paramètres › Paiements › Paiements automatiques). Les SMS au-delà du forfait sont facturés à part.</p>';
        $liste = toutes('SELECT * FROM paiements WHERE clinique_id = ? ORDER BY id DESC LIMIT 6', [$c['id']]);
        if ($liste) {
            $h .= '<h3 class="sep">Derniers mouvements</h3><ul class="liste-simple">';
            $libs = ['paye' => ['payé', 'vert'], 'echec' => ['échec', 'rouge'], 'sur_prochaine_facture' => ['sur la prochaine facture', 'bleu'], 'a_facturer' => ['à facturer', 'orange']];
            foreach ($liste as $p) {
                [$lib, $t] = $libs[$p['statut']] ?? [$p['statut'], 'gris'];
                $h .= '<li><span class="gris">' . h(date('d/m/Y', strtotime($p['cree_le']))) . '</span> ' . h($p['libelle']) . ' · <b>' . h(montant((float)$p['montant'])) . '</b> ' . badge($lib, $t) . '</li>';
            }
            $h .= '</ul>';
        }
        return $h . '</section>';
    }
    if ($r['mensuel'] <= 0) {
        return $h . '<p>Le prix de l\'offre sur mesure reste à fixer : le paiement en ligne sera possible ensuite.</p></section>';
    }
    if ($statut === 'en_attente') {
        $h .= '<div class="msg info">Paiement commencé mais pas encore confirmé. Un prélèvement SEPA peut prendre quelques jours ; sinon, reprenez ci-dessous.</div>';
    } elseif ($statut === 'annule') {
        $h .= '<div class="msg info">Abonnement résilié. Il peut être réactivé à tout moment ci-dessous.</div>';
    }
    $premiere = '<b>' . h(montant($r['mensuel'] + $r['mise_en_place'])) . ' HT</b><small>'
        . h($r['mise_en_place'] > 0 ? 'Mise en place ' . montant($r['mise_en_place']) . ' + premier mois ' . montant($r['mensuel']) : 'Premier mois (mise en place ' . ($r['raison_sans_mise_en_place'] ?: 'offerte') . ')') . '</small>';
    $h .= '<div class="recap-paiement' . ($r['essai'] ? ' trois' : '') . '">'
        . ($r['essai']
            ? '<div class="essai"><span>' . ESSAI_JOURS . ' jours d\'essai gratuit</span><b>0 € aujourd\'hui</b><small>Aucun prélèvement avant le ' . h(date('d/m/Y', fin_essai_previsionnelle())) . '</small></div><div><span>Au ' . (ESSAI_JOURS + 1) . 'e jour</span>' . $premiere . '</div>'
            : '<div><span>Première échéance</span>' . $premiere . '</div>')
        . '<div><span>Ensuite</span><b>' . h(montant($r['mensuel'])) . ' HT par mois</b><small>Prélevé automatiquement, facture envoyée par e-mail</small></div></div>';
    $h .= '<div class="deux-boutons">' . (stripe_actif() ? form_paiement('payer_stripe', $r['essai'] ? 'Démarrer l\'essai gratuit (carte ou SEPA)' : 'Payer par carte ou prélèvement SEPA', 'btn') : '')
        . (paypal_actif() ? form_paiement('payer_paypal', $r['essai'] ? 'Démarrer l\'essai avec PayPal' : 'Payer avec PayPal', 'btn contour') : '') . '</div>'
        . ($r['essai'] ? '<p class="petit">Le moyen de paiement est enregistré dès maintenant, mais rien n\'est prélevé pendant ' . ESSAI_JOURS . ' jours. Résiliation possible à tout moment pendant l\'essai, sans frais.</p>' : '')
        . '<p class="petit">Paiement sécurisé chez ' . (stripe_actif() && paypal_actif() ? 'Stripe ou PayPal' : (stripe_actif() ? 'Stripe' : 'PayPal')) . ' : SALW CONSULTING ne voit jamais les coordonnées bancaires.'
        . (stripe_actif() ? ' Avec Stripe, les SMS au-delà du forfait s\'ajoutent automatiquement à la facture suivante.' : '')
        . (paypal_actif() ? ' Avec PayPal, ils sont facturés à part.' : '') . '</p>';
    return $h . '</section>';
}

function form_paiement(string $action, string $libelle, string $classe): string
{
    return '<form method="post">' . champ_csrf() . '<input type="hidden" name="action" value="' . h($action) . '"><button class="' . h($classe) . '">' . h($libelle) . '</button></form>';
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
