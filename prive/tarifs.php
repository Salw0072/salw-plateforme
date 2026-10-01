<?php
/**
 * Plateforme SALW : formules d'abonnement au volume, prix par métier, consommation de chaque client.
 *
 * Toutes les automatisations sont incluses dans toutes les formules : seul le volume change
 * (nombre de professionnels, SMS inclus par mois). Le prix de base est ajusté par un coefficient
 * selon la valeur d'un rendez-vous dans le métier.
 *
 * Mêmes paramètres que la page Tarifs du site Conseiller Sam (TARIFS dans index.html) : à modifier ensemble.
 * Prix en euros hors taxes.
 */

declare(strict_types=1);

/** Formules, du plus petit au plus grand volume. */
const FORMULES = [
    'essentiel' => ['libelle' => 'Essentiel', 'pros_max' => 2, 'sms' => 500, 'prix' => 149, 'mise_en_place' => 490],
    'equipe'    => ['libelle' => 'Équipe', 'pros_max' => 6, 'sms' => 1500, 'prix' => 289, 'mise_en_place' => 790],
    'structure' => ['libelle' => 'Structure', 'pros_max' => 15, 'sms' => 4000, 'prix' => 489, 'mise_en_place' => 1490],
];

/** Coefficient de prix par métier, selon la valeur d'un rendez-vous (la mise en place n'est pas ajustée : c'est le même travail). */
const COEF_METIERS = ['sante' => 1.0, 'coach' => 1.0, 'artisan' => 1.1, 'garage' => 1.2, 'ecole' => 1.3, 'immobilier' => 1.4, 'juridique' => 1.4];

const SMS_SUPPLEMENT = 0.15;          // € HT par SMS au-delà du forfait (coût Twilio : environ 0,07 € en France, 0,10 € en Belgique)
const MAJORATION_SANS_ENGAGEMENT = 0.20;

function coef_metier(string $metier): float
{
    return COEF_METIERS[$metier] ?? 1.0;
}

/** Prix mensuel de grille d'une formule pour un métier, arrondi à la dizaine supérieure moins 1 € (149, 169, 209…). */
function prix_formule(string $formule, string $metier): int
{
    $p = FORMULES[$formule]['prix'] * coef_metier($metier);
    return (int)(ceil(round($p, 2) / 10) * 10 - 1);
}

/** Formule adaptée à un nombre de professionnels ('sur_mesure' au-delà de la plus grande). */
function formule_pour(int $pros): string
{
    foreach (FORMULES as $k => $f) {
        if ($pros <= $f['pros_max']) {
            return $k;
        }
    }
    return 'sur_mesure';
}

/** Formule du client : celle qui lui est attribuée, sinon celle qui correspond à sa taille. */
function formule_client(array $c): string
{
    $f = (string)($c['formule'] ?? '');
    return isset(FORMULES[$f]) || $f === 'sur_mesure' ? $f : formule_pour(count(praticiens($c)));
}

function libelle_formule(string $f): string
{
    return FORMULES[$f]['libelle'] ?? 'Sur mesure';
}

/** Prix mensuel payé par le client : prix négocié s'il y en a un, sinon la grille (majorée sans engagement). */
function prix_mensuel(array $c): float
{
    if ((float)($c['prix_negocie'] ?? 0) > 0) {
        return (float)$c['prix_negocie'];
    }
    $f = formule_client($c);
    if (!isset(FORMULES[$f])) {
        return 0.0;
    }
    $p = (float)prix_formule($f, (string)$c['metier']);
    return (int)($c['engagement'] ?? 1) ? $p : round($p * (1 + MAJORATION_SANS_ENGAGEMENT));
}

/**
 * Consommation d'un mois (AAAA-MM, heure du client) : SMS facturés (en segments, comme l'opérateur),
 * professionnels actifs, dépassements et projection à la fin du mois si le mois est en cours.
 */
