<?php
/**
 * Plateforme SALW Santé : assistant des patients, 24 h sur 24.
 *
 * Répond aux questions pratiques (horaires, adresse, accès, documents, praticiens) à partir
 * des informations de la clinique. Ne donne JAMAIS d'avis médical : toute question de santé
 * est renvoyée vers un rendez-vous, et toute urgence vers le 15, le 112 ou le 3114.
 *
 * Avec une clé Anthropic (et PHP 8.1+) : réponses rédigées par Claude, limitées aux informations
 * fournies. Sans clé ou en cas d'incident : réponses tirées de la FAQ par mots-clés.
 */

declare(strict_types=1);

function urgence(array $c, string $texte): ?string
{
    $t = mb_strtolower($texte);
    // Urgences vitales et détresse pour tous les métiers ; signes médicaux seulement en santé.
    $mots = metier($c)['urgence']
        ? ['urgence', 'urgent', 'douleur thoracique', 'douleur dans la poitrine', 'mal à la poitrine', 'respire', 'respirer', 'étouffe', 'inconscient', 'évanoui',
            'saigne', 'hémorragie', 'avc', 'paralys', 'convuls', 'overdose', 'intoxication', 'suicid', 'me tuer', 'en finir', 'accident']
        : ['suicid', 'me tuer', 'en finir', 'inconscient', 'ne respire plus', 'incendie', 'odeur de gaz', 'fuite de gaz', 'sent le gaz', 'sens le gaz', 'odeur gaz'];
    foreach ($mots as $m) {
        if (mb_strpos($t, $m) !== false) {
            $be = $c['pays'] === 'BE';
            if (in_array($m, ['incendie', 'odeur de gaz', 'fuite de gaz', 'sent le gaz', 'sens le gaz', 'odeur gaz'], true)) {
                return 'Quittez les lieux et appelez immédiatement le 112' . ($be ? '' : ' ou le 18 (pompiers)') . ". Pour le gaz, n'actionnez aucun interrupteur. Nous vous recontacterons ensuite.";
            }
            return 'Si vous pensez que c\'est une urgence, appelez immédiatement le ' . ($be ? '112' : '15 (SAMU) ou le 112')
                . '. En cas de pensées suicidaires, appelez le ' . ($be ? '1813 (Centre de prévention du suicide)' : '3114, numéro national de prévention du suicide, 24 h sur 24')
                . '. Je suis un assistant pour les questions pratiques et ne peux pas évaluer votre situation.';
        }
    }
    return null;
}

/** Informations de la clinique, telles que l'assistant a le droit de les utiliser. */
function fiche_clinique(array $c): string
{
    $jours = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $lignes = ["Nom : {$c['nom']}", 'Métier : ' . metier($c)['libelle'], "Adresse : " . trim($c['adresse'] . ', ' . $c['ville'], ', '), 'Téléphone : ' . telephone_lisible((string)$c['telephone'])];
    foreach (praticiens($c) as $p) {
        $h = json_decode((string)$p['horaires'], true) ?: [];
        $plages = [];
        foreach ($h as $j => $ps) {
            $plages[] = $jours[(int)$j] . ' ' . implode(' et ', array_map(function ($x) { return $x[0] . '-' . $x[1]; }, $ps));
        }
        $lignes[] = mot($c, 'Pro') . ' : ' . nom_pro($p) . ($plages ? ' (rendez-vous : ' . implode(', ', $plages) . ')' : '');
    }
    foreach (types_rdv($c, true) as $t) {
        $lignes[] = "Rendez-vous réservable en ligne : {$t['libelle']} ({$t['duree']} min)";
    }
    return implode("\n", $lignes) . "\n\nQuestions fréquentes :\n" . trim((string)$c['faq']);
}

function repondre_patient(array $c, array $historique): array
{
    $dernier = (string)end($historique)['content'];
    $u = urgence($c, $dernier);
    if ($u !== null) {
        return ['texte' => $u, 'source' => 'urgence'];
    }
    if ((string)cfg('ia_cle') !== '' && PHP_VERSION_ID >= 80100 && is_file(__DIR__ . '/vendor/autoload.php') && ia_quota_ok()) {
        require_once __DIR__ . '/assistant-ia.php';
        $texte = assistant_claude($c, $historique);
        if ($texte !== null) {
            return ['texte' => $texte, 'source' => 'ia'];
        }
    }
    return ['texte' => reponse_faq($c, $dernier), 'source' => 'faq'];
}

