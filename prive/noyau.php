<?php
/**
 * Plateforme SALW Santé : base de données, réglages, outils communs.
 *
 * Stockage : SQLite (prive/donnees/plateforme.sqlite), aucune base à créer ni à administrer.
 * Plusieurs cliniques dans la même installation : chaque ligne porte clinique_id.
 * Compatible PHP 7.4 à 8.x.
 */

declare(strict_types=1);

require_once __DIR__ . '/metiers.php';

function cfg(string $cle)
{
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
    }
    return $c[$cle] ?? null;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// --- Base de données ------------------------------------------------------------------

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dossier = __DIR__ . '/donnees';
    if (!is_dir($dossier)) {
        @mkdir($dossier, 0750, true);
    }
    $pdo = new PDO('sqlite:' . $dossier . '/plateforme.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
    schema($pdo);
    return $pdo;
}

function schema(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= 10) {
        return;
    }
    if ($version >= 1) {
        if ($version < 2) {
            migration_2($pdo);
        }
        if ($version < 3) {
            migration_3($pdo);
        }
        if ($version < 4) {
            migration_4($pdo);
        }
        if ($version < 5) {
            migration_5($pdo);
        }
        if ($version < 6) {
            migration_6($pdo);
        }
        if ($version < 7) {
            migration_7($pdo);
        }
        if ($version < 8) {
            migration_8($pdo);
        }
        if ($version < 9) {
            migration_9($pdo);
        }
        migration_10($pdo);
        return;
    }
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cliniques (
  id INTEGER PRIMARY KEY, slug TEXT UNIQUE NOT NULL, nom TEXT NOT NULL, adresse TEXT DEFAULT '', ville TEXT DEFAULT '',
  pays TEXT DEFAULT 'FR', fuseau TEXT DEFAULT 'Europe/Paris', telephone TEXT DEFAULT '', email TEXT DEFAULT '',
  avis_url TEXT DEFAULT '', valeur_consultation REAL DEFAULT 30, reglages TEXT DEFAULT '{}', faq TEXT DEFAULT '',
  demo INTEGER DEFAULT 0, horloge_decalage INTEGER DEFAULT 0, actif INTEGER DEFAULT 1, cree_le TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS praticiens (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  nom TEXT NOT NULL, titre TEXT DEFAULT '', horaires TEXT DEFAULT '{}', actif INTEGER DEFAULT 1
);
CREATE TABLE IF NOT EXISTS types_rdv (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  libelle TEXT NOT NULL, duree INTEGER NOT NULL DEFAULT 20, en_ligne INTEGER DEFAULT 1, actif INTEGER DEFAULT 1
);
CREATE TABLE IF NOT EXISTS patients (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  prenom TEXT NOT NULL, nom TEXT NOT NULL, telephone TEXT DEFAULT '', email TEXT DEFAULT '',
  consentement_relance INTEGER DEFAULT 0, stop_sms INTEGER DEFAULT 0, derniere_relance TEXT DEFAULT '', dernier_avis TEXT DEFAULT '',
  cree_le TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS i_patients_tel ON patients(clinique_id, telephone);
CREATE TABLE IF NOT EXISTS rdv (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  praticien_id INTEGER NOT NULL REFERENCES praticiens(id), patient_id INTEGER NOT NULL REFERENCES patients(id) ON DELETE CASCADE,
  type_id INTEGER REFERENCES types_rdv(id), debut TEXT NOT NULL, fin TEXT NOT NULL,
  statut TEXT NOT NULL DEFAULT 'confirme', source TEXT DEFAULT 'en_ligne', jeton TEXT UNIQUE NOT NULL,
  confirme_patient_le TEXT DEFAULT '', rappels TEXT DEFAULT '', avis_envoye_le TEXT DEFAULT '', absence_envoyee_le TEXT DEFAULT '',
  honore_auto INTEGER DEFAULT 0, cree_le TEXT NOT NULL, annule_le TEXT DEFAULT '', annule_par TEXT DEFAULT ''
);
CREATE INDEX IF NOT EXISTS i_rdv_debut ON rdv(clinique_id, debut);
CREATE TABLE IF NOT EXISTS attente (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  patient_id INTEGER NOT NULL REFERENCES patients(id) ON DELETE CASCADE, praticien_id INTEGER, type_id INTEGER,
  preference TEXT DEFAULT '', statut TEXT DEFAULT 'active', cree_le TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS offres (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL, attente_id INTEGER NOT NULL REFERENCES attente(id) ON DELETE CASCADE,
  praticien_id INTEGER NOT NULL, type_id INTEGER, debut TEXT NOT NULL, fin TEXT NOT NULL, rdv_libere_id INTEGER,
  jeton TEXT UNIQUE NOT NULL, statut TEXT DEFAULT 'envoyee', expire_le TEXT NOT NULL, cree_le TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS appels (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  numero TEXT NOT NULL, recu_le TEXT NOT NULL, statut TEXT DEFAULT 'manque', sms_envoye_le TEXT DEFAULT '', rdv_id INTEGER, source TEXT DEFAULT 'simulation'
);
CREATE TABLE IF NOT EXISTS messages (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  patient_id INTEGER, destinataire TEXT NOT NULL, canal TEXT NOT NULL, type TEXT NOT NULL, contenu TEXT NOT NULL,
  statut TEXT NOT NULL, envoye_le TEXT NOT NULL, rdv_id INTEGER, erreur TEXT DEFAULT ''
);
CREATE INDEX IF NOT EXISTS i_messages ON messages(clinique_id, envoye_le);
CREATE TABLE IF NOT EXISTS evenements (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL, t TEXT NOT NULL, type TEXT NOT NULL, detail TEXT DEFAULT '', valeur REAL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS i_evenements ON evenements(clinique_id, t);
CREATE TABLE IF NOT EXISTS fermetures (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE, jour TEXT NOT NULL, praticien_id INTEGER
);
CREATE TABLE IF NOT EXISTS utilisateurs (
  id INTEGER PRIMARY KEY, clinique_id INTEGER REFERENCES cliniques(id) ON DELETE CASCADE, identifiant TEXT UNIQUE NOT NULL,
  nom TEXT DEFAULT '', role TEXT NOT NULL, hash TEXT NOT NULL, actif INTEGER DEFAULT 1, changer_mdp INTEGER DEFAULT 0,
  totp TEXT, totp_dernier INTEGER DEFAULT 0, echecs INTEGER DEFAULT 0, bloque_jusqua INTEGER DEFAULT 0,
  derniere_connexion TEXT DEFAULT '', cree_le TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS journal (
  id INTEGER PRIMARY KEY, t TEXT NOT NULL, utilisateur TEXT DEFAULT '', clinique_id INTEGER, action TEXT NOT NULL, detail TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS compteurs (cle TEXT PRIMARY KEY, valeur INTEGER DEFAULT 0, maj TEXT DEFAULT '');
PRAGMA user_version = 1;
SQL
    );
    migration_2($pdo);
    migration_3($pdo);
    migration_4($pdo);
    migration_5($pdo);
    migration_6($pdo);
    migration_7($pdo);
    migration_8($pdo);
    migration_9($pdo);
    migration_10($pdo);
}

/** Version 2 : plateforme multi-métier (métier du client, questions de réservation, devis). */
function migration_2(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
ALTER TABLE cliniques ADD COLUMN metier TEXT DEFAULT 'sante';
ALTER TABLE rdv ADD COLUMN infos TEXT DEFAULT '';
CREATE TABLE IF NOT EXISTS devis (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  patient_id INTEGER NOT NULL REFERENCES patients(id) ON DELETE CASCADE, libelle TEXT NOT NULL, montant REAL DEFAULT 0,
  envoye_le TEXT NOT NULL, statut TEXT DEFAULT 'envoye', relances TEXT DEFAULT '', jeton TEXT UNIQUE NOT NULL,
  repondu_le TEXT DEFAULT '', cree_le TEXT NOT NULL
);
PRAGMA user_version = 2;
SQL
    );
}

/** Version 3 : image du client (logo, couleur, adresse web) et rapport mensuel. */
function migration_3(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
ALTER TABLE cliniques ADD COLUMN logo TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN couleur TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN domaine TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN rapport_email TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN rapport_actif INTEGER DEFAULT 1;
CREATE TABLE IF NOT EXISTS rapports (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  mois TEXT NOT NULL, destinataire TEXT NOT NULL, statut TEXT NOT NULL, envoye_le TEXT NOT NULL, erreur TEXT DEFAULT '',
  UNIQUE (clinique_id, mois)
);
PRAGMA user_version = 3;
SQL
    );
}

/** Version 4 : présentation de la page publique (accroche, texte, atouts). */
function migration_4(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
ALTER TABLE cliniques ADD COLUMN accroche TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN presentation TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN atouts TEXT DEFAULT '';
PRAGMA user_version = 4;
SQL
    );
}

/** Version 5 : photo de couverture de la page publique et son cadrage. */
function migration_5(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
ALTER TABLE cliniques ADD COLUMN couverture TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN couverture_cadrage TEXT DEFAULT 'centre';
PRAGMA user_version = 5;
SQL
    );
}

/** Version 6 : abonnement du client (formule au volume, engagement, prix négocié). */
function migration_6(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
ALTER TABLE cliniques ADD COLUMN formule TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN engagement INTEGER DEFAULT 1;
ALTER TABLE cliniques ADD COLUMN prix_negocie REAL DEFAULT 0;
PRAGMA user_version = 6;
SQL
    );
}

