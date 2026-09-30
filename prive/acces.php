<?php
/**
 * Plateforme SALW Santé : comptes, rôles, session, sécurité.
 *
 * Rôles :
 *   salw         équipe SALW : toutes les cliniques, création de cliniques, démonstration
 *   responsable  une clinique : tout, dont automatisations, réglages et équipe de la clinique
 *   secretariat  une clinique : agenda, patients, liste d'attente, appels, messages
 * Le premier compte créé (page d'installation) est un compte SALW.
 */

declare(strict_types=1);

const ROLES = ['salw' => 'Équipe SALW', 'responsable' => 'Responsable de clinique', 'secretariat' => 'Secrétariat'];
const INACTIVITE_MAX = 7200;
const ECHECS_MAX = 5;
const BLOCAGE = 900;

function demarrer_session(): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_name('salw_sante');
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function csrf(): string { return (string)$_SESSION['csrf']; }
function champ_csrf(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function csrf_valide(): bool { return hash_equals(csrf(), (string)($_POST['csrf'] ?? '')); }

function moi(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    $id = (int)($_SESSION['uid'] ?? 0);
    if (!$id) {
        return null;
    }
    if (time() - (int)($_SESSION['activite'] ?? 0) > INACTIVITE_MAX) {
        deconnecter();
        $_SESSION['flash'] = ['info', 'Session expirée : reconnectez-vous.'];
        return null;
    }
    $compte = une('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1', [$id]);
    if (!$compte) {
        deconnecter();
        return null;
    }
    $_SESSION['activite'] = time();
    $u = $compte;
    return $u;
}

function est_salw(): bool { return (moi()['role'] ?? '') === 'salw'; }
function peut_gerer(): bool { return in_array(moi()['role'] ?? '', ['salw', 'responsable'], true); }

/** Clinique en cours : fixe pour une clinique, choisie par l'équipe SALW. */
function clinique_courante(): ?array
{
    $u = moi();
    if (!$u) {
        return null;
    }
    if ($u['role'] !== 'salw') {
        return clinique((int)$u['clinique_id']);
    }
    $id = (int)($_SESSION['clinique_id'] ?? 0);
    $c = $id ? clinique($id) : null;
    if (!$c) {
        $c = une('SELECT * FROM cliniques ORDER BY demo DESC, id LIMIT 1');
        if ($c) {
            $_SESSION['clinique_id'] = (int)$c['id'];
        }
    }
    return $c;
}

function deconnecter(): void
{
    $_SESSION = ['csrf' => bin2hex(random_bytes(16))];
    session_regenerate_id(true);
}

function creer_compte(string $identifiant, string $nom, string $role, string $mdp, ?int $cliniqueId, bool $changer): array
{
    $identifiant = mb_strtolower(trim($identifiant));
    if (!filter_var($identifiant, FILTER_VALIDATE_EMAIL)) {
        return [false, "L'identifiant doit être une adresse e-mail."];
    }
    if (!isset(ROLES[$role]) || ($role !== 'salw' && !$cliniqueId)) {
        return [false, 'Rôle ou clinique invalide.'];
    }
    if (mb_strlen($mdp) < 12) {
        return [false, 'Le mot de passe doit faire au moins 12 caractères.'];
    }
    if (une('SELECT id FROM utilisateurs WHERE identifiant = ?', [$identifiant])) {
        return [false, 'Un compte existe déjà avec cet identifiant.'];
    }
    $id = inserer('INSERT INTO utilisateurs (clinique_id, identifiant, nom, role, hash, changer_mdp, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$role === 'salw' ? null : $cliniqueId, $identifiant, mb_substr(trim($nom), 0, 80), $role, password_hash($mdp, PASSWORD_DEFAULT), $changer ? 1 : 0, iso(time())]);
    return [true, une('SELECT * FROM utilisateurs WHERE id = ?', [$id])];
}

function tenter_connexion(string $identifiant, string $mdp): array
{
    $identifiant = mb_strtolower(trim($identifiant));
    $verrou = sys_get_temp_dir() . '/salw_sante_' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')) . '.lock';
    if (is_file($verrou) && time() - (int)filemtime($verrou) < 3) {
        return ['erreur', 'Patientez quelques secondes avant de réessayer.'];
    }
    $u = une('SELECT * FROM utilisateurs WHERE identifiant = ?', [$identifiant]);
    if ($u && (int)$u['bloque_jusqua'] > time()) {
        return ['erreur', 'Compte temporairement bloqué après plusieurs échecs. Réessayez dans ' . ceil(((int)$u['bloque_jusqua'] - time()) / 60) . ' min.'];
    }
    // Vérification même sans compte, pour ne pas révéler par le temps de réponse qu'il n'existe pas.
    $ok = password_verify($mdp, $u ? $u['hash'] : '$2y$10$abcdefghijklmnopqrstuuO5E4I4bD3vN6k9Hs7m1jqf8wL2lYx9W');
    if (!$u || !$ok || !(int)$u['actif']) {
        @touch($verrou);
        if ($u) {
            $echecs = (int)$u['echecs'] + 1;
            executer('UPDATE utilisateurs SET echecs = ?, bloque_jusqua = ? WHERE id = ?', [$echecs >= ECHECS_MAX ? 0 : $echecs, $echecs >= ECHECS_MAX ? time() + BLOCAGE : 0, $u['id']]);
        }
        journaliser('connexion_echec', $identifiant, $u['clinique_id'] ?? null);
        sleep(1);
        return ['erreur', 'Identifiant ou mot de passe incorrect.'];
    }
    if (!empty($u['totp'])) {
        $_SESSION['en_attente'] = ['uid' => (int)$u['id'], 't' => time()];
        return ['totp', ''];
    }
    ouvrir_session($u);
    return ['ok', ''];
}

function valider_totp(string $code): array
{
    $a = $_SESSION['en_attente'] ?? null;
    if (!$a || time() - (int)$a['t'] > 300) {
        unset($_SESSION['en_attente']);
        return ['erreur', 'Délai dépassé : recommencez la connexion.'];
    }
    $u = une('SELECT * FROM utilisateurs WHERE id = ?', [(int)$a['uid']]);
    $pas = $u ? totp_verifier((string)$u['totp'], $code, (int)$u['totp_dernier']) : 0;
    if (!$pas) {
        if ($u) {
            $echecs = (int)$u['echecs'] + 1;
            executer('UPDATE utilisateurs SET echecs = ?, bloque_jusqua = ? WHERE id = ?', [$echecs >= ECHECS_MAX ? 0 : $echecs, $echecs >= ECHECS_MAX ? time() + BLOCAGE : 0, $u['id']]);
        }
        sleep(1);
        return ['erreur', 'Code incorrect.'];
    }
    executer('UPDATE utilisateurs SET totp_dernier = ? WHERE id = ?', [$pas, $u['id']]);
    unset($_SESSION['en_attente']);
    ouvrir_session($u);
    return ['ok', ''];
}

function ouvrir_session(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['activite'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    executer('UPDATE utilisateurs SET echecs = 0, bloque_jusqua = 0, derniere_connexion = ? WHERE id = ?', [iso(time()), $u['id']]);
    journaliser('connexion', $u['identifiant'], $u['clinique_id'] !== null ? (int)$u['clinique_id'] : null);
}

function journaliser(string $action, string $detail = '', ?int $cliniqueId = null): void
{
    $u = isset($_SESSION['uid']) ? une('SELECT identifiant FROM utilisateurs WHERE id = ?', [(int)$_SESSION['uid']]) : null;
    inserer('INSERT INTO journal (t, utilisateur, clinique_id, action, detail) VALUES (?, ?, ?, ?, ?)', [iso(time()), $u['identifiant'] ?? '', $cliniqueId, $action, mb_substr($detail, 0, 300)]);
}

// --- Double authentification (TOTP, RFC 6238) ------------------------------------------

function base32_encoder(string $o): string
{
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($o) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $s = '';
    foreach (str_split($bits, 5) as $m) {
        $s .= $a[bindec(str_pad($m, 5, '0'))];
    }
    return $s;
}

function base32_decoder(string $t): string
{
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $t))) as $c) {
        $bits .= str_pad(decbin((int)strpos($a, $c)), 5, '0', STR_PAD_LEFT);
    }
    $s = '';
    foreach (str_split($bits, 8) as $o) {
        if (strlen($o) === 8) {
            $s .= chr(bindec($o));
        }
    }
    return $s;
}

function totp_code(string $secret, int $pas): string
{
    $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $pas), base32_decoder($secret), true);
    $d = ord($hash[19]) & 0x0F;
    $v = ((ord($hash[$d]) & 0x7F) << 24) | ((ord($hash[$d + 1]) & 0xFF) << 16) | ((ord($hash[$d + 2]) & 0xFF) << 8) | (ord($hash[$d + 3]) & 0xFF);
    return str_pad((string)($v % 1000000), 6, '0', STR_PAD_LEFT);
}

function totp_verifier(string $secret, string $code, int $dernier): int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || $secret === '') {
        return 0;
    }
    $pas = (int)floor(time() / 30);
    foreach ([0, -1, 1] as $d) {
        if ($pas + $d > $dernier && hash_equals(totp_code($secret, $pas + $d), $code)) {
            return $pas + $d;
        }
    }
    return 0;
}
