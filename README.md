# Plateforme SALW par métier · offre A

Première version de la **« Plateforme SALW par métier »** vendue sur le site Conseiller Sam, pour les **7 métiers du site**, marchés **France et Belgique**. Construite le 2026-09-30 pour le métier santé (pilote), puis étendue le même jour à tous les métiers. Le dossier garde son nom d'origine (`plateforme-sante`).

SALW configure et opère la plateforme pour chaque client : le client n'a rien à administrer. **Une seule installation sert tous les clients, quel que soit leur métier**, et chaque client ne voit que ses données.

## Les 7 métiers

Chaque client est rattaché à un métier (page **Clients (SALW)**, à la création). Le métier règle automatiquement le vocabulaire des écrans, les textes des SMS, les questions posées à la réservation, les modules actifs et ce que l'assistant n'a pas le droit de dire. Tout est défini dans `prive/metiers.php`.

| Métier | Démo | Ses clients s'appellent | Questions à la réservation | Relance de devis | L'assistant refuse |
|---|---|---|---|---|---|
| Cliniques & santé | Centre médical des Tilleuls, Lyon | patients | aucune (pas de donnée médicale) | non | tout avis médical ; urgences vers 15, 112, 3114 |
| Cabinets juridiques & comptables | Cabinet Durand & Associés | clients | domaine, échéance | oui | tout conseil juridique ou fiscal |
| Immobilier | Agence Horizon Immobilier | clients | projet, secteur | non | toute estimation à distance |
| Artisans & services à domicile | Martin Plomberie Chauffage, Nantes | clients | travaux, **adresse** (obligatoire) | oui | prix ferme, réparation dangereuse ; gaz et incendie vers le 112 |
| Coachs, consultants, formateurs | Cap Carrière Coaching | clients | objectif, format | oui | promesse de résultat |
| Écoles & universités privées | Institut privé Les Palmiers, Liège (BE) | familles | **niveau** (obligatoire), rentrée | non | promesse d'admission |
| Garages & auto-écoles | Garage Central Auto | clients | **immatriculation** (obligatoire), véhicule | oui | diagnostic à distance ; freins ou direction : ne pas rouler |

**Pour travailler sur un client d'un autre métier** (par exemple un cabinet d'avocats) : page *Clients (SALW)* → créer le client en choisissant son métier → bouton **Ouvrir** sur sa ligne. Tout l'espace (menu, agenda, textes, démonstration) bascule alors sur ce client.

## Ce que la plateforme fait toute seule