/** Version 7 : réinitialisation du mot de passe par lien (empreinte du jeton et expiration). */
function migration_7(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
ALTER TABLE utilisateurs ADD COLUMN reinit_empreinte TEXT DEFAULT '';
ALTER TABLE utilisateurs ADD COLUMN reinit_expire INTEGER DEFAULT 0;
PRAGMA user_version = 7;
SQL
    );
}

/** Version 8 : paiement des abonnements (Stripe, PayPal), historique des paiements. */
function migration_8(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
ALTER TABLE cliniques ADD COLUMN mise_en_place_offerte INTEGER DEFAULT 0;
ALTER TABLE cliniques ADD COLUMN paiement_fournisseur TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN paiement_statut TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN paiement_client_ref TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN paiement_abonnement_ref TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN paiement_plan_ref TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN paiement_maj TEXT DEFAULT '';
CREATE TABLE IF NOT EXISTS paiements (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  fournisseur TEXT NOT NULL, type TEXT NOT NULL, libelle TEXT DEFAULT '', montant REAL DEFAULT 0,
  statut TEXT NOT NULL, ref TEXT NOT NULL, cree_le TEXT NOT NULL, UNIQUE (fournisseur, ref)
);
CREATE TABLE IF NOT EXISTS paiements_evenements (id TEXT PRIMARY KEY, recu_le TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS parametres (cle TEXT PRIMARY KEY, valeur TEXT DEFAULT '');
PRAGMA user_version = 8;
SQL
    );
}

