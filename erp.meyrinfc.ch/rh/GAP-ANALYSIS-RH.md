# Gap analysis — Module RH / Paie
## erp.meyrinfc.ch/rh — état au 2026-08-16

Ce document remplace la section 1 (gap analysis) de `SPEC-RH-PAIE-MEYRINFC.md` et met à jour `AUDIT-RH-MEYRINFC-v2.md`, écrit avant le chantier « fiche employé complète » du 2026-08-14. Il classe chaque exigence numérotée de la SPEC en `✅ COUVERT`, `⚠️ PARTIEL`, `❌ ABSENT` ou `🔴 CONTRADICTOIRE`, sur la base d'une lecture du code réel (`rh/api.php`, `rh/index.html`) et non d'une relecture des deux documents précédents seuls.

**Ne pas commencer l'implémentation avant validation de ce fichier par Valentino**, conformément à la SPEC §1.

---

## 0. Constat central (mis à jour)

Le chantier du 2026-08-14 a construit **la moitié référentielle** de ce que demande la SPEC : la fiche employé porte maintenant une identité sociale complète, des taux datés par année, des profils d'assurance mutualisés. C'est du travail réel et il n'est pas à refaire.

**Mais le moteur de calcul n'a pas changé.** Il vit toujours dans `rh/index.html` (côté navigateur), applique toujours **une seule base plate** (`grossTotal`) à toutes les lignes de charges, et les nouveaux flags `avs_subject` / `aanp_subject` etc. ne servent qu'à activer ou désactiver une ligne — pas à constituer une base propre avec plafond. Le défaut structurant de l'audit v2 (A.1.1, A.1.3, A.2.3) est donc **toujours vrai**, malgré l'enrichissement des données.

Autrement dit : les fondations de données sont posées, la maison n'est pas construite dessus.

---

## 1. Modèle de données fonctionnel (SPEC §3)

