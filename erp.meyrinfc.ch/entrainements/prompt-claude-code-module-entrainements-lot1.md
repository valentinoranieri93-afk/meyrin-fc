# PROMPT CLAUDE CODE — ERP Meyrin FC — Module « Entraînements » (Lot 1)

## 0. Rôle attendu de toi

Tu es développeur senior sur l'ERP interne du FC Meyrin. Tu construis un nouveau module autonome, `entrainements`, cohérent avec les modules existants du repo.

**Contraintes de méthode, non négociables :**
- Travaille **étape par étape**, en annonçant chaque étape et en attendant validation avant la suivante.
- Ne réécris jamais un fichier existant du repo sans me demander.
- À chaque livraison, indique **exactement quels fichiers déployer par FTP** (chemin complet), jamais « re-uploadez le paquet ».
- La base SQLite ne doit jamais être écrasée par un déploiement. Les migrations sont idempotentes.
- Valide toute syntaxe JS avec `node --check` avant packaging.

---

## 1. Contexte

Le FC Meyrin est un club suisse (Genève) d'environ 1 200 membres, 40 équipes, 80 coachs. Les coachs sont majoritairement bénévoles, avec un emploi à côté. Ils préparent leurs séances en 20 à 45 minutes, souvent le soir même, **depuis leur téléphone**.

Le module doit leur permettre de générer une séance d'entraînement de qualité, conforme au cadre de formation du club, en moins de 2 minutes, et de la consulter sur le terrain.

**Principe directeur absolu : assemblage sous contraintes, pas génération libre.**
Le système ne « fabrique » pas des exercices à la volée. Il puise dans une bibliothèque d'exercices structurés, validés par la direction technique, et les assemble selon des règles. L'IA sert uniquement à adapter et rédiger, jamais à inventer un exercice qui n'a pas été validé.

---

## 2. Stack et environnement

- **Hébergement :** Infomaniak, hébergement mutualisé, déploiement par FTP.
- **Backend :** PHP 8.3, PDO SQLite.
- **Frontend :** JavaScript vanilla, single-page app. Pas de framework, pas de build Node en production.
- **Routeur :** même convention que les modules existants — `api.php?action=xxx`.
- **Auth :** réutiliser le système de session existant (sessions 14 jours), ne pas en créer un nouveau.
- **Base :** fichier dédié `data/entrainements.sqlite`.

**Identité visuelle du club :**
- Jaune `#FFD000`, noir `#15140F`
- Police display : Sora — Police UI : Inter
- Le rendu doit être de qualité SaaS professionnelle, mobile-first, directement déployable. Pas de wireframe, pas de placeholder.

---

## 3. Structure du club à modéliser

Ne modélise **pas** une liste plate de catégories. Utilise trois axes indépendants.

### Axe 1 — Tranche d'âge
`G`, `F`, `E`, `D`, `FE-12`, `FE-13`, `FE-14`, `M15`, `C`, `B`, `A`, `ACTIFS`, `SENIORS`

### Axe 2 — Filière
`loisir` (foot libre) · `competition` · `elite` (Footeco et M15)

### Axe 3 — Genre
`mixte` · `masculin` · `feminin`

Une **équipe** est une instance concrète (ex. « Juniors E2 compétition », « FF-14 », « 2e ligue »), rattachée à une tranche d'âge, une filière et un genre.

### Périmètres de validation et responsables

Crée une table `perimetres` avec ces 6 entrées, chacune assignable à un utilisateur. **Les périmètres doivent être modifiables depuis l'interface d'admin** — la répartition ci-dessous est un point de départ.

| Code | Libellé | Tranches d'âge couvertes |
|---|---|---|
| `ecole_foot` | Responsable école de foot | G, F, E (filière compétition) |
| `foot_libre_filles` | Responsable foot libre + école de foot filles | G, F, E (filière loisir) + école de foot féminine |
| `filles` | Responsable football féminin | FF-11, FF-14, FF-17, actives (genre = feminin) |
| `preformation` | Responsable préformation | E, D |
| `elite` | Responsable élite | FE-12, FE-13, FE-14, M15 |
| `actifs` | Responsable actifs | C, B, A, ACTIFS, SENIORS |

