<?php
/**
 * Plateforme SALW : catalogue des métiers.
 *
 * Chaque client (table « cliniques », historique du pilote santé) a un métier, qui fixe :
 *  - le vocabulaire affiché (patients / clients / familles, praticiens / avocats / techniciens…) ;
 *  - les automatisations actives par défaut et leurs textes ;
 *  - les questions posées à la réservation (pré-qualification) ;
 *  - les interdits de l'assistant (pas d'avis médical, pas de conseil juridique…) ;
 *  - la démonstration.
 * Ajouter un métier = ajouter une entrée ici ; le moteur est commun.
 */

declare(strict_types=1);

function metiers(): array
{
    static $m = null;
    if ($m !== null) {
        return $m;
    }
    $semaine = ['1' => [['09:00', '12:00'], ['14:00', '18:00']], '2' => [['09:00', '12:00'], ['14:00', '18:00']], '3' => [['09:00', '12:00'], ['14:00', '18:00']], '4' => [['09:00', '12:00'], ['14:00', '18:00']], '5' => [['09:00', '12:00'], ['14:00', '17:00']]];
    $m = [
        'sante' => [
            'libelle' => 'Cliniques & santé',
            'mots' => ['structure' => 'clinique', 'Structure' => 'Clinique', 'client' => 'patient', 'clients' => 'patients', 'Clients' => 'Patients', 'pro' => 'praticien', 'pros' => 'praticiens', 'Pro' => 'Praticien', 'rdv' => 'consultation', 'motif' => 'Motif de la visite'],
            'titre' => 'Dr',
            'interdit' => "aucun avis médical, aucun diagnostic, aucune interprétation de symptômes, de résultats ou de traitements, aucune posologie : propose de prendre rendez-vous",
            'refus' => ['regex' => '/m[ée]dicament|traitement|dose|posologie|sympt[oô]me|fi[èe]vre|douleur|mal (au|à la|aux)|ibuprof|doliprane|parac[ée]tamol|antibio|enceinte|allergi|tension|diab[èe]t|vaccin|r[ée]sultats? d|analyse|infection|bless/iu',
                'texte' => "Je ne peux pas donner d'avis médical. Le mieux est d'en parler avec un médecin : prenez rendez-vous sur cette page, ou appelez-nous."],
            'urgence' => true,
            'modules' => [],
            'textes' => [],
            'questions' => [],
            'demo' => ['nom' => '(démo) Centre médical des Tilleuls', 'adresse' => '12 avenue des Tilleuls', 'ville' => '69003 Lyon', 'pays' => 'FR', 'valeur' => 30,
                'pros' => [['Claire Morel', 'Dr'], ['Thomas Petit', 'Dr'], ['Sophie Laurent', 'Dr']],
                'types' => [['Consultation', 20, 1], ['Consultation longue (bilan, première visite)', 40, 1], ['Acte technique (sur appel au secrétariat)', 30, 0]],
                'faq' => "Horaires d'ouverture\nLe centre est ouvert du lundi au vendredi, de 8h30 à 18h30. Le secrétariat répond au téléphone de 9h à 12h et de 14h à 17h.\n\nParking, accès, transports\nUn parking gratuit de 20 places est devant le centre. Tram T1, arrêt « Les Tilleuls », à 200 mètres. Le centre est accessible aux personnes à mobilité réduite.\n\nDocuments à apporter, carte vitale, mutuelle\nPensez à votre carte Vitale, votre attestation de mutuelle et, le cas échéant, vos derniers comptes rendus ou ordonnances.\n\nTarifs, secteur, tiers payant\nLes médecins sont conventionnés secteur 1. Le tiers payant est pratiqué sur la part obligatoire.\n\nAnnuler ou déplacer un rendez-vous\nUtilisez le lien reçu par SMS, ou appelez le secrétariat. Merci de prévenir au moins 24 h à l'avance : le créneau sera proposé à un autre patient.\n\nTéléconsultation, visio\nLes médecins ne proposent pas de téléconsultation pour le moment."],
        ],
        'juridique' => [
            'libelle' => 'Cabinets juridiques & comptables',
            'mots' => ['structure' => 'cabinet', 'Structure' => 'Cabinet', 'client' => 'client', 'clients' => 'clients', 'Clients' => 'Clients', 'pro' => 'avocat', 'pros' => 'avocats', 'Pro' => 'Avocat', 'rdv' => 'rendez-vous', 'motif' => 'Objet du rendez-vous'],
            'titre' => 'Me',
            'interdit' => "aucun conseil juridique, aucune analyse de dossier, aucune estimation de chances, de délais de procédure ou d'honoraires non indiqués ci-dessous : propose un rendez-vous avec un avocat ; ne demande aucun détail de l'affaire (secret professionnel)",
            'refus' => ['regex' => "/ai-je le droit|est-ce l[ée]gal|puis-je (attaquer|porter plainte|contester)|proc[èe]s|licenci|divorce|garde (des|de mes) enfants|prud.?hom|prescription|plainte|condamn|amende|contrat (est|non)|succession|h[ée]ritage|dommages/iu",
                'texte' => "Je ne peux pas donner de conseil juridique : chaque situation doit être examinée par un avocat. Prenez rendez-vous sur cette page, le premier échange permettra de faire le point."],
            'urgence' => false,
            'modules' => ['appel_manque' => ['delai_min' => 1], 'liste_attente' => ['actif' => true], 'avis' => ['actif' => false], 'relance_devis' => ['actif' => true]],
            'textes' => [
                'appel_manque' => "{structure} : en RDV, nous n'avons pu répondre. Réservez en ligne : {lien}",
                'relance_devis' => "Votre proposition : {devis}. Une question ? Acceptez en 1 clic : {lien}",
                'reactivation' => "{structure} reste à votre disposition : {lien} Répondez STOP pour arrêter",
            ],
            'questions' => [
                ['cle' => 'domaine', 'libelle' => 'Domaine concerné', 'type' => 'choix', 'options' => ['Famille', 'Travail', 'Immobilier', 'Entreprise, commercial', 'Fiscal, comptable', 'Pénal', 'Autre']],
                ['cle' => 'echeance', 'libelle' => 'Avez-vous une échéance (audience, délai) ?', 'type' => 'choix', 'options' => ['Non', 'Oui, dans le mois', 'Oui, dans la semaine']],
            ],
            'demo' => ['nom' => '(démo) Cabinet Durand & Associés', 'adresse' => '8 rue de la Loi', 'ville' => '1000 Bruxelles', 'pays' => 'BE', 'valeur' => 180,
                'pros' => [['Claire Morel', 'Me'], ['Thomas Petit', 'Me'], ['Sophie Laurent', 'Me']],
                'types' => [['Premier rendez-vous (30 min)', 30, 1], ['Rendez-vous de suivi', 45, 1], ['Consultation approfondie (sur appel au cabinet)', 60, 0]],
                'faq' => "Horaires du cabinet\nLe cabinet reçoit du lundi au vendredi, de 9h à 18h. Le secrétariat répond de 9h à 12h30 et de 14h à 17h30.\n\nTarif du premier rendez-vous, honoraires\nLe premier rendez-vous de 30 minutes est facturé 90 € HTVA. Les honoraires suivants font l'objet d'une proposition écrite.\n\nDocuments à apporter\nApportez une pièce d'identité et les documents utiles à votre situation (contrats, courriers, décisions). Ne les envoyez pas par SMS.\n\nAccès, parking\nMétro Arts-Loi à 3 minutes. Parking public rue de la Loi.\n\nVisioconférence\nLes rendez-vous de suivi peuvent se tenir en visio, sur demande au secrétariat."],
        ],
        'immobilier' => [
            'libelle' => 'Immobilier',
            'mots' => ['structure' => 'agence', 'Structure' => 'Agence', 'client' => 'client', 'clients' => 'clients', 'Clients' => 'Clients', 'pro' => 'conseiller', 'pros' => 'conseillers', 'Pro' => 'Conseiller', 'rdv' => 'rendez-vous', 'motif' => 'Votre projet'],
            'titre' => '',
            'interdit' => "aucune estimation de prix d'un bien, aucune promesse de délai de vente, aucun avis juridique ou fiscal : propose un rendez-vous avec un conseiller",
            'refus' => ['regex' => "/combien vaut|estim(er|ation) (de )?(mon|ma|notre)|prix de (mon|ma)|plus-value|fiscal|imp[oô]t/iu",
                'texte' => "Une estimation sérieuse demande de voir le bien : un conseiller vous la donne gratuitement lors d'un rendez-vous. Réservez un créneau sur cette page."],
            'urgence' => false,
            'modules' => ['appel_manque' => ['delai_min' => 1], 'rappel_h3' => ['actif' => true], 'reactivation' => ['mois' => 6]],
            'textes' => [
                'appel_manque' => "{structure} : en visite, appel manqué. Réservez en ligne : {lien}",
                'reactivation' => "Où en est votre projet ? {structure} : {lien} Répondez STOP pour arrêter",
            ],
            'questions' => [
                ['cle' => 'projet', 'libelle' => 'Votre projet', 'type' => 'choix', 'options' => ['Acheter', 'Vendre', 'Louer', 'Mettre en location', 'Faire estimer un bien']],
                ['cle' => 'secteur', 'libelle' => 'Commune ou quartier recherché', 'type' => 'texte'],
            ],
            'demo' => ['nom' => '(démo) Agence Horizon Immobilier', 'adresse' => '21 cours Mirabeau', 'ville' => '13100 Aix-en-Provence', 'pays' => 'FR', 'valeur' => 400,
                'pros' => [['Claire Morel', ''], ['Thomas Petit', ''], ['Sophie Laurent', '']],
                'types' => [['Premier rendez-vous projet', 30, 1], ['Estimation à domicile', 60, 1], ['Visite d\'un bien (sur appel)', 45, 0]],
                'faq' => "Horaires de l'agence\nL'agence est ouverte du lundi au samedi, de 9h à 19h.\n\nEstimation\nL'estimation de votre bien est gratuite et sans engagement, sur rendez-vous à domicile.\n\nHonoraires\nNos honoraires sont affichés en agence et sur chaque annonce.\n\nDocuments pour louer\nPièce d'identité, trois derniers bulletins de salaire, dernier avis d'imposition et justificatif de domicile."],
        ],
        'artisan' => [
            'libelle' => 'Artisans & services à domicile',
            'mots' => ['structure' => 'entreprise', 'Structure' => 'Entreprise', 'client' => 'client', 'clients' => 'clients', 'Clients' => 'Clients', 'pro' => 'technicien', 'pros' => 'techniciens', 'Pro' => 'Technicien', 'rdv' => 'intervention', 'motif' => "Type d'intervention"],
            'titre' => '',
            'interdit' => "aucun prix ferme ni délai d'intervention non indiqué ci-dessous, aucun conseil technique de réparation à faire soi-même en cas de danger (gaz, électricité) : dans ce cas, couper l'arrivée et appeler les urgences",
            'refus' => ['regex' => "/combien (co[uû]te|pour)|prix (de|d'un|pour)|tarif (de|pour)/iu",
                'texte' => "Le prix dépend de l'intervention : nous établissons un devis gratuit après une visite ou un appel. Réservez un créneau sur cette page."],
            'urgence' => false,
            'modules' => ['appel_manque' => ['delai_min' => 1], 'liste_attente' => ['actif' => false], 'relance_devis' => ['actif' => true], 'reactivation' => ['mois' => 12]],
            'textes' => [
                'appel_manque' => "{structure} : sur un chantier, appel manqué. Réservez en ligne : {lien}",
                'confirmation' => "Intervention {date} {heure} confirmée, {structure}. Gérer : {lien}",
                'reactivation' => "Entretien annuel à prévoir ? {structure} : {lien} Répondez STOP pour arrêter",
            ],
            'questions' => [
                ['cle' => 'travaux', 'libelle' => 'Nature des travaux', 'type' => 'choix', 'options' => ['Dépannage', 'Entretien', 'Devis pour travaux', 'Installation neuve']],
                ['cle' => 'adresse', 'libelle' => "Adresse de l'intervention", 'type' => 'texte', 'requis' => true],
            ],
            'demo' => ['nom' => '(démo) Martin Plomberie Chauffage', 'adresse' => '5 rue des Artisans', 'ville' => '44000 Nantes', 'pays' => 'FR', 'valeur' => 220,
                'pros' => [['Claire Morel', ''], ['Thomas Petit', ''], ['Sophie Laurent', '']],
                'types' => [['Dépannage (créneau de 2 h)', 120, 1], ['Visite pour devis', 60, 1], ['Entretien chaudière', 60, 1]],
                'faq' => "Zone d'intervention\nNous intervenons à Nantes et dans un rayon de 25 km.\n\nDevis\nLe devis est gratuit et envoyé sous 48 h après la visite.\n\nUrgence gaz ou fuite d'eau
Odeur de gaz : sortez sans actionner d'interrupteur et appelez le 112 depuis l'extérieur. Fuite d'eau importante : coupez l'arrivée d'eau au compteur, puis appelez-nous : nous intervenons en priorité.\n\nPaiement\nCarte bancaire, virement ou chèque. Acompte de 30 % pour les travaux."],
        ],
        'coach' => [
            'libelle' => 'Coachs, consultants, formateurs',
            'mots' => ['structure' => 'activité', 'Structure' => 'Activité', 'client' => 'client', 'clients' => 'clients', 'Clients' => 'Clients', 'pro' => 'coach', 'pros' => 'coachs', 'Pro' => 'Coach', 'rdv' => 'séance', 'motif' => 'Type de séance'],
            'titre' => '',
            'interdit' => "aucun avis médical ou psychologique, aucune promesse de résultat : propose une séance découverte",
            'refus' => ['regex' => "/d[ée]pression|anxi[ée]t[ée]|traitement|m[ée]dicament|burn.?out|suicid/iu",
                'texte' => "Je ne peux pas vous conseiller sur ce point. Si vous traversez une période difficile, parlez-en à un médecin ; pour le reste, une séance découverte permet de faire le point."],
            'urgence' => false,
            'modules' => ['relance_devis' => ['actif' => true], 'reactivation' => ['mois' => 4]],
            'textes' => [
                'relance_devis' => "Votre proposition : {devis}. Une question ? Acceptez en 1 clic : {lien}",
                'reactivation' => "Envie de faire le point ? {structure} : {lien} Répondez STOP pour arrêter",
            ],
            'questions' => [
                ['cle' => 'objectif', 'libelle' => 'Votre objectif principal', 'type' => 'texte'],
                ['cle' => 'format', 'libelle' => 'Format souhaité', 'type' => 'choix', 'options' => ['Visio', 'En présentiel']],
            ],
            'demo' => ['nom' => '(démo) Cap Carrière Coaching', 'adresse' => '3 place Bellecour', 'ville' => '69002 Lyon', 'pays' => 'FR', 'valeur' => 120,
                'pros' => [['Claire Morel', ''], ['Thomas Petit', ''], ['Sophie Laurent', '']],
                'types' => [['Séance découverte (offerte)', 30, 1], ['Séance de coaching', 60, 1], ['Atelier de groupe (sur inscription)', 120, 0]],
                'faq' => "Déroulement\nLa séance découverte de 30 minutes est offerte et sans engagement. Elle se tient en visio ou au cabinet.\n\nTarifs\nLa séance de coaching est à 120 €. Forfaits de 6 et 10 séances disponibles.\n\nAnnulation\nMerci de prévenir 24 h à l'avance : au-delà, la séance est due."],
        ],
        'ecole' => [
            'libelle' => 'Écoles & universités privées',
            'mots' => ['structure' => 'établissement', 'Structure' => 'Établissement', 'client' => 'famille', 'clients' => 'familles', 'Clients' => 'Familles', 'pro' => 'conseiller', 'pros' => 'conseillers', 'Pro' => 'Conseiller', 'rdv' => 'rendez-vous d\'admission', 'motif' => 'Objet de la visite'],
            'titre' => '',
            'interdit' => "aucune promesse d'admission, aucun tarif ou aide non indiqués ci-dessous : propose un rendez-vous avec le service des admissions",
            'refus' => ['regex' => "/admis|accept[ée]|bourse|r[ée]duction/iu",
                'texte' => "La décision d'admission et les aides se voient avec le service des admissions : réservez un rendez-vous sur cette page."],
            'urgence' => false,
            'modules' => ['avis' => ['actif' => false], 'absence' => ['actif' => true], 'reactivation' => ['mois' => 3, 'intervalle_mois' => 3]],
            'textes' => [
                'reactivation' => "Inscriptions ouvertes chez {structure} : {lien} Répondez STOP pour arrêter",
            ],
            'questions' => [
                ['cle' => 'niveau', 'libelle' => 'Niveau ou formation visée', 'type' => 'texte', 'requis' => true],
                ['cle' => 'rentree', 'libelle' => 'Rentrée souhaitée', 'type' => 'choix', 'options' => ['Cette année', "L'année prochaine", 'Je me renseigne']],
            ],
            'demo' => ['nom' => '(démo) Institut privé Les Palmiers', 'adresse' => "14 boulevard d'Avroy", 'ville' => '4000 Liège', 'pays' => 'BE', 'valeur' => 350,
                'pros' => [['Claire Morel', ''], ['Thomas Petit', ''], ['Sophie Laurent', '']],
                'types' => [['Rendez-vous d\'admission', 30, 1], ['Visite de l\'établissement', 45, 1], ['Test de niveau (sur appel)', 60, 0]],
                'faq' => "Pièces du dossier\nBulletins des deux dernières années, acte de naissance, deux photos d'identité.\n\nFrais de scolarité\nLes frais sont communiqués lors du rendez-vous d'admission, avec les modalités de paiement échelonné.\n\nHoraires des cours\nDu lundi au vendredi, de 7h30 à 14h30."],
        ],
        'garage' => [
            'libelle' => 'Garages & auto-écoles',
            'mots' => ['structure' => 'garage', 'Structure' => 'Garage', 'client' => 'client', 'clients' => 'clients', 'Clients' => 'Clients', 'pro' => 'poste', 'pros' => 'postes', 'Pro' => 'Poste', 'rdv' => 'rendez-vous atelier', 'motif' => 'Prestation'],
            'titre' => '',
            'interdit' => "aucun diagnostic de panne, aucun prix ferme non indiqué ci-dessous : propose un rendez-vous atelier ; en cas de problème de freins ou de direction, conseille de ne pas rouler",
            'refus' => ['regex' => "/combien (co[uû]te|pour)|prix (de|d'un|pour)|c'est grave|voyant|bruit/iu",
                'texte' => "Un diagnostic se fait sur le véhicule : réservez un créneau atelier sur cette page. Si le problème touche les freins ou la direction, ne roulez pas."],
            'urgence' => false,
            'modules' => ['relance_devis' => ['actif' => true], 'reactivation' => ['mois' => 11, 'intervalle_mois' => 11]],
            'textes' => [
                'reactivation' => "Révision annuelle à prévoir ? {structure} : {lien} Répondez STOP pour arrêter",
            ],
            'questions' => [
                ['cle' => 'immatriculation', 'libelle' => 'Immatriculation', 'type' => 'texte', 'requis' => true],
                ['cle' => 'vehicule', 'libelle' => 'Marque et modèle', 'type' => 'texte'],
            ],
            'demo' => ['nom' => '(démo) Garage Central Auto', 'adresse' => '40 route de Namur', 'ville' => '5000 Namur', 'pays' => 'BE', 'valeur' => 250,
                'pros' => [['Claire Morel', ''], ['Thomas Petit', ''], ['Sophie Laurent', '']],
                'types' => [['Révision', 90, 1], ['Diagnostic', 45, 1], ['Pneus', 45, 1], ['Carrosserie (sur appel)', 120, 0]],
                'faq' => "Horaires de l'atelier\nDu lundi au vendredi de 8h à 18h, le samedi de 8h à 12h.\n\nVéhicule de courtoisie\nUn véhicule de courtoisie est disponible sur réservation, pour les interventions de plus d'une journée.\n\nDevis\nTout devis est gratuit. Aucune intervention n'est faite sans votre accord.\n\nPaiement\nCarte bancaire et virement."],
        ],
    ];
    return $m;
}

function metier(array $c): array
{
    $m = metiers();
    return $m[$c['metier'] ?? 'sante'] ?? $m['sante'];
}

/** Mot du vocabulaire du métier : mot($c, 'clients') → « patients », « clients », « familles »… */
function mot(array $c, string $cle): string
{
    return metier($c)['mots'][$cle] ?? $cle;
}

/** « Dr Claire Morel », « Me Claire Morel », « Claire Morel » */
function nom_pro(array $p): string
{
    return trim(($p['titre'] ?? '') . ' ' . ($p['nom'] ?? ''));
}

/**
 * Présentation proposée pour la page publique, par métier : accroche et texte (modifiables client par client),
 * et atouts de la structure de démonstration. {structure} et {ville} sont remplacés.
 * Rien ici ne promet ce que la plateforme ou le client ne garantit pas.
 */
function vitrine_metier(string $m): array
{
    $v = [
        'sante' => ["Prenez rendez-vous en quelques secondes",
            "{structure} vous reçoit à {ville}. Choisissez le motif, le praticien et l'horaire qui vous conviennent : la confirmation arrive aussitôt par SMS.",
            ["Médecins généralistes et spécialistes", "Téléconsultation possible", "Accès pour les personnes à mobilité réduite"]],
        'juridique' => ["Un premier échange avec un avocat, au moment qui vous arrange",
            "{structure} vous accompagne à {ville}. Réservez votre rendez-vous en ligne : vous indiquez seulement le domaine concerné, sans rien détailler de votre dossier.",
            ["Droit du travail, de la famille et des affaires", "Honoraires annoncés avant tout engagement", "Rendez-vous au cabinet ou en visio"]],
        'immobilier' => ["Parlons de votre projet immobilier",
            "Acheter, vendre, louer ou faire estimer un bien à {ville} : réservez un rendez-vous avec un conseiller de {structure}, à l'heure qui vous convient.",
            ["Estimation offerte", "Une agence au cœur de la ville", "Un conseiller unique, du premier rendez-vous à la signature"]],
        'artisan' => ["Une intervention planifiée, sans attendre au téléphone",
            "Choisissez le type d'intervention et le créneau : {structure} vous confirme le passage par SMS et vous le rappelle avant.",
            ["Devis gratuit sous 48 h", "Intervention à {ville} et alentours", "Artisans qualifiés et assurés"]],
        'coach' => ["Faites le premier pas",
            "Réservez votre séance avec {structure} en ligne, au moment qui vous convient. La confirmation arrive aussitôt par SMS.",
            ["Séance découverte offerte", "En présentiel à {ville} ou en visio", "Un accompagnement sur mesure"]],
        'ecole' => ["Venez découvrir {structure}",
            "Rencontrez le service des admissions pour parler de votre projet d'études : réservez votre rendez-vous en ligne, en quelques clics.",
            ["Petits effectifs", "Accompagnement personnalisé de chaque étudiant", "Campus au centre de {ville}"]],
        'garage' => ["Réservez votre passage à l'atelier",
            "Entretien, réparation ou pneus : choisissez la prestation et le créneau. {structure} vous confirme le rendez-vous par SMS et vous le rappelle avant.",
            ["Devis gratuit, aucune intervention sans votre accord", "Véhicule de courtoisie sur réservation", "Toutes marques"]],
    ];
    [$accroche, $presentation, $atouts] = $v[$m] ?? $v['sante'];
    return ['accroche' => $accroche, 'presentation' => $presentation, 'atouts_demo' => $atouts];
}
