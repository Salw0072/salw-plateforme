<?php
/**
 * MODÈLE. Sur le serveur, copiez ce fichier en prive/config.php, puis renseignez-y les clés.
 * config.php n'est jamais versionné (voir .gitignore) : aucune clé ne doit aller sur GitHub.
 *
 * Plateforme SALW Santé : réglages techniques de l'installation.
 *
 * Les réglages propres à chaque clinique (horaires, praticiens, automatisations,
 * textes des messages) se modifient dans l'application, pas ici.
 */

return [
    // Adresse publique, sans « / » final (liens envoyés aux patients). Vide = déduite.
    'url_site' => '',

    // « simulation » : aucun SMS ni e-mail ne part ; ils s'affichent dans l'application
    // (téléphone à l'écran). « reel » : envoi par Twilio (SMS) et par e-mail.
    'mode_envoi' => 'simulation',

    // SMS réels (mode « reel ») : compte Twilio.
    'twilio' => ['sid' => '', 'token' => '', 'expediteur' => ''],

    // E-mails aux patients sans mobile (mode « reel »).
    'email_expediteur' => 'plateforme@salw-consulting.com',

    // Assistant IA des patients (Claude). Vide : réponses tirées de la FAQ de la clinique.
    'ia_cle'    => '',
    'ia_modele' => 'claude-opus-5',
    'ia_effort' => 'low',
    'ia_max_jour' => 500,

    // Clé du point d'entrée « appel manqué » (standard téléphonique, Twilio, Ringover...).
    // Au moins 24 caractères. Vide = point d'entrée fermé.
    'cle_webhook' => '',

    // Données des patients : suppression des patients sans rendez-vous depuis (mois).
    'conservation_patients_mois' => 36,
];