**Note :** la tranche E apparaît dans `ecole_foot` et `preformation`. C'est volontaire et à arbitrer par le club — rends ce chevauchement supportable (un exercice peut être rattaché à plusieurs périmètres, la validation par l'un suffit à le publier).

**Cas particulier SENIORS (+30/+40/+50) :** mode « libre ». Pas de référentiel d'accents imposé, pas de blocage de publication. Bibliothèque consultable, générateur simplifié.

**Cas particulier ACTIFS 1re et 2e ligue :** ces coachs sont souvent diplômés B UEFA. Le référentiel d'accents ne doit pas leur être imposé : mode « composition libre » activable par équipe.

---

## 4. Rôles et permissions

| Rôle | Droits |
|---|---|
| `admin` | Tout, y compris administration des périmètres et des équipes |
| `directeur_technique` | Lecture sur tous les périmètres, validation possible partout, accès aux statistiques |
| `responsable_categorie` | Validation sur **ses périmètres uniquement**, création et édition d'exercices |
| `coach` | Consultation des exercices **publiés** de sa ou ses équipes, génération de séances, proposition d'exercices |

Un `coach` ne doit **jamais** voir un exercice non publié dans son générateur ou sa bibliothèque. C'est une règle de sécurité fonctionnelle : la crédibilité du module en dépend.

---

## 5. Modèle de données

Schéma SQLite complet à concevoir dès maintenant, y compris les tables des lots 2 et 3 (laissées vides). Pas de refonte ultérieure.

### `exercices`
```
id, code, titre, objectif_principal,
categorie_technique      -- echauffement | technique | tactique | jeu | physique | gardien | coordination
accents                  -- JSON: ["TE","TA","CO","ME"]  (technique/tactique/coordination/mental)
phase_easi               -- echauffement | analytique | situatif | integre
tranches_age             -- JSON: ["E","D"]
filieres                 -- JSON: ["competition","elite"]
genre                    -- mixte | masculin | feminin
joueurs_min, joueurs_max
surface_type             -- quart | demi | terrain | zone_libre | salle
surface_l, surface_L     -- dimensions en mètres
duree_min, duree_max     -- minutes
materiel                 -- JSON: [{"type":"cone","qte":12}, ...]
description_deroulement  -- texte
consignes_coach          -- texte : coaching points
criteres_reussite        -- texte
variante_facile, variante_difficile
geometrie                -- JSON (voir §6)
source                   -- socle_asf | cree_meyrin | propose_coach   ← CHAMP OBLIGATOIRE
source_reference         -- texte libre (document d'origine)
statut                   -- brouillon | en_revue | valide | publie | archive
version                  -- entier
cree_par, cree_le, modifie_par, modifie_le
```

**Le champ `source` est obligatoire et non nullable.** Il sépare ce qui vient du corpus ASF de ce qui est créé par le club. Raison : une éventuelle diffusion du module à d'autres clubs poserait une question de droits sur le matériel de l'association. Ne pas mélanger.

### `exercice_perimetres`
Liaison n-n `exercice_id` / `perimetre_id`.

### `exercice_revisions`
```
id, exercice_id, version, action, commentaire, utilisateur_id, date
-- action : soumis | valide | valide_avec_modif | renvoye | rejete | publie | archive
```
Traçabilité complète. La DT doit pouvoir prouver ce qui a été approuvé, par qui, quand.

**Règle :** un exercice `publie` qui est modifié repasse automatiquement en `en_revue` avec `version + 1`.

### `seances`
```
id, equipe_id, coach_id, date, duree_totale, nb_joueurs_prevus,
surface_disponible, accent_principal, mode  -- guide | libre
statut  -- brouillon | planifiee | realisee
notes_post_seance
cree_le
```

### `seance_blocs`
```
id, seance_id, ordre, exercice_id, phase_easi,
duree, adaptations  -- texte : ce que le moteur a ajusté
```

### `equipes`, `perimetres`, `utilisateurs_equipes`
Selon §3 et §4.

### Tables préparées pour les lots suivants (créées, non exploitées)
`plans_formation`, `accents_periodes`, `presences`, `evaluations_joueurs`

---

## 6. Rendu des schémas d'exercices en SVG

