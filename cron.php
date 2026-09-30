<?php
/**
 * Plateforme SALW Santé : lance les automatisations de toutes les cliniques.
 * Tâche cron toutes les 5 minutes :  php /home/<compte>/public_html/<dossier>/cron.php
 * Les cliniques de démonstration sont ignorées (leur horloge avance à la main).
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Réservé à la tâche planifiée.');
}
require __DIR__ . '/prive/noyau.php';
require __DIR__ . '/prive/messages.php';
require __DIR__ . '/prive/agenda.php';
require __DIR__ . '/prive/moteur.php';
require __DIR__ . '/prive/rapport.php';

foreach (toutes('SELECT * FROM cliniques WHERE actif = 1 AND demo = 0') as $c) {
    foreach (executer_automatisations($c) as $ligne) {
        echo date('Y-m-d H:i') . " [{$c['slug']}] {$ligne}\n";
    }
}
// Conservation : patients sans rendez-vous depuis N mois (et sans liste d'attente active) supprimés.
$limite = iso(time() - (int)cfg('conservation_patients_mois') * 30 * 86400);
$n = executer("DELETE FROM patients WHERE cree_le < ? AND NOT EXISTS (SELECT 1 FROM rdv WHERE patient_id = patients.id AND debut > ?)
    AND NOT EXISTS (SELECT 1 FROM attente WHERE patient_id = patients.id AND statut = 'active')", [$limite, $limite]);
if ($n) {
    echo "Conservation : {$n} patient(s) supprimé(s)\n";
}
