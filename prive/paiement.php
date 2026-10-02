<?php
/**
 * Plateforme SALW : paiement des abonnements par les clients.
 *
 * Stripe (principal) : carte ou prélèvement SEPA, abonnement mensuel + mise en place sur la première
 * facture, factures et reçus Stripe, SMS au-delà du forfait ajoutés automatiquement à la facture suivante,
 * portail Stripe pour changer de moyen de paiement ou télécharger les factures.
 * PayPal (option) : abonnement mensuel + mise en place ; les SMS supplémentaires sont facturés à part.
 *
 * Statuts (cliniques.paiement_statut) : '' (pas encore payé), en_attente, actif, impaye, annule.
 * Les notifications (webhooks/stripe.php, webhooks/paypal.php) font foi ; le retour du client sur la
 * page Abonnement met à jour le statut tout de suite, sans attendre la notification.
 */

declare(strict_types=1);

require_once __DIR__ . '/paiement-api.php';

const STATUTS_PAIEMENT = ['' => 'Non payé', 'en_attente' => 'En attente', 'essai' => 'Essai gratuit', 'actif' => 'Actif', 'impaye' => 'Impayé', 'annule' => 'Résilié'];

/** Ce que le client paiera : abonnement mensuel, mise en place, et s'il a droit à l'essai gratuit. */
function recap_paiement(array $c): array
{
    $f = formule_client($c);
    // La mise en place ne se paie qu'une fois : ni si elle est offerte, ni lors d'un réabonnement.
    $dejaPaye = (int)valeur("SELECT COUNT(*) FROM paiements WHERE clinique_id = ? AND statut = 'paye'", [$c['id']]) > 0;
    $offerte = (int)($c['mise_en_place_offerte'] ?? 0) === 1;
    $miseEnPlace = $offerte || $dejaPaye ? 0.0 : (float)(FORMULES[$f]['mise_en_place'] ?? 0);
    // Essai gratuit : à partir de la deuxième offre, une seule fois, jamais après un premier paiement.
    $essai = in_array($f, FORMULES_AVEC_ESSAI, true) && !(int)($c['paiement_essai_utilise'] ?? 0) && !$dejaPaye;
    return ['mensuel' => prix_mensuel($c), 'mise_en_place' => $miseEnPlace, 'formule' => $f, 'essai' => $essai,
        'raison_sans_mise_en_place' => $dejaPaye ? 'déjà réglée' : ($offerte ? 'offerte' : '')];
}

/** Date de fin d'un essai qui commencerait maintenant. */
function fin_essai_previsionnelle(): int
{
    return time() + ESSAI_JOURS * 86400;
}

/** Démarre l'essai côté plateforme : statut, date de fin, essai consommé. */
function demarrer_essai(array $c, int $fin, string $fournisseur): void
{
    maj_paiement((int)$c['id'], ['paiement_statut' => 'essai', 'paiement_essai_fin' => iso($fin), 'paiement_essai_utilise' => 1]);
    evenement($c, 'paiement_essai', $fournisseur . ' · jusqu\'au ' . date('d/m', $fin));
}

function parametre(string $cle): string
{
    return (string)(valeur('SELECT valeur FROM parametres WHERE cle = ?', [$cle]) ?? '');
}

function definir_parametre(string $cle, string $valeur): void
{
    executer('INSERT INTO parametres (cle, valeur) VALUES (?, ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur', [$cle, $valeur]);
}

function maj_paiement(int $cliniqueId, array $champs): void
{
    $champs['paiement_maj'] = iso(time());
    $sets = implode(', ', array_map(function ($k) { return $k . ' = ?'; }, array_keys($champs)));
    executer('UPDATE cliniques SET ' . $sets . ' WHERE id = ?', array_merge(array_values($champs), [$cliniqueId]));
}

function enregistrer_paiement(int $cliniqueId, string $fournisseur, string $type, string $libelle, float $montant, string $statut, string $ref): void
{
    db()->prepare('INSERT INTO paiements (clinique_id, fournisseur, type, libelle, montant, statut, ref, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(fournisseur, ref) DO UPDATE SET statut = excluded.statut, montant = excluded.montant')
        ->execute([$cliniqueId, $fournisseur, $type, $libelle, $montant, $statut, $ref, iso(time())]);
}