function consommation(array $c, ?string $mois = null): array
{
    $tz = tz($c);
    $now = maintenant($c);
    $mois = $mois ?? (new DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y-m');
    $debut = new DateTimeImmutable($mois . '-01 00:00:00', $tz);
    $fin = $debut->modify('first day of next month');
    $sms = 0;
    foreach (toutes("SELECT contenu FROM messages WHERE clinique_id = ? AND canal = 'sms' AND statut != 'echec' AND envoye_le >= ? AND envoye_le < ?",
        [$c['id'], iso($debut->getTimestamp()), iso($fin->getTimestamp())]) as $m) {
        $sms += segments_sms($m['contenu']);
    }
    $f = formule_client($c);
    $quota = FORMULES[$f]['sms'] ?? 0;
    $prosMax = FORMULES[$f]['pros_max'] ?? 0;
    $pros = count(praticiens($c));
    // Projection linéaire sur le mois, après 2 jours écoulés (avant, elle n'a pas de sens).
    $ecoule = max(0, min($fin->getTimestamp(), $now) - $debut->getTimestamp());
    $total = $fin->getTimestamp() - $debut->getTimestamp();
    $enCours = $now < $fin->getTimestamp();
    $projection = $enCours && $ecoule >= 2 * 86400 ? (int)round($sms * $total / $ecoule) : $sms;
    $depasse = max(0, $sms - $quota);
    return [
        'mois' => $mois, 'en_cours' => $enCours, 'formule' => $f,
        'sms' => $sms, 'quota' => $quota, 'projection' => $projection,
        'depassement' => $quota ? $depasse : 0, 'cout_depassement' => $quota ? round($depasse * SMS_SUPPLEMENT, 2) : 0.0,
        'cout_projete' => $quota ? round(max(0, $projection - $quota) * SMS_SUPPLEMENT, 2) : 0.0,
        'pros' => $pros, 'pros_max' => $prosMax,
    ];
}

/** Formule la moins chère pour ce volume, dépassements compris ; null si la formule actuelle l'est déjà. */
function formule_conseillee(array $c, array $k): ?array
{
    if (!isset(FORMULES[$k['formule']]) || (float)($c['prix_negocie'] ?? 0) > 0) {
        return null;
    }
    $cout = function (string $f) use ($c, $k): float {
        $d = FORMULES[$f];
        return prix_formule($f, (string)$c['metier']) + max(0, $k['projection'] - $d['sms']) * SMS_SUPPLEMENT;
    };
    $actuel = $cout($k['formule']);
    $meilleur = null;
    foreach (FORMULES as $f => $d) {
        if ($f === $k['formule'] || $k['pros'] > $d['pros_max']) {
            continue;
        }
        if ($cout($f) < $actuel - 1 && (!$meilleur || $cout($f) < $meilleur['cout'])) {
            $meilleur = ['formule' => $f, 'cout' => $cout($f), 'economie' => $actuel - $cout($f)];
        }
    }
    // Trop de professionnels pour la formule : il faut monter, même si c'est plus cher.
    if (!$meilleur && $k['pros'] > $k['pros_max']) {
        $f = formule_pour($k['pros']);
        return ['formule' => $f, 'cout' => isset(FORMULES[$f]) ? $cout($f) : 0.0, 'economie' => 0.0, 'raison' => 'pros'];
    }
    return $meilleur;
}

/** Alerte de consommation pour le tableau de bord : null, ou [ton, texte]. */
function alerte_consommation(array $c): ?array
{
    $k = consommation($c);
    if (!$k['quota']) {
        return null;
    }
    if ($k['sms'] > $k['quota']) {
        return ['erreur', 'Forfait SMS dépassé : ' . $k['sms'] . ' SMS sur ' . $k['quota'] . ' inclus ce mois-ci. Les envois continuent, facturés ' . number_format(SMS_SUPPLEMENT, 2, ',', ' ') . ' € l\'unité (' . number_format($k['cout_depassement'], 2, ',', ' ') . ' € à ce jour).'];
    }
    if ($k['en_cours'] && $k['projection'] > $k['quota']) {
        return ['info', 'À ce rythme, le forfait de ' . $k['quota'] . ' SMS sera dépassé d\'ici la fin du mois (environ ' . $k['projection'] . ' SMS).'];
    }
    if ($k['pros'] > $k['pros_max']) {
        return ['info', $k['pros'] . ' ' . mot($c, 'pros') . ' actifs pour une formule prévue jusqu\'à ' . $k['pros_max'] . ' : prévoir le passage à la formule ' . libelle_formule(formule_pour($k['pros'])) . '.'];
    }
    return null;
}

/** Montant en euros : sans décimales s'il est rond, avec les centimes sinon (204,50 €). */
function montant(float $n): string
{
    return abs($n - round($n)) < 0.005 ? euros($n) : number_format($n, 2, ',', ' ') . ' €';
}