**Aucune image bitmap.** Chaque exercice stocke une géométrie JSON, et un renderer produit du SVG à la volée.

Format de `geometrie` :
```json
{
  "zone": { "type": "demi-terrain", "largeur": 50, "longueur": 34 },
  "joueurs": [
    { "x": 10, "y": 20, "equipe": "A", "label": "1", "role": "attaquant" }
  ],
  "materiel": [
    { "type": "cone", "x": 5, "y": 5 },
    { "type": "but", "x": 0, "y": 17, "orientation": 90 }
  ],
  "mouvements": [
    { "from": [10,20], "to": [25,20], "type": "passe" }
  ]
}
```

Types de mouvement : `course` (trait plein fléché), `passe` (pointillé fléché), `conduite` (ligne ondulée fléchée), `tir` (double trait fléché).
Types de matériel : `cone`, `coupelle`, `but`, `mini_but`, `mannequin`, `haie`, `echelle`, `ballon`.

**Le renderer doit produire :**
- Un SVG responsive, lisible sur écran de 380 px de large
- Une version imprimable nette en A5
- Une légende automatique
- Les couleurs du club : équipe A en `#FFD000`, équipe B en `#15140F`, joueurs neutres en gris

**Attends-toi à devoir itérer sur ce renderer.** Les premiers rendus seront imparfaits (flèches qui se croisent, chevauchements de labels). Prévois le code pour être ajustable : décalage automatique des labels superposés, courbure des flèches paramétrable.

---

## 7. Le moteur de séance