function url_abonnement(string $suite = ''): string
{
    return url_base() . '/app/?p=abonnement' . $suite;
}

function libelle_abonnement(array $c): string
{
    return 'Plateforme SALW · offre ' . libelle_formule(formule_client($c)) . ' · ' . $c['nom'];
}

// --- Stripe ------------------------------------------------------------------------------------

/** Ouvre une page de paiement Stripe (abonnement mensuel + mise en place) ; renvoie son adresse. */
function stripe_ouvrir_paiement(array $c, string $email): string
{
    $r = recap_paiement($c);
    if ($r['mensuel'] <= 0) {
        throw new RuntimeException('Prix à fixer avant le paiement (offre sur mesure sans prix négocié).');
    }
    $client = (string)$c['paiement_client_ref'];
    if ($client === '' || (string)$c['paiement_fournisseur'] !== 'stripe') {
        $client = (string)stripe('POST', '/v1/customers', ['email' => $email, 'name' => $c['nom'], 'preferred_locales' => ['fr'], 'metadata' => ['clinique_id' => (string)$c['id']]])['id'];
    }
    $lignes = [['price_data' => ['currency' => 'eur', 'unit_amount' => centimes($r['mensuel']), 'recurring' => ['interval' => 'month'], 'product_data' => ['name' => libelle_abonnement($c)]], 'quantity' => 1]];
    $abonnement = ['metadata' => ['clinique_id' => (string)$c['id']], 'description' => libelle_abonnement($c)];
    if ($r['essai']) {
        // Essai : rien n'est prélevé pendant 7 jours ; la mise en place s'ajoute à la première vraie facture
        // (ligne créée au retour du client, voir stripe_programmer_mise_en_place). Sans moyen de paiement, l'abonnement s'arrête.
        $abonnement['trial_period_days'] = ESSAI_JOURS;
        $abonnement['trial_settings'] = ['end_behavior' => ['missing_payment_method' => 'cancel']];
    } elseif ($r['mise_en_place'] > 0) {
        $lignes[] = ['price_data' => ['currency' => 'eur', 'unit_amount' => centimes($r['mise_en_place']), 'product_data' => ['name' => 'Mise en place de la plateforme SALW']], 'quantity' => 1];
    }
    $session = stripe('POST', '/v1/checkout/sessions', [
        'mode' => 'subscription', 'customer' => $client, 'line_items' => $lignes, 'locale' => 'fr', 'payment_method_collection' => 'always',
        'client_reference_id' => (string)$c['id'],
        'metadata' => ['clinique_id' => (string)$c['id'], 'essai' => $r['essai'] ? '1' : '0', 'mise_en_place' => $r['essai'] ? (string)centimes($r['mise_en_place']) : '0'],
        'subscription_data' => $abonnement,
        'billing_address_collection' => 'required', 'tax_id_collection' => ['enabled' => 'true'], 'customer_update' => ['address' => 'auto', 'name' => 'auto'],
        'success_url' => url_abonnement('&paiement=stripe&session={CHECKOUT_SESSION_ID}'), 'cancel_url' => url_abonnement('&paiement=annule'),
    ]);
    maj_paiement((int)$c['id'], ['paiement_fournisseur' => 'stripe', 'paiement_client_ref' => $client, 'paiement_statut' => in_array($c['paiement_statut'], ['actif', 'impaye'], true) ? $c['paiement_statut'] : 'en_attente']);
    return (string)$session['url'];
}

