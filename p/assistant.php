<?php
/**
 * Plateforme SALW Santé : assistant des patients (appelé par la page de réservation).
 * Corps JSON : {"c": "<clinique>", "messages": [{"role": "user"|"assistant", "content": "..."}]}.
 */

declare(strict_types=1);
require dirname(__DIR__) . '/prive/public.php';
require dirname(__DIR__) . '/prive/assistant.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    repondre_json(405, ['ok' => false]);
}
$corps = json_decode((string)file_get_contents('php://input', false, null, 0, 50000), true);
$c = is_array($corps) ? clinique_par_slug((string)($corps['c'] ?? '')) : null;
if (!$c) {
    repondre_json(404, ['ok' => false]);
}
if (!limite_ip('assistant', 40)) {
    repondre_json(200, ['ok' => true, 'texte' => 'Vous avez posé beaucoup de questions : appelez-nous au ' . telephone_lisible((string)$c['telephone']) . ', nous vous répondrons.']);
}
// Historique : texte seulement, rôles alternés, commence par le patient, 12 derniers messages.
$h = [];
foreach (array_slice((array)($corps['messages'] ?? []), -12) as $m) {
    $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $t = trim(mb_substr((string)($m['content'] ?? ''), 0, 500));
    if ($t === '' || (!$h && $role === 'assistant')) {
        continue;
    }
    if ($h && end($h)['role'] === $role) {
        $h[count($h) - 1]['content'] .= "\n" . $t;
    } else {
        $h[] = ['role' => $role, 'content' => $t];
    }
}
if (!$h || end($h)['role'] !== 'user') {
    repondre_json(422, ['ok' => false]);
}
$r = repondre_patient($c, $h);
repondre_json(200, ['ok' => true, 'texte' => $r['texte'], 'source' => $r['source']]);
