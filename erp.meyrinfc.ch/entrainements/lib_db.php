<?php
/**
 * lib_db.php — Connexion SQLite et schéma du module Entraînements.
 *
 * PHP 8.3 + PDO SQLite, aucune dépendance externe, compatible hébergement
 * mutualisé Infomaniak. La base vit dans `data/entrainements.sqlite` et n'est
 * JAMAIS écrasée par un déploiement FTP (dossier data/ gitignoré).
 *
 * Le schéma est posé en `CREATE TABLE IF NOT EXISTS` à chaque connexion : il est
 * idempotent, on peut relancer sans risque. Les évolutions futures passent par
 * `et_migrations()` (contrôle de colonne via PRAGMA avant tout ALTER), même
 * discipline que les autres modules de l'ERP.
 *
 * TOUTES les tables des lots 2 et 3 sont créées dès maintenant, même vides :
 * le prompt exige de ne pas refondre le schéma plus tard.
 *
 * Conventions de rattachement au socle ERP :
 *   - une ÉQUIPE n'est jamais dupliquée ici. `equipe_config.ref_id` pointe vers
 *     l'identité stable d'équipe du référentiel club (lib/mfc_club.php). Nom,
 *     catégorie, entraîneur et saison restent lus depuis le socle.
 *   - un UTILISATEUR est identifié par son `erp_id` (le `sub` du jeton JWT).
 *     `utilisateurs` est un annuaire local non destructif : il porte le profil
 *     métier du module (coach, responsable, DT), pas l'authentification.
 */

declare(strict_types=1);

const ET_DB_DIR  = __DIR__ . '/data';
const ET_DB_FILE = ET_DB_DIR . '/entrainements.sqlite';

/** Connexion PDO partagée, schéma garanti à jour. */
function et_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!is_dir(ET_DB_DIR)) {
        mkdir(ET_DB_DIR, 0775, true);
    }
    /* data/ ne doit pas être servi en direct par le serveur web. */
    $ht = ET_DB_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "Require all denied\n");
    }

    $pdo = new PDO('sqlite:' . ET_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    et_init_schema($pdo);
    et_migrations($pdo);
    et_seed_referentiel($pdo);

    return $pdo;
}

/* ============================================================ SCHÉMA (LOT 1) */