/** Retour du client après Stripe Checkout : confirme sans attendre la notification. */
function stripe_confirmer_retour(array $c, string $sessionId): string
{
    if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
        return 'Retour de paiement non reconnu.';
    }
    $s = stripe('GET', '/v1/checkout/sessions/' . $sessionId);
    if ((string)($s['metadata']['clinique_id'] ?? '') !== (string)$c['id']) {
        return 'Ce paiement ne correspond pas à ce client.';
    }
    if (($s['status'] ?? '') !== 'complete') {
        return 'Paiement non terminé.';
    }
    if (($s['metadata']['essai'] ?? '') === '1') {
        $fin = stripe_session_essai($c, $s);
        return 'Essai gratuit activé : aucun prélèvement avant le ' . date('d/m/Y', $fin) . '. Vous pouvez résilier d\'ici là sans rien payer.';
    }
    $paye = in_array($s['payment_status'] ?? '', ['paid', 'no_payment_required'], true);
    maj_paiement((int)$c['id'], ['paiement_fournisseur' => 'stripe', 'paiement_client_ref' => (string)($s['customer'] ?? $c['paiement_client_ref']),
        'paiement_abonnement_ref' => (string)($s['subscription'] ?? ''), 'paiement_statut' => $paye ? 'actif' : 'en_attente']);
    evenement($c, 'paiement_' . ($paye ? 'actif' : 'en_attente'), 'Stripe');
    return $paye ? 'Paiement reçu : votre abonnement est actif. Merci !' : 'Paiement enregistré : le prélèvement SEPA sera confirmé sous quelques jours.';
}

/**
 * Session Stripe terminée avec essai : enregistre l'abonnement, démarre l'essai (date réelle donnée par Stripe)
 * et programme la mise en place sur la première vraie facture. Sans effet si déjà fait. Renvoie la fin de l'essai.
 */
function stripe_session_essai(array $c, array $s): int
{
    $abonnement = (string)($s['subscription'] ?? '');
    $fin = fin_essai_previsionnelle();
    if ($abonnement !== '') {
        $a = stripe('GET', '/v1/subscriptions/' . $abonnement);
        $fin = (int)($a['trial_end'] ?? 0) ?: $fin;
    }
    maj_paiement((int)$c['id'], ['paiement_fournisseur' => 'stripe', 'paiement_client_ref' => (string)($s['customer'] ?? $c['paiement_client_ref']), 'paiement_abonnement_ref' => $abonnement]);
    $c = clinique((int)$c['id']);
    if ($c['paiement_statut'] !== 'essai' || $c['paiement_essai_fin'] === '') {
        demarrer_essai($c, $fin, 'Stripe');
    }
    stripe_programmer_mise_en_place(clinique((int)$c['id']), $abonnement, (int)($s['metadata']['mise_en_place'] ?? 0));
    return $fin;
}

/** Ajoute la mise en place à la prochaine facture de l'abonnement (celle de la fin de l'essai), une seule fois. */
function stripe_programmer_mise_en_place(array $c, string $abonnement, int $centimes): void
{
    if ($centimes <= 0 || $abonnement === '' || (string)$c['paiement_mise_en_place_ref'] !== '') {
        return;
    }
    $ligne = stripe('POST', '/v1/invoiceitems', ['customer' => (string)$c['paiement_client_ref'], 'subscription' => $abonnement, 'amount' => $centimes, 'currency' => 'eur',
        'description' => 'Mise en place de la plateforme SALW', 'metadata' => ['clinique_id' => (string)$c['id']]], 'mep-' . $c['id'] . '-' . $abonnement);
    maj_paiement((int)$c['id'], ['paiement_mise_en_place_ref' => (string)($ligne['id'] ?? '')]);
}

/** Portail Stripe : moyen de paiement, factures, adresse de facturation. */
function stripe_portail(array $c): string
{
    return (string)stripe('POST', '/v1/billing_portal/sessions', ['customer' => (string)$c['paiement_client_ref'], 'locale' => 'fr', 'return_url' => url_abonnement()])['url'];
}

// --- PayPal ------------------------------------------------------------------------------------