function ia_quota_ok(): bool
{
    $jour = gmdate('Y-m-d');
    $ligne = une("SELECT * FROM compteurs WHERE cle = 'ia'");
    $n = $ligne && $ligne['maj'] === $jour ? (int)$ligne['valeur'] : 0;
    if ($n >= (int)cfg('ia_max_jour')) {
        return false;
    }
    db()->prepare("INSERT INTO compteurs (cle, valeur, maj) VALUES ('ia', ?, ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur, maj = excluded.maj")->execute([$n + 1, $jour]);
    return true;
}

/**
 * Question hors du rôle de l'assistant pour ce métier (santé, droit, estimation, prix ferme…) :
 * renvoie la réponse de refus du métier, ou null.
 */
function refus_metier(array $c, string $texte): ?string
{
    $r = metier($c)['refus'];
    return preg_match($r['regex'], $texte) ? $r['texte'] . ' Téléphone : ' . telephone_lisible((string)$c['telephone']) . '.' : null;
}

/** Sans IA : la question de la FAQ qui partage le plus de mots avec la demande. */
function reponse_faq(array $c, string $question): string
{
    $refus = refus_metier($c, $question);
    if ($refus !== null) {
        return $refus;
    }
    // Quelques synonymes courants, pour rapprocher la question des titres de la FAQ.
    $synonymes = ['garer' => 'parking', 'stationner' => 'parking', 'stationnement' => 'parking', 'voiture' => 'parking', 'tram' => 'transports', 'bus' => 'transports',
        'metro' => 'transports', 'métro' => 'transports', 'ouvert' => 'horaires', 'ouverture' => 'horaires', 'heure' => 'horaires', 'prix' => 'tarifs', 'tarif' => 'tarifs',
        'coût' => 'tarifs', 'combien' => 'tarifs', 'rembourse' => 'tiers payant', 'mutuelle' => 'mutuelle', 'annuler' => 'annuler', 'déplacer' => 'déplacer', 'visio' => 'téléconsultation'];
    foreach ($synonymes as $de => $vers) {
        if (mb_stripos($question, $de) !== false) {
            $question .= ' ' . $vers;
        }
    }
    $mots = function (string $s): array {
        $s = mb_strtolower(strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'ç' => 'c', 'ô' => 'o', 'î' => 'i', 'û' => 'u', 'ù' => 'u']));
        $vides = ['le', 'la', 'les', 'de', 'des', 'du', 'un', 'une', 'et', 'est', 'a', 'au', 'aux', 'en', 'je', 'vous', 'il', 'on', 'pour', 'que', 'quel', 'quelle', 'quels', 'quelles', 'ce', 'ces', 'mon', 'ma', 'mes', 'votre', 'vos', 'se', 'sur', 'pas', 'ne', 'y', 'dois', 'faut', 'peut', 'peux', 'comment', 'est-ce', 'qui', 'quoi'];
        return array_values(array_diff(array_filter(preg_split('/[^a-z0-9]+/', $s), function ($m) { return strlen($m) > 2; }), $vides));
    };
    $q = $mots($question);
    $meilleur = null;
    $score = 0;
    foreach (preg_split("/\n\s*\n/", trim((string)$c['faq'])) as $bloc) {
        $lignes = explode("\n", trim($bloc), 2);
        if (count($lignes) < 2) {
            continue;
        }
        $s = count(array_intersect($q, $mots($lignes[0] . ' ' . $lignes[1])));
        if ($s > $score) {
            $score = $s;
            $meilleur = trim($lignes[1]);
        }
    }
    $t = mb_strtolower($question);
    if ($meilleur === null && preg_match('/adresse|où|ou se trouve|venir|acc[eè]s|parking/u', $t)) {
        $meilleur = 'Nous sommes au ' . trim($c['adresse'] . ', ' . $c['ville'], ', ') . '.';
    }
    if ($meilleur === null && preg_match('/rendez|rdv|dispon|créneau|creneau|consult/u', $t)) {
        $meilleur = 'Vous pouvez prendre rendez-vous directement sur cette page : choisissez le type de rendez-vous puis un créneau.';
    }
    return $meilleur ?? 'Je n\'ai pas cette information. Vous pouvez nous appeler au ' . telephone_lisible((string)$c['telephone']) . ', ou prendre rendez-vous en ligne.';
}