### Entrées demandées au coach (écran unique, mobile)
1. Équipe (pré-rempli si le coach n'en a qu'une)
2. Date (pré-remplie : prochaine date d'entraînement)
3. Nombre de joueurs attendus
4. Surface disponible
5. Durée
6. Accent souhaité (optionnel — proposé par défaut selon la tranche d'âge)

**Objectif : 6 champs maximum, la plupart pré-remplis. Génération en moins de 2 minutes, pouce sur un téléphone.**

### Règles de composition

Structure EASI : **échauffement → analytique → situatif → intégré**.

Répartition indicative de la durée, ajustable par tranche d'âge :

| Tranche | Échauffement | Analytique | Situatif | Intégré |
|---|---|---|---|---|
| G, F, E | 20 % | 20 % | 25 % | 35 % |
| D, FE, M15 | 15 % | 20 % | 30 % | 35 % |
| C, B, A, Actifs | 15 % | 15 % | 30 % | 40 % |

**Contrainte forte pour toutes les catégories de formation : le football joué (situatif + intégré) doit représenter au minimum 50 % de la durée totale de la séance.** C'est un principe du cadre de formation suisse, pas une préférence esthétique. Le moteur doit refuser de produire une séance qui ne le respecte pas.

Filtres appliqués à la sélection : tranche d'âge, filière, genre, nombre de joueurs (`joueurs_min ≤ prévus ≤ joueurs_max`), surface compatible, statut = `publie`.

**Anti-répétition :** ne pas reproposer un exercice utilisé par cette équipe dans les 3 dernières semaines, sauf si le pool est trop petit — dans ce cas, le signaler explicitement au coach.

### Le rôle de l'IA (appel API Anthropic depuis PHP via cURL)

L'IA intervient **uniquement** sur trois tâches :
1. **Adapter** un exercice au nombre réel de joueurs (transformer un 8v8 en 5v5, ajuster la géométrie)
2. **Rédiger** les consignes de coaching contextualisées
3. **Expliquer** la cohérence de la séance en 2 phrases au coach

Elle ne sélectionne jamais un exercice hors de la bibliothèque publiée. Elle n'invente jamais d'exercice.

**Le moteur doit fonctionner sans l'IA.** Si l'appel API échoue ou si la clé est absente, la séance est générée quand même, en mode déterministe, avec les consignes d'origine de l'exercice. Aucune dégradation bloquante.

Clé API stockée hors du repo, dans un fichier de config non versionné.

---

## 8. Le mode terrain

Vue plein écran mobile, pensée pour être consultée gant au poing, sous la pluie, en décembre.

- Un bloc à la fois, en très gros caractères
- Le schéma SVG en haut, les consignes essentielles en dessous
- Chronomètre par bloc, avec alerte sonore en fin de bloc
- Navigation par swipe entre les blocs
- **Fonctionnement hors ligne** : la séance est mise en cache localement au chargement. Les terrains n'ont pas toujours de réseau.
- Bouton « ajuster » : réduire ou augmenter la durée d'un bloc en direct, le reste se recalcule

---

## 9. Export PDF

Fiche séance A5, imprimable, une page si possible :
- En-tête : équipe, date, durée, accent, nombre de joueurs
- Un bloc par exercice : schéma SVG, déroulement, consignes, matériel
- Pied de page : liste consolidée du matériel nécessaire
- Identité visuelle du club

Génération côté serveur en PHP, sans dépendance lourde incompatible avec un hébergement mutualisé.

---

## 10. L'interface de validation

Écran dédié aux `responsable_categorie`, optimisé pour le volume.

- File d'attente filtrée sur ses périmètres uniquement
- **Un exercice par écran**, avec son schéma rendu
- Trois actions principales : **Valider** · **Valider avec modification** · **Renvoyer avec commentaire**
- Navigation clavier (flèches + raccourcis) pour enchaîner rapidement
- Objectif de performance : un responsable doit pouvoir traiter **30 exercices en une heure**

### Compteur de mise en service
Une catégorie s'ouvre à ses coachs dès que **25 exercices y sont publiés**. Les catégories avancent indépendamment — on n'attend pas que tout soit validé pour déployer.

Affiche en permanence, pour l'admin et le directeur technique, un tableau de bord d'avancement : par catégorie, nombre publiés / en revue / brouillons, et l'écart au seuil de 25.

### Proposition par les coachs
Un coach peut soumettre un exercice depuis son interface. Il arrive en `en_revue` dans le périmètre correspondant, avec `source = propose_coach`. C'est le mécanisme qui fait vivre la bibliothèque au-delà des six premiers mois.

---

## 11. Protocole de seed initial

Génère une bibliothèque de départ **structurée par périmètre**, pour que chaque responsable ne relise que ce qui le concerne.

Objectif : **30 à 40 exercices par périmètre**, tous en statut `en_revue`, jamais `publie` directement.

Répartition par périmètre, respectant les proportions EASI :
- 6 échauffements
- 8 exercices analytiques (technique)
- 10 exercices situatifs
- 10 formes de jeu / intégré
- 3 spécifiques gardien (hors G et F)

Chaque exercice doit être **complet** : géométrie renseignée, consignes rédigées, critères de réussite, variantes facile et difficile. Un exercice incomplet est un exercice que la DT rejettera, donc du travail perdu.

**Adapte le contenu à la filière.** Pour l'élite (Footeco, M15), les exercices doivent être plus exigeants : intensité, densité décisionnelle, spécificité par poste, travail sous pression.

Le seed doit être livré sous forme de **fichier de migration SQL ou script PHP rejouable**, séparé du code applicatif, pour pouvoir être régénéré ou complété sans toucher au module.

---

## 12. Hors périmètre du lot 1

Ne développe pas, mais laisse la place dans le schéma :
- Plan de formation par saison et périodisation automatique des accents (lot 2)
- Cycles de 4 semaines (lot 2)
- Suivi des présences et de la charge (lot 3)
- Tableau de bord direction technique avancé : couverture des accents, écarts au plan (lot 3)
- Éditeur de schémas en glisser-déposer (v2)

---

## 13. Ordre de livraison attendu

Annonce chaque étape, livre, attends validation.

1. Schéma SQLite complet + migrations + script de seed vide
2. Backend : routeur, authentification, CRUD exercices, workflow de validation
3. Renderer SVG + page de test affichant 10 géométries variées
4. Interface bibliothèque et interface de validation
5. Moteur de séance (mode déterministe, sans IA)
6. Couche IA d'adaptation, avec dégradation propre si indisponible
7. Mode terrain + export PDF
8. Script de seed rempli, périmètre par périmètre

À chaque étape : liste exacte des fichiers à déployer par FTP.

---

## 14. Question ouverte à me poser avant de commencer

Si un point de ce cahier des charges est ambigu ou incohérent avec le code existant du repo, **signale-le avant de coder**, ne devine pas.