/** Ouvre un abonnement PayPal (plan propre à ce client, mise en place en frais initiaux) ; renvoie l'adresse d'approbation. */
function paypal_ouvrir_abonnement(array $c, string $email): string
{
    $r = recap_paiement($c);
    if ($r['mensuel'] <= 0) {
        throw new RuntimeException('Prix à fixer avant le paiement (offre sur mesure sans prix négocié).');
    }
    $produit = parametre('paypal_produit_' . (cfg('paypal_mode') === 'live' ? 'live' : 'test'));
    if ($produit === '') {
        $produit = (string)paypal('POST', '/v1/catalogs/products', ['name' => 'Plateforme SALW', 'description' => 'Plateforme de rendez-vous et d\'automatisations opérée par SALW CONSULTING', 'type' => 'SERVICE', 'category' => 'SOFTWARE'])['id'];
        definir_parametre('paypal_produit_' . (cfg('paypal_mode') === 'live' ? 'live' : 'test'), $produit);
    }
    $mois = function (int $sequence, string $type, float $prix, int $nombre): array {
        return ['frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1], 'tenure_type' => $type, 'sequence' => $sequence, 'total_cycles' => $nombre,
            'pricing_scheme' => ['fixed_price' => ['value' => montant_paypal($prix), 'currency_code' => 'EUR']]];
    };
    if ($r['essai']) {
        // Essai : 7 jours gratuits, puis un premier mois qui inclut la mise en place, puis le prix mensuel.
        $cycles = [['frequency' => ['interval_unit' => 'DAY', 'interval_count' => ESSAI_JOURS], 'tenure_type' => 'TRIAL', 'sequence' => 1, 'total_cycles' => 1]];
        if ($r['mise_en_place'] > 0) {
            $cycles[] = $mois(2, 'TRIAL', $r['mensuel'] + $r['mise_en_place'], 1);
        }
        $cycles[] = $mois(count($cycles) + 1, 'REGULAR', $r['mensuel'], 0);
        $preferences = ['auto_bill_outstanding' => true, 'payment_failure_threshold' => 2];
    } else {
        $cycles = [$mois(1, 'REGULAR', $r['mensuel'], 0)];
        $preferences = ['auto_bill_outstanding' => true, 'payment_failure_threshold' => 2]
            + ($r['mise_en_place'] > 0 ? ['setup_fee' => ['value' => montant_paypal($r['mise_en_place']), 'currency_code' => 'EUR'], 'setup_fee_failure_action' => 'CANCEL'] : []);
    }
    $plan = paypal('POST', '/v1/billing/plans', [
        'product_id' => $produit, 'name' => mb_substr(libelle_abonnement($c), 0, 127), 'status' => 'ACTIVE',
        'billing_cycles' => $cycles, 'payment_preferences' => $preferences,
    ]);
    $abo = paypal('POST', '/v1/billing/subscriptions', [
        'plan_id' => $plan['id'], 'custom_id' => (string)$c['id'], 'subscriber' => ['email_address' => $email],
        'application_context' => ['brand_name' => 'SALW CONSULTING', 'locale' => 'fr-FR', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'SUBSCRIBE_NOW',
            'return_url' => url_abonnement('&paiement=paypal'), 'cancel_url' => url_abonnement('&paiement=annule')],
    ]);
    maj_paiement((int)$c['id'], ['paiement_fournisseur' => 'paypal', 'paiement_plan_ref' => (string)$plan['id'], 'paiement_abonnement_ref' => (string)$abo['id'], 'paiement_statut' => 'en_attente']);
    definir_parametre('paypal_essai_' . $abo['id'], $r['essai'] ? '1' : '0');
    $lien = paypal_lien($abo, 'approve');
    if ($lien === '') {
        throw new RuntimeException('PayPal n\'a pas renvoyé de lien de validation.');
    }
    return $lien;
}

/** Retour du client après validation PayPal. */
function paypal_confirmer_retour(array $c, string $abonnementId): string
{
    if (!preg_match('/^I-[A-Z0-9]+$/', $abonnementId) || $abonnementId !== (string)$c['paiement_abonnement_ref']) {
        return 'Retour PayPal non reconnu.';
    }
    $a = paypal('GET', '/v1/billing/subscriptions/' . $abonnementId);
    if ((string)($a['custom_id'] ?? '') !== (string)$c['id']) {
        return 'Cet abonnement PayPal ne correspond pas à ce client.';
    }
    $statut = ['ACTIVE' => 'actif', 'APPROVED' => 'en_attente', 'SUSPENDED' => 'impaye', 'CANCELLED' => 'annule', 'EXPIRED' => 'annule'][$a['status'] ?? ''] ?? 'en_attente';
    if ($statut === 'actif' && parametre('paypal_essai_' . $abonnementId) === '1') {
        if ($c['paiement_statut'] !== 'essai') {
            demarrer_essai($c, fin_essai_previsionnelle(), 'PayPal');
        }
        return 'Essai gratuit activé : aucun prélèvement PayPal avant le ' . date('d/m/Y', fin_essai_previsionnelle()) . ". Vous pouvez résilier d'ici là sans rien payer.";
    }
    maj_paiement((int)$c['id'], ['paiement_statut' => $statut]);
    evenement($c, 'paiement_' . $statut, 'PayPal');
    return $statut === 'actif' ? 'Abonnement PayPal actif. Merci !' : 'Abonnement PayPal validé : activation en cours.';
}