| Automatisation | Déclencheur | Effet |
|---|---|---|
| Appel manqué → SMS | Un appel reste sans réponse | SMS avec le lien de réservation 2 min plus tard, 1 fois par numéro et par 24 h. Si la personne réserve, l'appel compte comme « rattrapé » |
| Confirmation | Rendez-vous pris (en ligne, secrétariat, liste d'attente) | SMS avec un lien pour confirmer, annuler ou déplacer |
| Rappels | 48 h avant, la veille (si non confirmé), 3 h avant (option) | Si plusieurs rappels tombent en même temps, seul le plus proche part |
| Liste d'attente | Annulation | Le créneau est proposé aux 3 premiers patients compatibles ; le premier qui accepte l'obtient ; à l'expiration, il passe aux suivants |
| Honoré | Rendez-vous passé sans absence signalée | Passe en « honoré » (réglable) |
| Avis Google | Quelques heures après la visite | Même message à tous les patients (Google interdit de ne solliciter que les satisfaits), 1 fois par an au plus |
| Absence | Patient marqué absent | Message pour reprendre rendez-vous, sans reproche |
| Réactivation | Patient non revu depuis 12 mois | Seulement s'il a accepté les messages de suivi, avec mention STOP |
| Relance de devis | Devis envoyé sans réponse (artisans, garages, cabinets, coachs) | 1re relance à J+3, 2e à J+7, « sans suite » à J+21. Le client accepte ou refuse en un clic ; un devis accepté après relance compte dans le chiffre d'affaires récupéré |

Les mots « patient », « praticien », « clinique » ci-dessus deviennent « client », « avocat », « technicien », « cabinet »… selon le métier.

**Assistant des clients**, 24 h sur 24, sur la page de réservation : horaires, accès, documents, tarifs, annulation. Il applique les interdits du métier (tableau ci-dessus). En santé, **jamais d'avis médical** : les urgences vont vers le 15, le 112 ou le 3114 (le 112 et le 1813 en Belgique). Dans les autres métiers, les urgences vitales (détresse, incendie, gaz) vont vers le 112. Avec une clé Anthropic et PHP 8.1 ou plus, il répond par Claude Opus 5 (repli serveur activé), limité aux informations du client. Sans clé, il répond à partir de la FAQ.

**Rien de confidentiel dans les SMS** : ni motif détaillé, ni domaine juridique, ni spécialité ; le professionnel n'est pas cité (réglable).

## Ce que le client présente

Une fois configurée, la plateforme donne au client trois choses visibles, à son nom :

1. **Sa page de réservation, à ses couleurs** (page *Page publique*). Logo, couleur principale et, si on le souhaite, une adresse à son nom (ex. `rdv.cabinet-durand.fr`). Si la couleur est trop claire pour du texte blanc, elle est assombrie automatiquement jusqu'au contraste 4,5:1 (norme d'accessibilité).
   La page s'ouvre sur un **bandeau d'accueil** à la couleur du client : une accroche, deux phrases de présentation, un bouton « Choisir mon créneau » et quatre garanties. Les garanties ne sont affichées que si l'automatisation correspondante est active chez ce client (rappel, liste d'attente…). À côté de la réservation : **infos pratiques** (adresse avec lien Google Maps, téléphone cliquable, horaires calculés d'après ceux des professionnels) et, si le client en a saisi, **« Pourquoi nous choisir »** (4 atouts au plus, faits vérifiables uniquement). Accroche et texte sont proposés par métier (`vitrine_metier()` dans `prive/metiers.php`) et modifiables client par client ; les atouts sont vides par défaut, pour ne rien affirmer à la place du client. Dès que le visiteur choisit un type de rendez-vous, le bandeau s'efface et la page va droit à la réservation.
   **Photo de couverture** (facultative) en fond du bandeau : les locaux, l'équipe, l'atelier. Un voile à la couleur du client, plus dense côté texte, garde le texte lisible sur n'importe quelle photo (contraste d'au moins 4,7:1 calculé avec une photo toute blanche, pour toutes les couleurs). Cadrage réglable (haut, centre, bas). Avec l'extension GD (présente sur la plupart des hébergements, dont Hostinger en principe), la photo est redressée, réduite à 1 920 px et réenregistrée en JPEG : quelques dizaines de Ko, métadonnées et position GPS effacées. Sans GD, 2 Mo au plus et photo gardée telle quelle.