/** Version 9 : essai gratuit de 7 jours (fin de l'essai, essai déjà utilisé, mise en place différée). */
function migration_9(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
ALTER TABLE cliniques ADD COLUMN paiement_essai_fin TEXT DEFAULT '';
ALTER TABLE cliniques ADD COLUMN paiement_essai_utilise INTEGER DEFAULT 0;
ALTER TABLE cliniques ADD COLUMN paiement_mise_en_place_ref TEXT DEFAULT '';
PRAGMA user_version = 9;
SQL
    );
}

/** Version 10 : réseaux sociaux et WhatsApp (connexions, accord WhatsApp des clients, publications, prospects des publicités). */
function migration_10(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS reseaux (
  clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE, reseau TEXT NOT NULL,
  identifiant TEXT DEFAULT '', jeton TEXT DEFAULT '', nom TEXT DEFAULT '', expire_le TEXT DEFAULT '', maj TEXT DEFAULT '',
  PRIMARY KEY (clinique_id, reseau)
);
ALTER TABLE cliniques ADD COLUMN reseaux_options TEXT DEFAULT '{}';
ALTER TABLE patients ADD COLUMN whatsapp INTEGER DEFAULT 0;
ALTER TABLE messages ADD COLUMN ref TEXT DEFAULT '';
CREATE TABLE IF NOT EXISTS publications (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  reseau TEXT NOT NULL, origine TEXT NOT NULL, cle TEXT NOT NULL, texte TEXT NOT NULL, lien TEXT DEFAULT '', image TEXT DEFAULT '',
  statut TEXT NOT NULL, ref TEXT DEFAULT '', erreur TEXT DEFAULT '', cree_le TEXT NOT NULL, UNIQUE (clinique_id, reseau, cle)
);
CREATE TABLE IF NOT EXISTS prospects (
  id INTEGER PRIMARY KEY, clinique_id INTEGER NOT NULL REFERENCES cliniques(id) ON DELETE CASCADE,
  source TEXT NOT NULL, ref TEXT NOT NULL UNIQUE, formulaire TEXT DEFAULT '', prenom TEXT DEFAULT '', nom TEXT DEFAULT '',
  telephone TEXT DEFAULT '', email TEXT DEFAULT '', whatsapp INTEGER DEFAULT 0, donnees TEXT DEFAULT '{}',
  statut TEXT DEFAULT 'nouveau', canal TEXT DEFAULT '', recu_le TEXT NOT NULL, contacte_le TEXT DEFAULT '', rdv_id INTEGER
);
PRAGMA user_version = 10;
SQL
    );
}