// --- Changement d'offre ------------------------------------------------------------------------

/** Répercute le nouveau prix mensuel chez Stripe ou PayPal (à partir de la prochaine échéance, sans prorata). */
function synchroniser_prix(array $c): string
{
    if (!in_array($c['paiement_statut'], ['actif', 'impaye', 'en_attente', 'essai'], true) || (string)$c['paiement_abonnement_ref'] === '') {
        return '';
    }
    $prix = prix_mensuel($c);
    if ($prix <= 0) {
        return 'Prix à fixer : l\'abonnement en cours n\'a pas été modifié.';
    }
    if ($c['paiement_fournisseur'] === 'stripe') {
        $abo = stripe('GET', '/v1/subscriptions/' . $c['paiement_abonnement_ref']);
        $ligne = $abo['items']['data'][0] ?? null;
        if (!$ligne) {
            throw new RuntimeException('Abonnement Stripe sans ligne de prix.');
        }
        stripe('POST', '/v1/subscriptions/' . $c['paiement_abonnement_ref'], [
            'items' => [['id' => $ligne['id'], 'price_data' => ['currency' => 'eur', 'product' => (string)($ligne['price']['product'] ?? ''), 'unit_amount' => centimes($prix), 'recurring' => ['interval' => 'month']]]],
            'proration_behavior' => 'none', 'description' => libelle_abonnement($c),
        ]);
        return 'Stripe mis à jour : ' . montant($prix) . ' HT par mois à partir de la prochaine échéance.';
    }
    if ($c['paiement_fournisseur'] === 'paypal' && (string)$c['paiement_plan_ref'] !== '') {
        // Le cycle régulier n'est pas toujours le premier (essai) : on le retrouve dans le plan.
        $plan = paypal('GET', '/v1/billing/plans/' . $c['paiement_plan_ref']);
        $sequence = 1;
        foreach ($plan['billing_cycles'] ?? [] as $cycle) {
            if (($cycle['tenure_type'] ?? '') === 'REGULAR') {
                $sequence = (int)$cycle['sequence'];
            }
        }
        paypal('POST', '/v1/billing/plans/' . $c['paiement_plan_ref'] . '/update-pricing-schemes', ['pricing_schemes' => [
            ['billing_cycle_sequence' => $sequence, 'pricing_scheme' => ['fixed_price' => ['value' => montant_paypal($prix), 'currency_code' => 'EUR']]],
        ]]);
        return 'PayPal mis à jour : ' . montant($prix) . ' HT par mois. PayPal peut demander au client de valider la hausse.';
    }
    return '';
}

// --- Notifications -----------------------------------------------------------------------------

/** Une notification n'est traitée qu'une fois (Stripe et PayPal peuvent la renvoyer). */
function evenement_deja_traite(string $id): bool
{
    $st = db()->prepare('INSERT OR IGNORE INTO paiements_evenements (id, recu_le) VALUES (?, ?)');
    $st->execute([$id, iso(time())]);
    return $st->rowCount() === 0;
}

function clinique_par_paiement(string $champ, string $valeur): ?array
{
    return $valeur === '' ? null : une('SELECT * FROM cliniques WHERE ' . $champ . ' = ?', [$valeur]);
}