2. **Des boutons pour son site**, prêts à copier : un bouton flottant (une ligne de script, sans cookie ni collecte), un bouton HTML pour une page, un lien simple (Google Business Profile, réseaux sociaux, signature) et un **code QR** téléchargeable pour la vitrine, le comptoir ou les devis.
3. **Un rapport mensuel par e-mail** (page *Rapport mensuel*), le 1er du mois à partir de 8 h : chiffre d'affaires récupéré, rendez-vous pris (dont le soir et le week-end), taux d'absence comparé au mois précédent, appels rattrapés, créneaux repris, avis demandés, devis signés après relance, messages envoyés et temps évité (estimation : 2 minutes par message, indiquée dans l'e-mail). Aperçu de n'importe quel mois, envoi manuel, historique. Un mois sans aucune activité n'est pas envoyé.

## Abonnements et tarifs

Formules **au volume** (toutes les automatisations partout) : Essentiel (1 à 2 agendas, 500 SMS), Équipe (3 à 6, 1 500 SMS), Structure (7 à 15, 4 000 SMS), Sur mesure au-delà. Prix de base 149 / 289 / 489 € HT par mois, **ajustés par métier** (coefficient de ×1,0 en santé à ×1,4 pour les cabinets juridiques et l'immobilier). Paramètres dans `prive/tarifs.php`, identiques à ceux de la page Tarifs du site Conseiller Sam : **à modifier ensemble**. Détail et justification : `livrables/Cabinets/2026-09_salw-offres-et-tarifs/grille-tarifaire.md`.

- Page **Abonnement** (responsable en lecture, équipe SALW en modification) : formule, prix, jauges SMS et agendas avec état écrit (dans le forfait, bientôt atteint, dépassé), projection de fin de mois, coût du dépassement, conseil de formule quand une autre reviendrait moins cher ou quand l'équipe a grandi, historique de 6 mois. L'équipe SALW règle la formule (ou « automatique » selon le nombre d'agendas), l'engagement (+20 % sans) et un prix négocié (pilote, remise contre témoignage, sur mesure).
- Les SMS sont comptés en segments, comme l'opérateur les facture. Au-delà du forfait, **les envois ne sont jamais coupés**, le dépassement est affiché à 0,09 € l'unité.
- **Tableau de bord** : alerte si le forfait est dépassé, va l'être à ce rythme, ou si l'équipe dépasse la formule.
- **Clients (SALW)** : formule, prix et SMS du mois de chaque client, et **revenu mensuel récurrent** (démonstrations exclues), avec sa projection sur 12 mois.
- Pas encore de facturation automatique : les montants servent à établir les factures à la main (Stripe plus tard).

## Écrans

- **Espace de travail** (`/app/`) :
  - tableau de bord (chiffre d'affaires récupéré, taux d'absence comparé aux 30 jours précédents, rendez-vous, appels rattrapés, créneaux repris, avis) ;
  - agenda par praticien ;
  - nouveau rendez-vous (avec les questions du métier), clients (consentements, effacement), liste d'attente ;
  - **devis** (métiers concernés) : saisie, suivi et relances ;
  - appels manqués (avec simulation) et messages envoyés, affichés comme sur un téléphone ;
  - **automatisations** (activation, délais, textes avec compteur de SMS) ;
  - fiche de la structure (informations, professionnels et horaires, types de rendez-vous, FAQ, jours fermés) ;
  - **page publique** : logo, couleur, adresse web, codes à intégrer, code QR ;
  - **rapport mensuel** : destinataire, envoi automatique, aperçu, historique ;
  - équipe, clients SALW (avec leur métier) et journal ;
  - **démonstration**.
- **Pages publiques** (`/p/`) :
  - réservation en 4 étapes (plus les questions du métier), avec liste d'attente et assistant ;
  - réponse à un devis (`d.php`, accepter ou refuser en un clic) ;
  - `bouton.js` (bouton flottant pour le site du client) et `logo.php` (logo, rangé dans `prive/`) ;
  - gestion du rendez-vous depuis le lien du SMS (rien ne se fait à l'ouverture du lien, tout passe par un clic) ;
  - proposition de créneau libéré ;
  - lien d'avis.

**Rôles** : *Équipe SALW* (tous les clients), *Responsable* (sa structure, réglages compris), *Secrétariat* (agenda, patients, liste d'attente, appels, messages). Sécurité identique à l'espace Sam : mots de passe hachés, blocage après 5 échecs, double authentification, jeton sur chaque formulaire, journal.

## Démonstration

À l'installation, cochez « Créer les démonstrations des 7 métiers ». La page **Démonstration** propose un onglet par métier et joue une semaine en 8 étapes (9 pour les métiers avec devis), avec le vrai moteur ; seule l'horloge de la démo avance. Exemple en santé :

1. Camille réserve en ligne, à 22 h.
2. 48 h avant, le rappel part.
3. Camille annule par le lien, et 3 patients de la liste d'attente sont prévenus.
4. Lucas accepte le premier ; Inès, trop tard, reste sur la liste.
5. Un appel est manqué ; le SMS part, et Nadia réserve.
6. Après la consultation, Lucas reçoit la demande d'avis.
7. Julien est absent ; il reçoit un message.
8. Martine, non revue depuis 13 mois, est réactivée.
9. (artisans, garages, cabinets, coachs) Un devis reste sans réponse : relance à J+3, le client l'accepte en un clic.

Les mêmes étapes se rejouent avec le vocabulaire, les noms et les motifs de chaque métier.

Tout est fictif : les numéros sont pris dans la tranche 06 39 98 xx xx, réservée par l'ARCEP aux œuvres de fiction.

## Mise en service

1. Hébergement PHP 7.4 ou plus avec SQLite (PHP 8.1 ou plus pour l'assistant IA). Téléverser tout le dossier, `prive/vendor/` compris.
2. Ouvrir `/app/` et créer le compte SALW. Il n'y a pas de clé d'installation : **créez le compte juste après la mise en ligne**.
3. Tâche cron toutes les 5 minutes : `php /chemin/cron.php`. Elle envoie aussi les rapports mensuels.
4. `prive/config.php` (renseigner **`url_site`**, sinon la tâche cron ne peut pas construire les liens des SMS et du rapport, et le rapport n'est pas envoyé) :
   - `mode_envoi` : laisser `simulation` tant que les SMS réels ne sont pas branchés (les démonstrations restent toujours en simulation, même en mode `reel`) ;
   - `twilio` : compte Twilio pour les SMS ;
   - `cle_webhook` : clé du signalement d'appel manqué ;
   - `ia_cle` : clé Anthropic pour l'assistant.
5. **E-mails** : le rapport part de `email_expediteur` (par défaut `plateforme@salw-consulting.com`). Créer cette adresse chez Hostinger et vérifier les enregistrements SPF et DKIM du domaine, sinon le rapport risque d'arriver en indésirables.
6. **Adresse au nom du client** (facultatif) : le client crée un sous-domaine (ex. `rdv`) qui pointe vers l'hébergement ; dans hPanel, l'ajouter comme domaine parqué sur le dossier de la plateforme et activer le SSL ; puis le saisir dans *Page publique*.
7. Standard téléphonique : faire appeler `webhooks/appel-manque.php` à chaque appel manqué (POST, en-tête `X-Cle`, champs `clinique` et `numero`). C'est possible avec Twilio, Ringover, OVH ou Aircall.

## Points de vigilance

- **Hébergement de données de santé (France), métier santé uniquement.** Les rendez-vous d'un patient chez un professionnel de santé peuvent constituer des données de santé. Leur hébergement pour le compte d'un professionnel exige alors un **hébergeur certifié HDS** (OVHcloud, Scaleway, Outscale…). **Hostinger ne l'est pas.** Le pilote de démonstration n'est pas concerné, puisque tout est fictif. Pour une première clinique réelle en France, il faudra un hébergement HDS. L'application tourne sans modification sur tout hébergement PHP. En Belgique, c'est l'article 9 du RGPD qui s'applique, sans certification spécifique : à qualifier.
- **Secret professionnel (avocats, experts-comptables).** Le fait même d'être client d'un avocat est couvert par le secret. Il faut un contrat de sous-traitance strict, un hébergement en Europe, et ne jamais mettre l'objet du dossier dans un SMS (c'est le cas par défaut). À faire valider par le premier cabinet client.
- **Mineurs (écoles).** Les candidats peuvent être mineurs : la réservation doit être faite par un parent (d'où « familles »), et la réactivation est à désactiver. À régler client par client.
- **Contrat avec chaque client** : SALW agit comme sous-traitant (article 28 du RGPD) ; le contrat de sous-traitance est à rédiger, avec une annexe par métier.
- **SMS commerciaux** : la réactivation n'est envoyée qu'avec consentement, avec la mention STOP. Les rappels et confirmations sont des messages de service.
- **Concurrence** : en santé, la réservation et les rappels sont déjà couverts par Doctolib chez beaucoup de praticiens (et, dans d'autres métiers, par Planity, Calendly ou les logiciels de garage). La valeur propre de SALW tient à tout ce que Doctolib ne fait pas ou peu : appels manqués rattrapés, liste d'attente automatique, avis Google, réactivation, assistant, et service opéré « clé en main ».
- **Non testé** :
  - SMS réels par Twilio et e-mails réels ;
  - réponses réelles de Claude (aucune clé) ;
  - rendu réel du rapport mensuel dans Outlook et Gmail (construit en tableaux et styles en ligne, comme il se doit, mais non vérifié dans ces messageries) ;
  - adresse web au nom d'un client sur un vrai domaine (testée en local en simulant le nom de domaine).

## Contrôles effectués (30/09/2026)

Abonnements et tarifs :

- Grille calculée identique dans la plateforme et sur le site Sam (7 métiers, 3 formules).
- Formule automatique selon le nombre d'agendas, formule imposée, sans engagement (+20 %), prix négocié à 204,50 € affiché au centime.
- Dépassement simulé (1 738 SMS pour un forfait de 500) : jauge « Dépassé », 111,42 € de dépassement, alerte sur le tableau de bord, conseil de passer à Équipe. Défauts trouvés et corrigés : prix négocié arrondi à l'euro, tournure « selon le nombre de avocats ».
- PHP 7.4 : page Abonnement pour les 7 démos, liste des clients, tableau de bord.

Photo de couverture :

- Photo trop petite (800 px) refusée ; photo de téléphone tournée et géolocalisée : redressée, réduite de 1 600 × 2 400 à 1 920 × 1 280 px (60 Ko), GPS effacé ; ancienne photo supprimée à chaque remplacement ; cadrage et retrait vérifiés.
- Lisibilité : pire cas calculé (photo blanche, couleurs de démo et couleurs extrêmes) à 4,74:1, au-dessus du seuil de 4,5:1.
- Sans GD (PHP 7.4 de test) : fichier de plus de 2 Mo refusé avec un message clair, photo acceptée sinon.
- Aucun débordement à 390, 768 et 1 440 px.

Bandeau d'accueil et infos pratiques :

- Les 7 métiers : accroche, 4 garanties, atouts des démos, horaires (« Lundi au vendredi 9 h – 18 h »). Bandeau absent dès qu'un type est choisi.
- Enregistrement depuis l'espace : lignes vides ignorées, 4 atouts au plus, retour aux textes du métier si on vide les champs.
- Aucun débordement à 390 px et 1 440 px ; PHP 7.4 vérifié.

Image du client, intégration, rapport mensuel :

- Couleur : une couleur trop claire (#FFD700) est assombrie à #877300, contraste 4,68:1 ; les 7 couleurs des démos passent toutes au-dessus de 4,5:1.
- Logo : un faux PNG (code PHP) est refusé ; un vrai PNG est accepté, l'ancien fichier supprimé, le logo servi par `logo.php` ; logo inconnu : 404.
- Adresse web : saisie avec `https://` et majuscules nettoyée, adresse invalide refusée ; la racine du sous-domaine mène à la page de réservation du bon client, celle de la plateforme à l'espace de travail.
- Bouton flottant testé sur une fausse page de site client ; codes à copier conformes.
- Rapport : aperçu, envoi manuel (simulation), historique, adresse invalide refusée. Planification vérifiée à heure fixée : rien à 7 h 30 le 1er, envoi à 8 h 05, pas de doublon à 14 h ni le 2, mois suivant envoyé le 1er novembre, rien si désactivé.
- Aucun débordement à 390 px et 1 440 px (page publique, rapport, page de réservation).
- PHP 7.4 : installation, 7 scénarios, nouvelles pages et rapport pour chaque métier. Migration d'une base existante : tout est conservé (1 625 rendez-vous).

Version multi-métier :

- Installation neuve avec les 7 démonstrations ; les 7 scénarios rejoués jusqu'au bout sans erreur (8 ou 9 étapes) en PHP 8.3, et deux d'entre eux (juridique, garage) en PHP 7.4.
- Pages de l'espace testées pour 4 métiers ; vocabulaire vérifié (menu « Clients », « Cabinet », étapes de réservation « Avocat », « Technicien », « Poste »…). Textes SMS par défaut rendus neutres ; la santé garde ses formulations.
- Réservation publique : question obligatoire manquante refusée (adresse chez l'artisan), réponses enregistrées avec le rendez-vous.
- Devis : lien ouvert sans effet, puis accepté en un clic.
- Assistant : conseil juridique refusé, estimation immobilière et diagnostic garage renvoyés vers un rendez-vous, odeur de gaz orientée vers le 112 (défaut trouvé et corrigé : « ça sent le gaz » n'était pas reconnu).
- Migration d'une base de la version santé : le client existant passe en métier santé, la table des devis est créée, rien n'est perdu.

Version santé (pilote) :

- PHP 8.3 et 7.4 : syntaxe, installation, les 13 pages de l'espace de travail, scénario complet.
- Scénario en 8 étapes rejoué de zéro : 26 messages, **aucun doublon** (deux défauts trouvés et corrigés : rappels 48 h et 24 h simultanés, double demande d'avis).
- Parcours patient :
  - réservation en ligne ; un deuxième patient est refusé sur le même créneau ;
  - numéro invalide refusé ;
  - lien de gestion : ouverture sans effet, puis confirmation et déplacement, l'ancien rendez-vous étant annulé ;
  - lien d'avis (redirection et comptage), offre déjà attribuée.
- Assistant FAQ :
  - réponses justes sur le stationnement, les tarifs, l'annulation et la visio ;
  - refus clair sur une question de médicament ;
  - urgence orientée vers le 15, le 112 et le 3114.
- Point d'entrée « appel manqué » : mauvaise clé refusée, bonne clé acceptée. Le cron est refusé par le web.
- Aucun débordement horizontal à 1440 px et à 390 px (espace de travail et page de réservation).

## Déploiement (GitHub puis Hostinger)

Dépôt Git propre à cette application (branche `main`), à pousser dans un dépôt GitHub **privé**. Ne jamais pousser le workspace Jarvis entier.

- **Versionné** : le code, `prive/vendor/` (l'hébergement ne lance pas Composer) et le modèle `prive/config.exemple.php`.
- **Jamais versionné** (`.gitignore`) : `prive/config.php` (réglages et clés) et `prive/donnees/` (données). Un déploiement ne les touche donc pas.
- **Premier envoi** : `git remote add origin https://github.com/<compte>/salw-plateforme.git` puis `git push -u origin main` (connexion GitHub dans le navigateur ; jamais de jeton dans une commande).
- **Hostinger** : hPanel → Avancé → Git, dépôt en SSH avec la clé de déploiement ajoutée dans GitHub (Settings → Deploy keys, lecture seule), webhook de déploiement automatique ajouté dans GitHub (Settings → Webhooks). Puis, une seule fois sur le serveur : copier `prive/config.exemple.php` en `prive/config.php` et le compléter, vérifier que `prive/donnees/` est accessible en écriture, programmer la tâche cron, activer le SSL, créer le compte admin aussitôt.