function une(string $sql, array $p = []): ?array
{
    $s = db()->prepare($sql);
    $s->execute($p);
    $r = $s->fetch();
    return $r === false ? null : $r;
}

function toutes(string $sql, array $p = []): array
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->fetchAll();
}

function valeur(string $sql, array $p = [])
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
}

/** UPDATE / DELETE : renvoie le nombre de lignes touchées. */
function executer(string $sql, array $p = []): int
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->rowCount();
}

/** INSERT : renvoie l'identifiant créé. */
function inserer(string $sql, array $p = []): int
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return (int)db()->lastInsertId();
}

function jeton(): string
{
    return bin2hex(random_bytes(12));
}

// --- Temps : chaque clinique a son fuseau, et une clinique de démonstration son horloge ----

function tz(array $c): DateTimeZone
{
    try {
        return new DateTimeZone($c['fuseau'] ?: 'Europe/Paris');
    } catch (Exception $e) {
        return new DateTimeZone('Europe/Paris');
    }
}

/** Heure courante de la clinique (horloge avancée pour une clinique de démonstration). */
function maintenant(array $c): int
{
    return time() + ((int)$c['demo'] === 1 ? (int)$c['horloge_decalage'] : 0);
}

function iso(int $t): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $t);
}

function ts(string $iso): int
{
    return (int)strtotime($iso);
}

function local(array $c, string $iso, string $format): string
{
    return (new DateTimeImmutable($iso))->setTimezone(tz($c))->format($format);
}

/** « mardi 14 octobre » */
function date_longue(array $c, string $iso): string
{
    $jours = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $d = (new DateTimeImmutable($iso))->setTimezone(tz($c));
    return $jours[(int)$d->format('N')] . ' ' . (int)$d->format('j') . ' ' . $mois[(int)$d->format('n')];
}

function heure(array $c, string $iso): string
{
    return str_replace(':', 'h', local($c, $iso, 'H:i'));
}

// --- Téléphone (France, Belgique) --------------------------------------------------------

/** Numéro au format international, ou '' s'il est invalide. 06… (FR) et 04… (BE) sont convertis. */
function telephone(string $brut, string $pays): string
{
    $t = preg_replace('/[\s.\-()\/]/', '', $brut);
    if (strpos($t, '00') === 0) {
        $t = '+' . substr($t, 2);
    }
    if (preg_match('/^0\d{8,9}$/', $t)) {
        $t = ($pays === 'BE' ? '+32' : '+33') . substr($t, 1);
    }
    return preg_match('/^\+[1-9]\d{7,14}$/', $t) ? $t : '';
}