function traiter_evenement_stripe(array $e): void
{
    if (empty($e['id']) || evenement_deja_traite('stripe:' . $e['id'])) {
        return;
    }
    $o = $e['data']['object'] ?? [];
    // Client concerné : métadonnée posée à la création, sinon abonnement, sinon client Stripe.
    // Selon la version de l'API, l'abonnement d'une facture est dans « subscription » ou dans « parent ».
    $details = $o['parent']['subscription_details'] ?? [];
    $cliniqueId = (string)($o['metadata']['clinique_id'] ?? $details['metadata']['clinique_id'] ?? '');
    $abonnement = ($o['object'] ?? '') === 'subscription' ? (string)$o['id'] : (string)(is_string($o['subscription'] ?? null) ? $o['subscription'] : ($details['subscription'] ?? ''));
    $c = ($cliniqueId !== '' ? clinique((int)$cliniqueId) : null)
        ?: clinique_par_paiement('paiement_abonnement_ref', $abonnement)
        ?: clinique_par_paiement('paiement_client_ref', is_string($o['customer'] ?? null) ? $o['customer'] : '');
    if (!$c) {
        return;
    }
    $id = (int)$c['id'];
    switch ($e['type'] ?? '') {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            if (($o['mode'] ?? '') === 'subscription' && ($o['metadata']['essai'] ?? '') === '1') {
                stripe_session_essai($c, $o);
            } elseif (($o['mode'] ?? '') === 'subscription') {
                $paye = in_array($o['payment_status'] ?? '', ['paid', 'no_payment_required'], true);
                maj_paiement($id, ['paiement_fournisseur' => 'stripe', 'paiement_client_ref' => (string)($o['customer'] ?? ''), 'paiement_abonnement_ref' => (string)($o['subscription'] ?? ''), 'paiement_statut' => $paye ? 'actif' : 'en_attente']);
            }
            break;
        case 'checkout.session.async_payment_failed':
            maj_paiement($id, ['paiement_statut' => 'impaye']);
            evenement($c, 'paiement_impaye', 'Prélèvement refusé (Stripe)');
            break;
        case 'invoice.paid':
            if ((int)($o['amount_paid'] ?? 0) === 0) {
                break; // facture à 0 € du début de l'essai : rien n'est encaissé
            }
            enregistrer_paiement($id, 'stripe', 'facture', 'Facture Stripe ' . ($o['number'] ?? ''), ((int)($o['amount_paid'] ?? 0)) / 100, 'paye', (string)$o['id']);
            maj_paiement($id, ['paiement_statut' => 'actif']);
            evenement($c, 'paiement_recu', 'Stripe · ' . montant(((int)($o['amount_paid'] ?? 0)) / 100));
            break;
        case 'invoice.payment_failed':
            enregistrer_paiement($id, 'stripe', 'facture', 'Facture Stripe ' . ($o['number'] ?? ''), ((int)($o['amount_due'] ?? 0)) / 100, 'echec', (string)$o['id']);
            maj_paiement($id, ['paiement_statut' => 'impaye']);
            evenement($c, 'paiement_impaye', 'Stripe · ' . montant(((int)($o['amount_due'] ?? 0)) / 100));
            break;
        case 'customer.subscription.updated':
            if (($o['status'] ?? '') === 'trialing') {
                maj_paiement($id, ['paiement_statut' => 'essai'] + (!empty($o['trial_end']) ? ['paiement_essai_fin' => iso((int)$o['trial_end'])] : []));
                break;
            }
            $s = ['active' => 'actif', 'trialing' => 'essai', 'past_due' => 'impaye', 'unpaid' => 'impaye', 'canceled' => 'annule', 'incomplete' => 'en_attente', 'incomplete_expired' => 'annule', 'paused' => 'impaye'][$o['status'] ?? ''] ?? null;
            if ($s) {
                maj_paiement($id, ['paiement_statut' => $s]);
            }
            break;
        case 'customer.subscription.deleted':
            // Résiliation pendant l'essai : la mise en place programmée est retirée, rien n'est facturé.
            if ($c['paiement_statut'] === 'essai' && (string)$c['paiement_mise_en_place_ref'] !== '') {
                try {
                    stripe('DELETE', '/v1/invoiceitems/' . $c['paiement_mise_en_place_ref']);
                } catch (RuntimeException $x) {
                    // déjà facturée ou supprimée : rien à faire
                }
                maj_paiement($id, ['paiement_mise_en_place_ref' => '']);
            }
            maj_paiement($id, ['paiement_statut' => 'annule']);
            evenement($c, 'paiement_annule', 'Stripe');
            break;
    }
}

