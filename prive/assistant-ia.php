<?php
/**
 * Plateforme SALW Santé : réponses de l'assistant des patients par Claude (SDK Anthropic officiel).
 * PHP 8.1+ (arguments nommés). Renvoie null en cas d'échec : l'assistant répond alors par la FAQ.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;

function assistant_claude(array $c, array $historique): ?string
{
    $urgences = $c['pays'] === 'BE' ? '112 (urgences) ou 1813 (prévention du suicide)' : '15 (SAMU), 112, ou 3114 (prévention du suicide)';
    $st = mot($c, 'structure');
    $clients = mot($c, 'clients');
    $pros = mot($c, 'pros');
    $interdit = metier($c)['interdit'];
    $consignes = <<<TXT
Tu es l'assistant en ligne de la structure décrite ci-dessous ({$st}), pour ses {$clients}, 24 h sur 24. Tu es une IA et tu le dis si on te le demande.

Tu réponds uniquement aux questions pratiques : horaires, adresse, accès, stationnement, {$pros}, types de rendez-vous, documents à apporter, prise ou annulation de rendez-vous. Tu t'appuies SEULEMENT sur les informations ci-dessous ; si l'information n'y est pas, tu le dis et tu proposes d'appeler.

Interdits absolus :
- {$interdit} ;
- en cas de signe d'urgence vitale ou de détresse, oriente immédiatement vers le {$urgences} ;
- ne demande aucune information sensible (santé, détails d'une affaire, données bancaires) ;
- n'invente ni tarif, ni horaire, ni personne, ni service.

Style : vouvoiement, deux ou trois phrases, sans liste. Langue du patient (français par défaut).

INFORMATIONS DE LA CLINIQUE
TXT;
    try {
        $client = new Client(apiKey: (string)cfg('ia_cle'));
        $reponse = $client->beta->messages->create(
            model: (string)cfg('ia_modele'),
            maxTokens: 2048,
            system: [['type' => 'text', 'text' => $consignes . "\n" . fiche_clinique($c), 'cacheControl' => ['type' => 'ephemeral']]],
            messages: $historique,
            outputConfig: ['effort' => (string)cfg('ia_effort')],
            betas: ['server-side-fallback-2026-07-01'],
            fallbacks: 'default',
            requestOptions: ['timeout' => 25.0, 'maxRetries' => 1],
        );
        if ($reponse->stopReason === 'refusal') {
            return null;
        }
        $textes = [];
        foreach ($reponse->content as $bloc) {
            if ($bloc->type === 'text' && trim($bloc->text) !== '') {
                $textes[] = trim($bloc->text);
            }
        }
        return $textes ? implode("\n\n", $textes) : null;
    } catch (APIStatusException $e) {
        return null;
    } catch (APIConnectionException $e) {
        return null;
    }
}