function telephone_lisible(string $t): string
{
    if (preg_match('/^\+33(\d)(\d{2})(\d{2})(\d{2})(\d{2})$/', $t, $m)) {
        return "0{$m[1]} {$m[2]} {$m[3]} {$m[4]} {$m[5]}";
    }
    if (preg_match('/^\+32(\d{3})(\d{2})(\d{2})(\d{2})$/', $t, $m)) {
        return "0{$m[1]} {$m[2]} {$m[3]} {$m[4]}";
    }
    return $t;
}

// --- Réglages de clinique : automatisations et textes --------------------------------------

function reglages_defaut(?array $c = null): array
{
    $d = [
        'appel_manque'  => ['actif' => true, 'delai_min' => 2],
        'confirmation'  => ['actif' => true],
        'rappel_j2'     => ['actif' => true, 'heures' => 48],
        'rappel_j1'     => ['actif' => true, 'heures' => 24, 'si_non_confirme' => true],
        'rappel_h3'     => ['actif' => false, 'heures' => 3],
        'liste_attente' => ['actif' => true, 'simultanes' => 3, 'validite_min' => 60, 'marge_min' => 90],
        'avis'          => ['actif' => true, 'heures_apres' => 2, 'intervalle_mois' => 12, 'honore_par_defaut' => true],
        'absence'       => ['actif' => true],
        'reactivation'  => ['actif' => true, 'mois' => 12, 'intervalle_mois' => 6],
        'mentionner_praticien' => false,
        'relance_devis' => ['actif' => false, 'premier_j' => 3, 'second_j' => 7],
        'reservation'   => ['delai_min_h' => 2, 'horizon_jours' => 30],
        'textes' => textes_defaut($c),
    ];
    // Réglages propres au métier (modules actifs, délais).
    if ($c) {
        foreach (metier($c)['modules'] as $k => $v) {
            $d[$k] = array_merge($d[$k], $v);
        }
    }
    return $d;
}

function textes_defaut(?array $c = null): array
{
    $t = [
        // Courts à dessein : un SMS = 160 caractères, dont environ 73 pour le lien. Au-delà, chaque SMS coûte double.
        'confirmation'  => "RDV confirmé le {date} à {heure}, {structure}. Gérer : {lien}",
        'rappel_j2'     => "Rappel RDV {date} {heure}, {structure}. Confirmer/annuler : {lien}",
        'rappel_j1'     => "RDV demain {heure}, {structure}. Confirmer/annuler : {lien}",
        'rappel_h3'     => "{structure} : à tout à l'heure, {heure}. {adresse}",
        'offre_attente' => "Créneau libre {date} {heure}, {structure}. 1er à confirmer : {lien}",
        'appel_manque'  => "{structure} : désolés, appel manqué. Réservez en ligne : {lien}",
        'avis'          => "Merci {prenom} ! Votre avis sur {structure} : {lien}",
        'absence'       => "{prenom}, on ne vous a pas vu. Reprenez RDV quand vous voulez : {lien}",
        'reactivation'  => "{structure} vous propose un point : {lien} Répondez STOP pour arrêter",
        'relance_devis' => "Votre devis : {devis}. Une question ? Acceptez en 1 clic : {lien}",
        'prospect'      => "Merci {prenom} ! Réservez votre RDV chez {structure} : {lien}",
    ];
    return $c ? array_merge($t, metier($c)['textes']) : $t;
}

/** Réglages effectifs d'une clinique (défauts complétés par ses choix). */
function reglages(array $c): array
{
    $r = json_decode((string)$c['reglages'], true);
    $r = is_array($r) ? $r : [];
    $d = reglages_defaut($c);
    foreach ($d as $k => $v) {
        if (is_array($v) && isset($r[$k]) && is_array($r[$k])) {
            $d[$k] = array_merge($v, $r[$k]);
        } elseif (array_key_exists($k, $r)) {
            $d[$k] = $r[$k];
        }
    }
    $d['textes'] = array_merge(textes_defaut($c), (array)$d['textes']);
    return $d;
}