| # | Exigence | Statut | Constat |
|---|---|---|---|
| 3.1 | Personne : identité, N° AVS, naissance, état civil, permis, adresse, IBAN | ✅ COUVERT (employés) / ❌ ABSENT (joueurs) | `employees` porte tout depuis le 2026-08-14. `players` n'a ni N° AVS, ni permis, ni état civil — seulement nom, date de naissance, adresse |
| 3.2 | Rapport de travail versionné (historisation) | ❌ ABSENT | Aucune notion de version. Modifier une fiche employé écrase la précédente, comme avant |
| 3.3 | Catalogue de rubriques avec attributs d'assujettissement par assurance | ⚠️ PARTIEL | Les colonnes `{avs,ac,amat,aanp,laac,ijm,lpp,is}_subject` existent par personne, mais il n'y a pas de catalogue de rubriques distinct des cotisations sociales : les indemnités/primes saisies à la main (`PS.indemnites`) n'ont aucun attribut d'assujettissement, elles s'ajoutent simplement au brut avant calcul des charges |
| 3.4 | Période de paie avec statut (ouverte/calculée/validée/clôturée/rouverte) | ❌ ABSENT | Un décompte a juste une case « payé » (`payroll_payments.paid`), aucun cycle |
| 3.5 | Décompte immuable avec bases et taux conservés | ❌ ABSENT | `payroll_payments` stocke un chemin de PDF (`payslip_path`), pas les lignes/bases/taux structurés. Confirmé par le commentaire du code lui-même (`index.html`, fonction `buildPayslipPayload`) : *« le serveur ne recalcule rien, il pose exactement ce que le responsable a sous les yeux »* |
| 3.6 | Cumuls annuels par personne/rapport de travail | ❌ ABSENT | Aucune table ni vue de cumul. Impossible de vérifier un seuil ou une franchise sur l'année |
| 3.7 | Absences / événements (maladie, accident, service militaire) | ❌ ABSENT | Rien dans le schéma |
| 3.8 | Référentiel de paramètres datés | ⚠️ PARTIEL | `payroll_rate_settings` est daté par année civile pour les 6 taux partagés — bon principe. Mais LPP, impôt source, abattement OCAS restent des valeurs figées par personne, jamais datées : les changer efface la valeur précédente sans trace |
| 3.9 | Enfants / allocations familiales | ✅ COUVERT | `employee_children` existe (nom, naissance, formation en cours, montant d'allocation) |

## 2. Référentiel de paramètres datés (SPEC §4)

| # | Paramètre | Statut | Constat |
|---|---|---|---|
| 4.1 | AVS : taux, franchise rentier, seuil minime importance | ⚠️ PARTIEL | Taux AVS dans `payroll_rate_settings.avs_rate` (daté). **Franchise rentier (1'400/mois) : absente.** **Seuil de minime importance (2'500/an) : absent** comme règle automatique — seul l'abattement OCAS spécifique joueurs existe (voir §4 ci-dessous), pas le mécanisme général de renonciation |
| 4.2 | AC : taux, plafond 148'200, cotisation solidarité | ⚠️ PARTIEL | Taux daté existe. **Aucun plafond appliqué nulle part dans le calcul** |
| 4.3 | LAA/LAAC : taux, gain maximum assuré, distinction AAP/AANP | ⚠️ PARTIEL | `aanp_rate`/`laac_rate` datés existent. Pas de plafond, pas de séparation explicite AAP (toujours dû, à charge employeur) vs AANP |
| 4.4 | LPP : seuil d'entrée, coordination, salaire coordonné | ❌ ABSENT | `lpp_rate`/`lpp_amount` sont des valeurs saisies à la main par employé, sans seuil d'entrée (22'680), sans déduction de coordination (26'460), sans plafond (90'720) |
| 4.5 | Allocations familiales : taux, montants par enfant | ⚠️ PARTIEL | `employee_children.allocation` est un montant saisi à la main par enfant, pas calculé depuis un barème genevois daté |
| 4.6 | Impôt à la source : modèle annuel genevois, barèmes AFC-GE | ❌ ABSENT | `is_rate`/`is_amount` = saisie manuelle. Aucun barème importé, aucun modèle annuel implémenté (voir 6.4.1 ci-dessous, point le plus coûteux de la SPEC) |
| 4.7 | Maternité cantonale, IJM, 13e salaire | ⚠️ PARTIEL | `ijm_rate` daté existe. `payments_per_year` existe sur l'employé (12/13) mais rien ne vérifie sa cohérence avec le calcul du décompte |

## 3. Moteur de calcul (SPEC §5)

| # | Exigence | Statut | Constat |
|---|---|---|---|
| — | Séquence normative (brut → bases par assurance → seuils/franchises → cotisations → IS → net) | 🔴 CONTRADICTOIRE | Le code fait : brut → **une seule base** → cotisations en % de cette base unique → net. Aucune étape de constitution de bases distinctes, aucun traitement de seuil/franchise avant le calcul des cotisations. Ce n'est pas un écart partiel, c'est un ordre de calcul différent de celui exigé |
| 5.1 | Calcul déterministe et rejouable | ❌ ABSENT | Rien n'est stocké pour rejouer : recalculer plus tard avec des taux modifiés donne un résultat différent, silencieusement |
| 5.2 | Chaque ligne conserve sa base et son taux | ⚠️ PARTIEL | Le PDF généré affiche la base et le taux au moment de l'impression, mais rien n'est persisté côté serveur pour le consulter après coup |
| 5.3 | Règle d'arrondi unique documentée | ❌ ABSENT | Pas de règle explicite trouvée |
| 5.4 | Décompte validé immuable, correction par rectificatif | ❌ ABSENT | Un décompte peut être régénéré et écrasé (`UPDATE payroll_payments SET payslip_path=...`) à tout moment, y compris pour une période passée |
| 5.5 | Rétroactif automatique | ❌ ABSENT | Aucun mécanisme |

## 4. Règles spécifiques club (SPEC §6)

| # | Règle | Statut | Constat |
|---|---|---|---|
| 6.1 | Seuil minime importance (2'500/an), projection annuelle, alerte, régularisation rétroactive | ❌ ABSENT | Rien. Le seul mécanisme voisin est l'abattement OCAS ci-dessous, qui est différent dans son principe (réduction de base, pas franchise tout-ou-rien) |
| 6.2 | Franchise rentier, non-cumul avec le seuil | ❌ ABSENT | Aucune notion de statut rentier AVS dans le schéma |
| — | **Abattement OCAS joueurs/coachs (addendum v2, §C.2)** | ⚠️ PARTIEL — nouveau depuis le 14 août, à valider | `players.avs_abatement` (flag booléen) existe et **réduit effectivement la base de calcul** des charges du décompte joueur (416.67 CHF/mois, vérifié dans `index.html`). C'est un vrai progrès, mais : **(a)** pas de source/date de l'arrangement OCAS enregistrée dans le module (C.2.1.b de l'addendum) ; **(b)** pas de double calcul légal/arrangement pour tracer l'exposition du club (C.2.1.c) ; **(c)** pas de distinction assiette par assiette (AVS seule ou aussi AC/LAA/AMAT — le code semble l'appliquer à la base générale des charges, à vérifier précisément) ; **(d)** pas de contrôle de non-cumul avec une franchise rentier (qui n'existe pas encore de toute façon) ; **(e)** le flag n'est ni daté ni désactivable dans le temps — le changer un jour ne recalcule rien rétroactivement |
| 6.3 | Distinction frais effectifs / indemnité forfaitaire, blocage sans justificatif | ❌ ABSENT | `PS.indemnites` accepte n'importe quel libellé et montant sans distinction ni contrôle |
| 6.4.1 | Impôt source, modèle annuel genevois | ❌ ABSENT | Confirmé : saisie manuelle d'un taux/montant fixe par employé, aucun modèle mensuel ni annuel appliqué. Ni erreur à corriger, ni modèle à corriger : rien n'existe |
| 6.4.2–6.4.7 | Détermination automatique IS, barème, télétravail frontalier | ❌ ABSENT | — |
| 6.5 | Saison sportive vs année civile, entrées/sorties en cours d'année | ⚠️ PARTIEL | Le référentiel saisons (juillet-juin) existe et fonctionne bien pour les affectations, mais le décompte de paie ne fait aucun prorata d'entrée/sortie en cours de période (hérité de A.2.4, toujours vrai) |
| 6.6 | Arbitres, personnel buvette, bénévoles indemnisés | ❌ ABSENT | Non traité comme population distincte |

## 5. Documents et sorties (SPEC §8)

| # | Document | Statut |
|---|---|---|
| 8.1 | Fiche de salaire | ⚠️ PARTIEL — un PDF est généré et lisible, mais sans les bases/taux détaillés persistés ni les cumuls annuels |
| 8.2 | Certificat de salaire (formulaire 11) | ❌ ABSENT |
| 8.3 | Attestation annuelle OCAS | ❌ ABSENT |
| 8.4 | Déclaration LPP AXA | ❌ ABSENT |
| 8.5 | Décompte mensuel IS AFC-GE | ❌ ABSENT |
| 8.6 | Reporting analytique par équipe/catégorie/saison | ✅ COUVERT (partiellement, hors paie) — le référentiel équipes/catégories/saisons permet déjà l'imputation, mais aucun rapport de masse salariale par équipe n'a été vu dans `api.php` |
| 8.7 | Écritures comptables | ⚠️ PARTIEL — des numéros de compte comptable sont rattachés aux rubriques (lavage, salaires joueurs) mais aucun export d'écriture n'a été trouvé |
| 8.8 | Fichier de paiement ISO 20022 | ❌ ABSENT |
| 8.9 | Export de sortie de dépendance | ❌ ABSENT |

## 6. Contrôles (SPEC §9) et audit (SPEC §10)

| # | Exigence | Statut |
|---|---|---|
| 9.1–9.8 | Contrôles bloquants (N° AVS invalide, permis expiré, net négatif, etc.) | ❌ ABSENT — aucun contrôle trouvé avant génération d'un décompte |
| 9.9–9.14 | Alertes non bloquantes | ❌ ABSENT |
| 10.1 | Journal d'audit inaltérable | ❌ ABSENT — aucune table de journal, aucun `INSERT` d'historique trouvé dans `api.php` |
| 10.2 | Référence des versions de paramètres par décompte | ❌ ABSENT |
| 10.3 | Fonction « expliquer ce calcul » | ❌ ABSENT |

## 7. Accès et droits (SPEC §11)

| # | Exigence | Statut |
|---|---|---|
| 11.1–11.2 | Rôles minimaux : Gestionnaire / Direction / Rémunéré / Auditeur | ⚠️ PARTIEL — seuls deux niveaux existent (`payroll.view`, `payroll.edit`), correctement appliqués et avec dégradation propre (403 explicite). Pas de rôle « Direction » limité aux agrégats, pas de rôle « Auditeur » lecture seule |
| 11.3 | Portail employé (fiches, certificat) | ⚠️ PARTIEL — `mes-documents.php` / `mon-document.php` / `mon-piece.php` / `mon-fichier.php` donnent déjà accès par token à ses propres documents et fiches de paie. Bonne base, mais pas de certificat de salaire à donner puisqu'il n'existe pas encore |
| 11.4 | Chiffrement au repos (IBAN, N° AVS, permis) | ❌ ABSENT — SQLite en clair, comme le reste des modules Meyrin FC |
| 11.5 | Conformité nLPD | ❌ ABSENT — hors périmètre code, action de gouvernance |

---

## 8. Règles codées en dur trouvées (SPEC §1, point 3)

- `AVS_ABATEMENT_MONTHLY` (416.67) : en dur dans `index.html`, devrait être un paramètre daté comme les autres taux
- Seuil de retenue lavage (300) et montant (30) : **déjà corrigés**, vivent dans `payroll_rate_settings` depuis le 14 août — bon exemple à suivre pour le reste
- Aucun taux AVS/AC/LPP officiel suisse en dur trouvé (conforme à la décision du 2026-07-15, toujours respectée)

## 9. Calculs existants qui produiraient un résultat faux (SPEC §1, point 4)

C'est le point le plus urgent de toute cette analyse : **si des décomptes réels sont générés aujourd'hui avec ce moteur pour des personnes dépassant un seuil ou un plafond, ils sont faux** — pas approximatifs, faux. Concrètement, tout employé ou joueur dont l'assiette AVS ou LPP dépasse un plafond légal, ou qui devrait bénéficier d'une franchise rentier, reçoit un décompte incorrect sans qu'aucune alerte ne le signale. Ceci concerne potentiellement des décomptes déjà émis et archivés (`payroll_payments`) : à vérifier avant toute chose auprès des personnes à salaire le plus élevé (directeurs techniques notamment, P2 dans la typologie).

---

## 10. Ce qui est bon et à conserver (confirmé, inchangé)

- Le référentiel organisationnel (saisons, catégories, équipes, postes, affectations)
- La fiche employé enrichie du 2026-08-14 (identité sociale, permis, statut fiscal) : **fondation correcte pour tout le reste**, ne pas la refaire
- `payroll_rate_settings` daté par année : bon principe à étendre à LPP, IS, franchise, seuil
- Les profils d'assurance mutualisés
- Le modèle de droits `payroll.view`/`payroll.edit` avec dégradation propre
- Le portail employé par token pour les documents
- L'abattement OCAS joueurs : premier pas réel vers C.2, à compléter (traçabilité, double calcul, datation) plutôt qu'à refaire

---

## 11. Priorités révisées

**P0 — avant tout code, action humaine puis blocage technique**
1. Vérifier si des décomptes déjà émis sont faux (§9 ci-dessus) — action immédiate, indépendante du reste
2. Obtenir la trace écrite de l'arrangement OCAS et son périmètre exact (assiettes concernées) — sans elle, l'abattement joueur actuel reste juridiquement fragile
3. Construire le moteur séquencé côté serveur avec bases distinctes et plafonds (§3 de ce document) — c'est le seul vrai chantier technique qui débloque tout le reste
4. Persister le décompte (lignes, bases, taux, référence des paramètres) au lieu de recalculer à la volée à chaque affichage

**P1 — cœur fonctionnel, une fois P0 fait**
5. Franchise rentier + seuil de minime importance générique (au-delà du cas joueur déjà traité), avec contrôle de non-cumul
6. Modèle IS annuel genevois avec import de barème
7. LPP avec seuil d'entrée et coordination
8. Contrôles bloquants (§9 SPEC)
9. Journal d'audit
10. Unification joueurs / employés dans un seul référentiel de rapports de travail

**P2 — exploitation**
11. Certificat de salaire, exports OCAS/AXA/AFC-GE, fichier de paiement
12. Rôles Direction/Auditeur
13. Chiffrement au repos

---

## Questions ouvertes à trancher avant d'implémenter

1. Combien de décomptes ont déjà été émis via ce module (pas via l'Excel externe), et pour qui ? Détermine l'urgence du point §9.
2. L'arrangement OCAS existe-t-il par écrit ? Sur quelles assiettes porte-t-il précisément (AVS seule, ou aussi AC/LAA/AMAT) ?
3. Le fichier Excel externe est-il toujours utilisé en parallèle du module pour certains décomptes ?
4. Confirme-t-on un moteur de paie interne complet, ou vise-t-on un module d'avant-paie qui alimente un logiciel certifié Swissdec en aval ? Cette décision change le périmètre de moitié (question 7 de la SPEC, toujours ouverte).