function traiter_evenement_paypal(array $e): void
{
    if (empty($e['id']) || evenement_deja_traite('paypal:' . $e['id'])) {
        return;
    }
    $r = $e['resource'] ?? [];
    $c = !empty($r['custom_id']) ? clinique((int)$r['custom_id']) : null;
    $c = $c ?: clinique_par_paiement('paiement_abonnement_ref', (string)($r['billing_agreement_id'] ?? ($r['id'] ?? '')));
    if (!$c) {
        return;
    }
    $id = (int)$c['id'];
    switch ($e['event_type'] ?? '') {
        case 'BILLING.SUBSCRIPTION.ACTIVATED':
        case 'BILLING.SUBSCRIPTION.RE-ACTIVATED':
            if (parametre('paypal_essai_' . ($r['id'] ?? '')) === '1' && !(int)$c['paiement_essai_utilise']) {
                demarrer_essai($c, fin_essai_previsionnelle(), 'PayPal');
            } elseif ($c['paiement_statut'] !== 'essai') {
                maj_paiement($id, ['paiement_statut' => 'actif']);
            }
            break;
        case 'BILLING.SUBSCRIPTION.SUSPENDED':
        case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
            maj_paiement($id, ['paiement_statut' => 'impaye']);
            evenement($c, 'paiement_impaye', 'PayPal');
            break;
        case 'BILLING.SUBSCRIPTION.CANCELLED':
        case 'BILLING.SUBSCRIPTION.EXPIRED':
            maj_paiement($id, ['paiement_statut' => 'annule']);
            evenement($c, 'paiement_annule', 'PayPal');
            break;
        case 'PAYMENT.SALE.COMPLETED':
            $m = (float)($r['amount']['total'] ?? 0);
            enregistrer_paiement($id, 'paypal', 'facture', 'Paiement PayPal', $m, 'paye', (string)($r['id'] ?? ''));
            maj_paiement($id, ['paiement_statut' => 'actif']);
            evenement($c, 'paiement_recu', 'PayPal · ' . montant($m));
            break;
    }
}

// --- SMS au-delà du forfait ----------------------------------------------------------------------

/**
 * Facture les SMS supplémentaires d'un mois écoulé, une seule fois. Stripe : ligne ajoutée à la prochaine
 * facture de l'abonnement. PayPal ou sans paiement en ligne : noté « à facturer » pour une facture manuelle.
 */
function facturer_depassement(array $c, string $mois): ?string
{
    $k = consommation($c, $mois);
    if ($k['depassement'] <= 0 || $k['cout_depassement'] <= 0) {
        return null;
    }
    $ref = 'sms-' . $c['id'] . '-' . $mois;
    if (valeur('SELECT id FROM paiements WHERE ref = ?', [$ref])) {
        return null;
    }
    $libelle = 'Messages au-delà du forfait · ' . mois_libelle($mois) . ' · ' . $k['depassement'] . ' messages';
    if ($c['paiement_fournisseur'] === 'stripe' && in_array($c['paiement_statut'], ['actif', 'impaye'], true) && stripe_actif()) {
        stripe('POST', '/v1/invoiceitems', ['customer' => (string)$c['paiement_client_ref'], 'amount' => centimes($k['cout_depassement']), 'currency' => 'eur',
            'description' => $libelle, 'metadata' => ['clinique_id' => (string)$c['id'], 'mois' => $mois]], $ref);
        enregistrer_paiement((int)$c['id'], 'stripe', 'sms', $libelle, $k['cout_depassement'], 'sur_prochaine_facture', $ref);
        return $libelle . ' : ajouté à la prochaine facture Stripe (' . montant($k['cout_depassement']) . ')';
    }
    enregistrer_paiement((int)$c['id'], $c['paiement_fournisseur'] ?: 'manuel', 'sms', $libelle, $k['cout_depassement'], 'a_facturer', $ref);
    return $libelle . ' : à facturer à la main (' . montant($k['cout_depassement']) . ')';
}

/** Tâche cron : le 1er du mois à partir de 8 h, SMS supplémentaires du mois écoulé. */
function depassements_mensuels(array $c, ?int $t = null): ?string
{
    $t = $t ?? maintenant($c);
    $l = (new DateTimeImmutable('@' . $t))->setTimezone(tz($c));
    if ((int)$l->format('j') !== 1 || (int)$l->format('G') < 8) {
        return null;
    }
    try {
        return facturer_depassement($c, mois_precedent($c, $t));
    } catch (RuntimeException $e) {
        return 'SMS supplémentaires non facturés : ' . $e->getMessage();
    }
}
