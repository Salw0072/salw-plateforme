<?php
/**
 * Plateforme SALW : espace de travail (SALW, responsables, secrétariats), tous métiers.
 * Point d'entrée unique : app/?p=page. Toute la logique est dans prive/ (inaccessible depuis le web).
 */

declare(strict_types=1);

$prive = dirname(__DIR__) . '/prive';
foreach (['noyau', 'messages', 'agenda', 'moteur', 'rapport', 'tarifs', 'acces', 'demo', 'app/vues', 'app/pages-accueil', 'app/pages-agenda', 'app/pages-gestion', 'app/pages-demo', 'app/pages-marque', 'app/pages-abonnement'] as $f) {
    require $prive . '/' . $f . '.php';
}

demarrer_session();
$page = preg_replace('/[^a-z]/', '', (string)($_GET['p'] ?? 'tableau'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valide()) {
    flash('erreur', 'Session expirée ou formulaire périmé : recommencez.');
    aller(moi() ? $page : 'connexion');
}
if ((int)valeur('SELECT COUNT(*) FROM utilisateurs') === 0) {
    page_installation();
    exit;
}
if ($page === 'deconnexion') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && moi()) {
        journaliser('deconnexion', moi()['identifiant']);
        deconnecter();
        flash('info', 'Vous êtes déconnecté.');
    }
    aller('connexion');
}
// Mot de passe oublié : accessible sans être connecté.
if ($page === 'oubli') {
    page_oubli();
    exit;
}
if ($page === 'nouveaumdp') {
    page_nouveau_mdp();
    exit;
}
$u = moi();
if (!$u || $page === 'connexion') {
    if ($u) {
        aller('tableau');
    }
    page_connexion();
    exit;
}
if ((int)$u['changer_mdp'] && $page !== 'compte') {
    aller('compte');
}
if (!clinique_courante() && !in_array($page, ['cliniques', 'compte'], true)) {
    aller(est_salw() ? 'cliniques' : 'compte');
}

$pages = [
    'tableau' => 'page_tableau', 'agenda' => 'page_agenda', 'nouveau' => 'page_nouveau', 'patients' => 'page_patients', 'attente' => 'page_attente',
    'appels' => 'page_appels', 'messages' => 'page_messages', 'devis' => 'page_devis', 'automatisations' => 'page_automatisations', 'clinique' => 'page_clinique',
    'equipe' => 'page_equipe', 'cliniques' => 'page_cliniques', 'demo' => 'page_demo', 'compte' => 'page_compte',
    'integration' => 'page_integration', 'rapport' => 'page_rapport', 'abonnement' => 'page_abonnement',
];
($pages[$page] ?? 'page_tableau')();