function et_init_schema(PDO $db): void
{
    $db->exec(<<<'SQL'

    -- Suivi interne de version de schéma (pour les migrations futures).
    CREATE TABLE IF NOT EXISTS schema_meta (
        cle    TEXT PRIMARY KEY,
        valeur TEXT
    );

    -- ============================================================ UTILISATEURS
    -- Annuaire local NON destructif. `erp_id` = sub du jeton JWT de l'ERP.
    -- Le profil pilote les droits internes au module ; l'accès à l'app est
    -- gouverné en amont par la permission ERP `entrainements.access`.
    CREATE TABLE IF NOT EXISTS utilisateurs (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        erp_id        TEXT NOT NULL UNIQUE,
        nom           TEXT NOT NULL DEFAULT '',
        email         TEXT NOT NULL DEFAULT '',
        -- admin | directeur_technique | responsable_categorie | coach
        profil        TEXT NOT NULL DEFAULT 'coach',
        actif         INTEGER NOT NULL DEFAULT 1,
        cree_le       TEXT NOT NULL DEFAULT (datetime('now')),
        dernier_acces TEXT
    );

    -- ================================================================ PÉRIMÈTRES
    -- 6 périmètres de validation, modifiables depuis l'admin du module.
    -- `tranches_age` : JSON, liste des codes d'Axe 1 couverts (indicatif).
    CREATE TABLE IF NOT EXISTS perimetres (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        code         TEXT NOT NULL UNIQUE,
        libelle      TEXT NOT NULL,
        tranches_age TEXT NOT NULL DEFAULT '[]',
        -- mode libre : bibliothèque consultable, générateur simplifié, pas de
        -- blocage de publication (cas SENIORS et ACTIFS 1re/2e ligue).
        mode_libre   INTEGER NOT NULL DEFAULT 0,
        seuil_ouverture INTEGER NOT NULL DEFAULT 25,
        actif        INTEGER NOT NULL DEFAULT 1,
        cree_le      TEXT NOT NULL DEFAULT (datetime('now'))
    );

    -- Responsable(s) d'un périmètre. Un responsable_categorie ne valide QUE
    -- sur les périmètres où il figure ici.
    CREATE TABLE IF NOT EXISTS perimetre_responsables (
        perimetre_id    INTEGER NOT NULL REFERENCES perimetres(id) ON DELETE CASCADE,
        utilisateur_id  INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
        PRIMARY KEY (perimetre_id, utilisateur_id)
    );

    -- =================================================== CONFIG DES ÉQUIPES
    -- Greffe sur le référentiel club : `ref_id` = identité stable d'équipe.
    -- On stocke ici seulement ce qui est propre à l'entraînement.
    CREATE TABLE IF NOT EXISTS equipe_config (
        ref_id        TEXT NOT NULL,
        saison_id     TEXT NOT NULL DEFAULT '',
        -- Axe 1 (référentiel interne, voir seed) : G,F,E,D,FE-12,FE-13,FE-14,
        -- M15,C,B,A,ACTIFS,SENIORS
        tranche_age   TEXT NOT NULL DEFAULT '',
        -- Axe 2 : loisir | competition | elite
        filiere       TEXT NOT NULL DEFAULT '',
        -- Axe 3 : mixte | masculin | feminin
        genre         TEXT NOT NULL DEFAULT 'mixte',
        -- guide (référentiel d'accents appliqué) | libre (composition libre)
        mode          TEXT NOT NULL DEFAULT 'guide',
        perimetre_id  INTEGER REFERENCES perimetres(id) ON DELETE SET NULL,
        -- 0 tant que l'admin n'a pas validé le mapping : l'équipe est alors
        -- exclue du générateur et signalée « à configurer », jamais devinée.
        configure     INTEGER NOT NULL DEFAULT 0,
        modifie_par   TEXT,
        modifie_le    TEXT,
        PRIMARY KEY (ref_id, saison_id)
    );

    -- Coach <-> équipes qu'il entraîne (par identité d'équipe stable).
    CREATE TABLE IF NOT EXISTS utilisateurs_equipes (
        utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
        equipe_ref_id  TEXT NOT NULL,
        PRIMARY KEY (utilisateur_id, equipe_ref_id)
    );

    -- ================================================================ EXERCICES
    CREATE TABLE IF NOT EXISTS exercices (
        id                     INTEGER PRIMARY KEY AUTOINCREMENT,
        code                   TEXT NOT NULL UNIQUE,
        titre                  TEXT NOT NULL,
        objectif_principal     TEXT NOT NULL DEFAULT '',
        -- echauffement|technique|tactique|jeu|physique|gardien|coordination
        categorie_technique    TEXT NOT NULL DEFAULT 'technique',
        -- JSON : ["TE","TA","CO","ME"] (technique/tactique/coordination/mental)
        accents                TEXT NOT NULL DEFAULT '[]',
        -- echauffement|analytique|situatif|integre
        phase_easi             TEXT NOT NULL DEFAULT 'analytique',
        tranches_age           TEXT NOT NULL DEFAULT '[]',   -- JSON
        filieres               TEXT NOT NULL DEFAULT '[]',   -- JSON
        genre                  TEXT NOT NULL DEFAULT 'mixte', -- mixte|masculin|feminin
        joueurs_min            INTEGER NOT NULL DEFAULT 1,
        joueurs_max            INTEGER NOT NULL DEFAULT 30,
        -- quart|demi|terrain|zone_libre|salle
        surface_type           TEXT NOT NULL DEFAULT 'quart',
        -- SQLite est insensible à la casse sur les noms de colonnes : le
        -- schéma du cahier des charges (surface_l / surface_L) est donc
        -- renommé en largeur / longueur, mêmes valeurs en mètres.
        surface_largeur        REAL NOT NULL DEFAULT 0,
        surface_longueur       REAL NOT NULL DEFAULT 0,
        duree_min              INTEGER NOT NULL DEFAULT 10,
        duree_max              INTEGER NOT NULL DEFAULT 20,
        materiel               TEXT NOT NULL DEFAULT '[]', -- JSON [{"type","qte"}]
        description_deroulement TEXT NOT NULL DEFAULT '',
        consignes_coach        TEXT NOT NULL DEFAULT '',
        criteres_reussite      TEXT NOT NULL DEFAULT '',
        variante_facile        TEXT NOT NULL DEFAULT '',
        variante_difficile     TEXT NOT NULL DEFAULT '',
        geometrie              TEXT NOT NULL DEFAULT '{}',  -- JSON (voir renderer)
        -- CHAMP OBLIGATOIRE : socle_asf | cree_meyrin | propose_coach
        source                 TEXT NOT NULL DEFAULT 'cree_meyrin',
        source_reference       TEXT NOT NULL DEFAULT '',
        -- brouillon|en_revue|valide|publie|archive
        statut                 TEXT NOT NULL DEFAULT 'brouillon',
        version                INTEGER NOT NULL DEFAULT 1,
        cree_par               TEXT,
        cree_le                TEXT NOT NULL DEFAULT (datetime('now')),
        modifie_par            TEXT,
        modifie_le             TEXT
    );

    -- Un exercice peut appartenir à plusieurs périmètres ; la validation par
    -- l'un d'eux suffit à le publier (chevauchement de la tranche E assumé).
    CREATE TABLE IF NOT EXISTS exercice_perimetres (
        exercice_id  INTEGER NOT NULL REFERENCES exercices(id) ON DELETE CASCADE,
        perimetre_id INTEGER NOT NULL REFERENCES perimetres(id) ON DELETE CASCADE,
        PRIMARY KEY (exercice_id, perimetre_id)
    );

    -- Traçabilité complète : la DT doit pouvoir prouver quoi, par qui, quand.
    CREATE TABLE IF NOT EXISTS exercice_revisions (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        exercice_id    INTEGER NOT NULL REFERENCES exercices(id) ON DELETE CASCADE,
        version        INTEGER NOT NULL DEFAULT 1,
        -- soumis|valide|valide_avec_modif|renvoye|rejete|publie|archive
        action         TEXT NOT NULL,
        commentaire    TEXT NOT NULL DEFAULT '',
        utilisateur_id INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
        date           TEXT NOT NULL DEFAULT (datetime('now'))
    );

    -- ================================================================== SÉANCES
    CREATE TABLE IF NOT EXISTS seances (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        equipe_id          TEXT NOT NULL DEFAULT '',   -- ref_id du référentiel club
        coach_id           INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
        date               TEXT NOT NULL,
        duree_totale       INTEGER NOT NULL DEFAULT 0, -- minutes
        nb_joueurs_prevus  INTEGER NOT NULL DEFAULT 0,
        surface_disponible TEXT NOT NULL DEFAULT '',
        accent_principal   TEXT NOT NULL DEFAULT '',
        mode               TEXT NOT NULL DEFAULT 'guide', -- guide | libre
        -- brouillon|planifiee|realisee
        statut             TEXT NOT NULL DEFAULT 'brouillon',
        notes_post_seance  TEXT NOT NULL DEFAULT '',
        -- coût IA cumulé de cette séance (CHF), 0 si générée en déterministe
        cout_ia_chf        REAL NOT NULL DEFAULT 0,
        ia_utilisee        INTEGER NOT NULL DEFAULT 0,
        cree_le            TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS seance_blocs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        seance_id   INTEGER NOT NULL REFERENCES seances(id) ON DELETE CASCADE,
        ordre       INTEGER NOT NULL DEFAULT 0,
        exercice_id INTEGER REFERENCES exercices(id) ON DELETE SET NULL,
        phase_easi  TEXT NOT NULL DEFAULT '',
        duree       INTEGER NOT NULL DEFAULT 0,  -- minutes
        -- ce que le moteur (ou l'IA) a ajusté par rapport à l'exercice d'origine
        adaptations TEXT NOT NULL DEFAULT '',
        -- copie figée du contenu au moment de la génération, pour que la séance
        -- reste lisible même si l'exercice source évolue ensuite
        snapshot    TEXT NOT NULL DEFAULT '{}'   -- JSON
    );

    -- ======================================================= IA : BUDGET & USAGE
    -- Plafond mensuel de dépense IA. Trois portées possibles :
    --   club      -> portee_ref NULL
    --   perimetre -> portee_ref = id de perimetres
    --   equipe    -> portee_ref = ref_id d'équipe (référentiel club)
    -- Quand un plafond applicable est atteint pour le mois en cours, le moteur
    -- génère la séance en mode déterministe (aucun appel IA).
    CREATE TABLE IF NOT EXISTS ia_budgets (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        portee              TEXT NOT NULL CHECK (portee IN ('club','perimetre','equipe')),
        portee_ref          TEXT,
        plafond_mensuel_chf REAL NOT NULL DEFAULT 0,
        actif               INTEGER NOT NULL DEFAULT 1,
        modifie_par         TEXT,
        modifie_le          TEXT NOT NULL DEFAULT (datetime('now'))
    );

    -- Journal de consommation IA, une ligne par appel (ou par appel évité pour
    -- cause de plafond). `mois` = 'YYYY-MM' pour des sommes mensuelles rapides.
    CREATE TABLE IF NOT EXISTS ia_usage (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        mois           TEXT NOT NULL,
        seance_id      INTEGER REFERENCES seances(id) ON DELETE SET NULL,
        utilisateur_id INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
        equipe_ref_id  TEXT NOT NULL DEFAULT '',
        perimetre_id   INTEGER REFERENCES perimetres(id) ON DELETE SET NULL,
        -- adapter | rediger | expliquer
        tache          TEXT NOT NULL DEFAULT '',
        modele         TEXT NOT NULL DEFAULT '',
        tokens_in      INTEGER NOT NULL DEFAULT 0,
        tokens_out     INTEGER NOT NULL DEFAULT 0,
        cout_chf       REAL NOT NULL DEFAULT 0,
        -- ok | echec | desactive_budget
        statut         TEXT NOT NULL DEFAULT 'ok',
        detail         TEXT NOT NULL DEFAULT '',
        cree_le        TEXT NOT NULL DEFAULT (datetime('now'))
    );

    -- ================================================== TABLES DES LOTS 2 & 3
    -- Créées maintenant, non exploitées par le lot 1. Colonnes minimales,
    -- extensibles par migration.

    -- Lot 2 : plan de formation par saison et périodisation des accents.
    CREATE TABLE IF NOT EXISTS plans_formation (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        perimetre_id INTEGER REFERENCES perimetres(id) ON DELETE CASCADE,
        saison_id    TEXT NOT NULL DEFAULT '',
        titre        TEXT NOT NULL DEFAULT '',
        contenu      TEXT NOT NULL DEFAULT '{}',  -- JSON
        cree_le      TEXT NOT NULL DEFAULT (datetime('now'))
    );

    -- Lot 2 : cycles de 4 semaines, accent dominant par période.
    CREATE TABLE IF NOT EXISTS accents_periodes (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        plan_id        INTEGER REFERENCES plans_formation(id) ON DELETE CASCADE,
        semaine_debut  TEXT NOT NULL DEFAULT '',
        semaine_fin    TEXT NOT NULL DEFAULT '',
        accent         TEXT NOT NULL DEFAULT '',
        objectifs      TEXT NOT NULL DEFAULT ''
    );

    -- Lot 3 : suivi des présences et de la charge.
    CREATE TABLE IF NOT EXISTS presences (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        seance_id   INTEGER REFERENCES seances(id) ON DELETE CASCADE,
        joueur_ref  TEXT NOT NULL DEFAULT '',   -- futur lien module Membres
        present     INTEGER NOT NULL DEFAULT 1,
        charge_rpe  INTEGER,                    -- ressenti d'effort 1-10
        commentaire TEXT NOT NULL DEFAULT ''
    );

    -- Lot 3 : évaluations individuelles des joueurs.
    CREATE TABLE IF NOT EXISTS evaluations_joueurs (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        joueur_ref    TEXT NOT NULL DEFAULT '',
        date          TEXT NOT NULL DEFAULT (date('now')),
        critere       TEXT NOT NULL DEFAULT '',
        note          REAL,
        evaluateur_id INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
        commentaire   TEXT NOT NULL DEFAULT ''
    );

    -- ================================================================= INDEX
    CREATE INDEX IF NOT EXISTS idx_exercices_statut       ON exercices(statut);
    CREATE INDEX IF NOT EXISTS idx_exercices_phase        ON exercices(phase_easi);
    CREATE INDEX IF NOT EXISTS idx_exercices_categorie    ON exercices(categorie_technique);
    CREATE INDEX IF NOT EXISTS idx_exrev_exercice         ON exercice_revisions(exercice_id);
    CREATE INDEX IF NOT EXISTS idx_experim_perimetre      ON exercice_perimetres(perimetre_id, exercice_id);
    CREATE INDEX IF NOT EXISTS idx_seances_equipe_date    ON seances(equipe_id, date);
    CREATE INDEX IF NOT EXISTS idx_blocs_seance           ON seance_blocs(seance_id);
    CREATE INDEX IF NOT EXISTS idx_iausage_mois           ON ia_usage(mois);
    CREATE INDEX IF NOT EXISTS idx_iausage_equipe_mois    ON ia_usage(equipe_ref_id, mois);
    CREATE INDEX IF NOT EXISTS idx_iausage_perimetre_mois ON ia_usage(perimetre_id, mois);
    CREATE UNIQUE INDEX IF NOT EXISTS idx_iabudget_portee ON ia_budgets(portee, IFNULL(portee_ref, ''));

    SQL);

    if (et_meta_get($db, 'schema_version') === null) {
        et_meta_set($db, 'schema_version', '1');
        et_meta_set($db, 'cree_le', date('c'));
    }
}

/* ==================================================== MIGRATIONS FUTURES */

/**
 * Évolutions additives du schéma. Chaque bloc vérifie l'état réel de la base
 * (PRAGMA) avant d'agir : rejouable sans effet de bord, jamais destructif.
 * Le lot 1 n'a aucune migration ; la fonction existe pour la suite.
 */
function et_migrations(PDO $db): void
{
    // Exemple de forme, à décommenter le jour venu :
    //
    // $cols = array_column($db->query('PRAGMA table_info(exercices)')->fetchAll(), 'name');
    // if (!in_array('nouveau_champ', $cols, true)) {
    //     $db->exec("ALTER TABLE exercices ADD COLUMN nouveau_champ TEXT DEFAULT ''");
    // }
    // et_meta_set($db, 'schema_version', '2');
}

/* ============================================ SEED DU RÉFÉRENTIEL (LOT 1) */

/**
 * Insère les 6 périmètres s'ils n'existent pas encore. Idempotent : ne touche
 * jamais une ligne déjà présente (l'admin peut ensuite les modifier librement).
 *
 * Répartition = point de départ, ajustable depuis l'interface d'admin.
 */
function et_seed_referentiel(PDO $db): void
{
    $perimetres = [
        ['ecole_foot',        'Responsable école de foot',
            ['G','F','E'], 0],
        ['foot_libre_filles', 'Responsable foot libre + école de foot filles',
            ['G','F','E'], 0],
        ['filles',            'Responsable football féminin',
            ['E','D','FE-14','M15','C','B','A','ACTIFS'], 0],
        ['preformation',      'Responsable préformation',
            ['E','D'], 0],
        ['elite',             'Responsable élite',
            ['FE-12','FE-13','FE-14','M15'], 0],
        ['actifs',            'Responsable actifs',
            ['C','B','A','ACTIFS','SENIORS'], 1], // mode libre par défaut (B UEFA, +30/+40/+50)
    ];

    $exists = $db->prepare('SELECT 1 FROM perimetres WHERE code = ?');
    $insert = $db->prepare(
        'INSERT INTO perimetres (code, libelle, tranches_age, mode_libre)
         VALUES (?, ?, ?, ?)'
    );
    foreach ($perimetres as [$code, $libelle, $tranches, $libre]) {
        $exists->execute([$code]);
        if ($exists->fetchColumn()) continue;
        $insert->execute([$code, $libelle, json_encode($tranches), $libre]);
    }
}

/* ================================================================ HELPERS */

function et_meta_get(PDO $db, string $cle): ?string
{
    $st = $db->prepare('SELECT valeur FROM schema_meta WHERE cle = ?');
    $st->execute([$cle]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function et_meta_set(PDO $db, string $cle, string $valeur): void
{
    $db->prepare(
        'INSERT INTO schema_meta (cle, valeur) VALUES (?, ?)
         ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur'
    )->execute([$cle, $valeur]);
}

/**
 * Référentiel canonique des tranches d'âge (Axe 1). Ordre = du plus jeune au
 * plus âgé, sert au tri et aux règles de répartition EASI.
 *
 * Le football féminin n'est PAS un code ici : c'est le filtre `genre = feminin`
 * appliqué sur ces mêmes tranches (FF-14 = FE-14 + genre féminin, etc.).
 */
function et_tranches_age(): array
{
    return ['G','F','E','D','FE-12','FE-13','FE-14','M15','C','B','A','ACTIFS','SENIORS'];
}

function et_filieres(): array
{
    return ['loisir','competition','elite'];
}

function et_genres(): array
{
    return ['mixte','masculin','feminin'];
}