function clinique(int $id): ?array
{
    return une('SELECT * FROM cliniques WHERE id = ?', [$id]);
}

function clinique_par_slug(string $slug): ?array
{
    return une('SELECT * FROM cliniques WHERE slug = ? AND actif = 1', [$slug]);
}

function evenement(array $c, string $type, string $detail = '', float $valeur = 0): void
{
    inserer('INSERT INTO evenements (clinique_id, t, type, detail, valeur) VALUES (?, ?, ?, ?, ?)', [$c['id'], iso(maintenant($c)), $type, $detail, $valeur]);
}

function clinique_par_domaine(string $hote): ?array
{
    $hote = strtolower(preg_replace('/:\d+$/', '', $hote));
    return $hote === '' ? null : une('SELECT * FROM cliniques WHERE domaine = ? AND actif = 1', [$hote]);
}

// --- Image du client : couleur et logo ------------------------------------------------------

/** Couleur d'accent du client, assombrie si besoin pour que le texte blanc reste lisible (contraste 4,5:1). */
function couleur_accent(array $c): string
{
    $hex = preg_match('/^#[0-9a-f]{6}$/i', (string)($c['couleur'] ?? '')) ? strtolower($c['couleur']) : '#6b2150';
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    for ($i = 0; $i < 20 && contraste_blanc($r, $g, $b) < 4.5; $i++) {
        $r = (int)round($r * 0.9);
        $g = (int)round($g * 0.9);
        $b = (int)round($b * 0.9);
    }
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/** Teinte très claire de la couleur d'accent (fonds de sélection). */
function couleur_claire(string $hex): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return sprintf('#%02x%02x%02x', (int)round($r * 0.1 + 229.5), (int)round($g * 0.1 + 229.5), (int)round($b * 0.1 + 229.5));
}

function contraste_blanc(int $r, int $g, int $b): float
{
    $l = 0.0;
    foreach ([[$r, 0.2126], [$g, 0.7152], [$b, 0.0722]] as [$v, $k]) {
        $v /= 255;
        $l += $k * ($v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4);
    }
    return 1.05 / ($l + 0.05);
}

/** Fichier d'une image du client ('logo' ou 'couverture'), rangée dans prive/donnees/logos/. */
function image_fichier(array $c, string $quoi = 'logo'): string
{
    $nom = (string)($c[$quoi === 'couverture' ? 'couverture' : 'logo'] ?? '');
    return $nom !== '' ? __DIR__ . '/donnees/logos/' . basename($nom) : '';
}

function logo_fichier(array $c): string
{
    return image_fichier($c, 'logo');
}

/** Adresse publique d'une image (le nom du fichier change à chaque envoi, ce qui évite les caches périmés). */
function image_url(array $c, string $quoi = 'logo'): string
{
    $f = image_fichier($c, $quoi);
    return $f !== '' && is_file($f) ? url_base() . '/p/logo.php?c=' . rawurlencode($c['slug']) . ($quoi === 'couverture' ? '&i=couverture' : '') . '&v=' . substr(md5(basename($f)), 0, 8) : '';
}

function logo_url(array $c): string
{
    return image_url($c, 'logo');
}

/** Position CSS de la photo de couverture selon le cadrage choisi. */
function cadrage_css(array $c): string
{
    return ['haut' => 'center 20%', 'bas' => 'center 80%'][(string)($c['couverture_cadrage'] ?? '')] ?? 'center';
}

function url_base(): string
{
    $u = rtrim((string)cfg('url_site'), '/');
    if ($u !== '') {
        return $u;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $hote = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    // Racine de l'application : le dossier parent de app/, p/ ou webhooks/.
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $racine = preg_replace('#/(app|p|webhooks)(/[^/]*)?$#', '', dirname($script) . '/x');
    $racine = rtrim(preg_replace('#/x$#', '', $racine), '/');
    return ($https ? 'https' : 'http') . '://' . $hote . $racine;
}

function repondre_json(int $code, array $d): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function euros(float $n): string
{
    return number_format($n, 0, ',', ' ') . ' €';
}
