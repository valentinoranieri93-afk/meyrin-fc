<?php
/**
 * lib_exercices.php — Bibliothèque d'exercices et workflow de validation.
 *
 * Règles structurantes (cahier des charges §4, §5, §10) :
 *   - Un `coach` ne voit JAMAIS un exercice non publié. Règle de sécurité
 *     fonctionnelle, appliquée dans chaque requête de lecture, sans exception.
 *   - Un `responsable_categorie` n'agit que sur SES périmètres.
 *   - Un exercice `publie` qui est modifié repasse en `en_revue` avec
 *     version + 1, automatiquement.
 *   - Toute transition est tracée dans `exercice_revisions` : la DT doit
 *     pouvoir prouver quoi, par qui, quand.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib_entrainements.php';

/* ================================================================= ENUMS */

const ET_CATEGORIES = ['echauffement','technique','tactique','jeu','physique','gardien','coordination'];
const ET_PHASES     = ['echauffement','analytique','situatif','integre'];
const ET_ACCENTS    = ['TE','TA','CO','ME']; // technique / tactique / coordination / mental
const ET_SURFACES   = ['quart','demi','terrain','zone_libre','salle'];
const ET_SOURCES    = ['socle_asf','cree_meyrin','propose_coach'];
const ET_STATUTS    = ['brouillon','en_revue','valide','publie','archive'];

/** action de révision => statut cible. */
const ET_TRANSITIONS = [
    'soumis'            => 'en_revue',
    'valide'            => 'valide',
    'valide_avec_modif' => 'valide',
    'renvoye'           => 'brouillon',
    'rejete'            => 'archive',
    'publie'            => 'publie',
    'archive'           => 'archive',
    'restaure'          => 'brouillon',
];

/* ============================================================ PÉRIMÈTRES */

/** Tous les périmètres (pour l'admin, la config, les filtres). */
function et_perimetres_tous(): array
{
    return et_db()->query(
        'SELECT id, code, libelle, tranches_age, mode_libre, seuil_ouverture, actif
         FROM perimetres ORDER BY id'
    )->fetchAll();
}

/**
 * Périmètres de LECTURE de la personne.
 * Renvoie null = aucune restriction de périmètre (admin, DT, ou coach pas
 * encore rattaché à une équipe). Sinon la liste d'ids autorisés.
 */
function et_scope_perimetres_lecture(array $session): ?array
{
    $u = et_user_sync($session);
    if (in_array($u['profil'], ['admin', 'directeur_technique'], true)) {
        return null;
    }
    if ($u['profil'] === 'responsable_categorie') {
        return et_perimetres_de_validation($session);
    }
    // coach : périmètres de ses équipes rattachées
    $st = et_db()->prepare(
        'SELECT DISTINCT ec.perimetre_id
         FROM utilisateurs_equipes ue
         JOIN equipe_config ec ON ec.ref_id = ue.equipe_ref_id
         WHERE ue.utilisateur_id = ? AND ec.perimetre_id IS NOT NULL'
    );
    $st->execute([(int) $u['id']]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return $ids ?: null; // pas de rattachement connu : on ne restreint pas (mais coach = publié seulement)
}

/** Statuts qu'une personne a le droit de voir en liste. */
function et_scope_statuts(array $session): array
{
    return et_profil_courant($session) === 'coach' ? ['publie'] : ET_STATUTS;
}

/** La personne peut-elle valider / publier sur ce périmètre précis ? */
function et_peut_agir_sur_perimetre(array $session, int $perimetreId): bool
{
    $u = et_user_sync($session);
    if (in_array($u['profil'], ['admin', 'directeur_technique'], true)) return true;
    if ($u['profil'] === 'responsable_categorie') {
        return in_array($perimetreId, et_perimetres_de_validation($session), true);
    }
    return false;
}

/** Ids des périmètres rattachés à un exercice. */
function et_exercice_perimetres(int $exerciceId): array
{
    $st = et_db()->prepare('SELECT perimetre_id FROM exercice_perimetres WHERE exercice_id = ?');
    $st->execute([$exerciceId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * La personne a-t-elle autorité sur cet exercice (au moins un périmètre commun) ?
 * Un exercice sans aucun périmètre n'est administrable que par admin / DT.
 */
function et_a_autorite_sur_exercice(array $session, int $exerciceId): bool
{
    $u = et_user_sync($session);
    if (in_array($u['profil'], ['admin', 'directeur_technique'], true)) return true;
    if ($u['profil'] !== 'responsable_categorie') return false;
    $mien   = et_perimetres_de_validation($session);
    $sien   = et_exercice_perimetres($exerciceId);
    return (bool) array_intersect($mien, $sien);
}

/* ============================================================== LECTURE */

function et_exercice_get(int $id): ?array
{
    $st = et_db()->prepare('SELECT * FROM exercices WHERE id = ?');
    $st->execute([$id]);
    $ex = $st->fetch();
    if (!$ex) return null;
    $ex['perimetres'] = et_exercice_perimetres($id);
    foreach (['accents','tranches_age','filieres','materiel','geometrie'] as $j) {
        $ex[$j] = json_decode((string) $ex[$j], true) ?? ($j === 'geometrie' ? [] : []);
    }
    return $ex;
}

/**
 * Liste filtrée et paginée, déjà restreinte aux droits de la personne.
 * $filtres : statut, perimetre_id, phase_easi, categorie_technique, recherche,
 *            source, limit, offset.
 */
function et_exercices_list(array $session, array $filtres): array
{
    $db        = et_db();
    $statutsOk = et_scope_statuts($session);
    $perimOk   = et_scope_perimetres_lecture($session);

    $where = [];
    $args  = [];

    // statut : filtre demandé ∩ statuts autorisés
    $statutDemande = $filtres['statut'] ?? null;
    $statuts = $statutDemande && in_array($statutDemande, $statutsOk, true)
        ? [$statutDemande] : $statutsOk;
    $where[] = 'e.statut IN (' . implode(',', array_fill(0, count($statuts), '?')) . ')';
    array_push($args, ...$statuts);

    if ($perimOk !== null) {
        $where[] = 'EXISTS (SELECT 1 FROM exercice_perimetres ep
                            WHERE ep.exercice_id = e.id
                              AND ep.perimetre_id IN (' . implode(',', array_fill(0, count($perimOk), '?')) . '))';
        array_push($args, ...$perimOk);
    }
    if (!empty($filtres['perimetre_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM exercice_perimetres ep2
                            WHERE ep2.exercice_id = e.id AND ep2.perimetre_id = ?)';
        $args[] = (int) $filtres['perimetre_id'];
    }
    if (!empty($filtres['phase_easi']) && in_array($filtres['phase_easi'], ET_PHASES, true)) {
        $where[] = 'e.phase_easi = ?';
        $args[]  = $filtres['phase_easi'];
    }
    if (!empty($filtres['categorie_technique']) && in_array($filtres['categorie_technique'], ET_CATEGORIES, true)) {
        $where[] = 'e.categorie_technique = ?';
        $args[]  = $filtres['categorie_technique'];
    }
    if (!empty($filtres['source']) && in_array($filtres['source'], ET_SOURCES, true)) {
        $where[] = 'e.source = ?';
        $args[]  = $filtres['source'];
    }
    if (isset($filtres['recherche']) && trim((string) $filtres['recherche']) !== '') {
        $where[] = '(e.titre LIKE ? OR e.code LIKE ? OR e.objectif_principal LIKE ?)';
        $q = '%' . trim((string) $filtres['recherche']) . '%';
        array_push($args, $q, $q, $q);
    }

    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stC = $db->prepare("SELECT COUNT(*) FROM exercices e $sqlWhere");
    $stC->execute($args);
    $total = (int) $stC->fetchColumn();

    $limit  = min(max((int) ($filtres['limit'] ?? 25), 1), 100);
    $offset = max((int) ($filtres['offset'] ?? 0), 0);

    $st = $db->prepare(
        "SELECT e.id, e.code, e.titre, e.objectif_principal, e.categorie_technique,
                e.phase_easi, e.genre, e.joueurs_min, e.joueurs_max, e.duree_min,
                e.duree_max, e.source, e.statut, e.version, e.modifie_le, e.cree_le
         FROM exercices e $sqlWhere
         ORDER BY e.modifie_le DESC, e.id DESC
         LIMIT $limit OFFSET $offset"
    );
    $st->execute($args);
    $rows = $st->fetchAll();

    // périmètres de chaque ligne, en une requête
    $ids = array_column($rows, 'id');
    $byEx = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stp = $db->prepare("SELECT exercice_id, perimetre_id FROM exercice_perimetres
                             WHERE exercice_id IN ($ph)");
        $stp->execute($ids);
        foreach ($stp->fetchAll() as $r) {
            $byEx[(int) $r['exercice_id']][] = (int) $r['perimetre_id'];
        }
    }
    foreach ($rows as &$r) {
        $r['perimetres'] = $byEx[(int) $r['id']] ?? [];
    }
    unset($r);

    return ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'exercices' => $rows];
}

/** File de validation : exercices en_revue dans les périmètres de la personne. */
function et_file_validation(array $session): array
{
    $perims = et_perimetres_de_validation($session);
    if (!$perims) return [];
    $ph = implode(',', array_fill(0, count($perims), '?'));
    $st = et_db()->prepare(
        "SELECT DISTINCT e.id, e.code, e.titre, e.objectif_principal, e.categorie_technique,
                e.phase_easi, e.source, e.version, e.modifie_le,
                (SELECT commentaire FROM exercice_revisions r
                  WHERE r.exercice_id = e.id ORDER BY r.id DESC LIMIT 1) AS dernier_commentaire
         FROM exercices e
         JOIN exercice_perimetres ep ON ep.exercice_id = e.id
         WHERE e.statut = 'en_revue' AND ep.perimetre_id IN ($ph)
         ORDER BY e.modifie_le ASC"
    );
    $st->execute($perims);
    return $st->fetchAll();
}

function et_exercice_revisions(int $id): array
{
    $st = et_db()->prepare(
        "SELECT r.version, r.action, r.commentaire, r.date,
                COALESCE(u.nom, '') AS utilisateur
         FROM exercice_revisions r
         LEFT JOIN utilisateurs u ON u.id = r.utilisateur_id
         WHERE r.exercice_id = ? ORDER BY r.id ASC"
    );
    $st->execute([$id]);
    return $st->fetchAll();
}

/**
 * Tableau de bord d'avancement (admin / DT) : par périmètre, comptes par statut
 * et écart au seuil d'ouverture.
 */
function et_avancement(): array
{
    $rows = et_db()->query(
        "SELECT p.id, p.code, p.libelle, p.seuil_ouverture, p.mode_libre,
                COALESCE(SUM(e.statut = 'publie'),    0) AS publies,
                COALESCE(SUM(e.statut = 'valide'),    0) AS valides,
                COALESCE(SUM(e.statut = 'en_revue'),  0) AS en_revue,
                COALESCE(SUM(e.statut = 'brouillon'), 0) AS brouillons
         FROM perimetres p
         LEFT JOIN exercice_perimetres ep ON ep.perimetre_id = p.id
         LEFT JOIN exercices e ON e.id = ep.exercice_id
         GROUP BY p.id ORDER BY p.id"
    )->fetchAll();

    foreach ($rows as &$r) {
        $r['publies']    = (int) $r['publies'];
        $r['valides']    = (int) $r['valides'];
        $r['en_revue']   = (int) $r['en_revue'];
        $r['brouillons'] = (int) $r['brouillons'];
        $r['ecart_seuil'] = max(0, (int) $r['seuil_ouverture'] - $r['publies']);
        $r['ouvert']      = $r['publies'] >= (int) $r['seuil_ouverture'];
    }
    return $rows;
}

/* ============================================================= ÉCRITURE */

class EtErreur extends RuntimeException {}

/** Valide et normalise le corps d'un exercice. Lève EtErreur si invalide. */
function et_valider_payload(array $in, bool $creation): array
{
    $out = [];
    $req = fn($k) => trim((string) ($in[$k] ?? ''));

    if ($creation || isset($in['titre'])) {
        $out['titre'] = $req('titre');
        if ($out['titre'] === '') throw new EtErreur('Le titre est requis.');
    }
    $enum = function (string $k, array $vals, bool $obligatoire) use ($in, $creation, &$out) {
        if (!$creation && !array_key_exists($k, $in)) return;
        $v = (string) ($in[$k] ?? '');
        if ($v === '' && !$obligatoire) { $out[$k] = ''; return; }
        if (!in_array($v, $vals, true)) throw new EtErreur("Valeur invalide pour « $k » : $v");
        $out[$k] = $v;
    };
    $enum('categorie_technique', ET_CATEGORIES, $creation);
    $enum('phase_easi', ET_PHASES, $creation);
    $enum('genre', ['mixte','masculin','feminin'], false);
    $enum('surface_type', ET_SURFACES, false);

    // listes JSON
    foreach (['accents' => ET_ACCENTS, 'tranches_age' => et_tranches_age(), 'filieres' => et_filieres()] as $k => $vals) {
        if (!$creation && !array_key_exists($k, $in)) continue;
        $arr = $in[$k] ?? [];
        if (is_string($arr)) $arr = json_decode($arr, true) ?: [];
        if (!is_array($arr)) throw new EtErreur("Format invalide pour « $k ».");
        foreach ($arr as $v) {
            if (!in_array($v, $vals, true)) throw new EtErreur("Valeur « $v » non permise pour « $k ».");
        }
        $out[$k] = json_encode(array_values(array_unique($arr)));
    }

    // matériel : [{type, qte}]
    if ($creation || array_key_exists('materiel', $in)) {
        $mat = $in['materiel'] ?? [];
        if (is_string($mat)) $mat = json_decode($mat, true) ?: [];
        $clean = [];
        foreach ((array) $mat as $m) {
            $t = trim((string) ($m['type'] ?? ''));
            if ($t === '') continue;
            $clean[] = ['type' => $t, 'qte' => max(0, (int) ($m['qte'] ?? 0))];
        }
        $out['materiel'] = json_encode($clean);
    }

    // géométrie : doit être un objet JSON valide
    if ($creation || array_key_exists('geometrie', $in)) {
        $g = $in['geometrie'] ?? new stdClass();
        if (is_string($g)) {
            $dec = json_decode($g, true);
            if ($g !== '' && $dec === null) throw new EtErreur('Géométrie : JSON invalide.');
            $g = $dec ?? new stdClass();
        }
        $out['geometrie'] = json_encode($g ?: new stdClass());
    }

    // entiers
    foreach (['joueurs_min','joueurs_max','duree_min','duree_max'] as $k) {
        if (!$creation && !array_key_exists($k, $in)) continue;
        $out[$k] = max(0, (int) ($in[$k] ?? 0));
    }
    if (isset($out['joueurs_min'], $out['joueurs_max']) && $out['joueurs_max'] > 0
        && $out['joueurs_min'] > $out['joueurs_max']) {
        throw new EtErreur('joueurs_min ne peut pas dépasser joueurs_max.');
    }
    // réels
    foreach (['surface_largeur','surface_longueur'] as $k) {
        if (!$creation && !array_key_exists($k, $in)) continue;
        $out[$k] = max(0.0, (float) ($in[$k] ?? 0));
    }

    // textes libres
    foreach (['objectif_principal','description_deroulement','consignes_coach',
              'criteres_reussite','variante_facile','variante_difficile',
              'source_reference'] as $k) {
        if ($creation || array_key_exists($k, $in)) {
            $out[$k] = trim((string) ($in[$k] ?? ''));
        }
    }

    return $out;
}

function et_code_unique(string $prefixe = 'EX'): string
{
    $db = et_db();
    do {
        $code = $prefixe . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $st = $db->prepare('SELECT 1 FROM exercices WHERE code = ?');
        $st->execute([$code]);
    } while ($st->fetchColumn());
    return $code;
}

/** Journalise une révision. */
function et_journal(int $exerciceId, int $version, string $action, string $commentaire, ?int $userId): void
{
    et_db()->prepare(
        'INSERT INTO exercice_revisions (exercice_id, version, action, commentaire, utilisateur_id)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$exerciceId, $version, $action, $commentaire, $userId]);
}

/**
 * Création ou mise à jour d'un exercice.
 *   - coach : uniquement création d'une proposition (source=propose_coach, statut=en_revue).
 *   - responsable : uniquement dans ses périmètres.
 *   - DT / admin : partout.
 * Modification d'un exercice `publie` -> repasse en `en_revue`, version + 1.
 */
function et_exercice_save(array $session, array $in): array
{
    $db     = et_db();
    $u      = et_user_sync($session);
    $profil = $u['profil'];
    $id     = (int) ($in['id'] ?? 0);
    $creation = $id === 0;

    $perimetresDemandes = array_values(array_unique(array_map('intval', (array) ($in['perimetres'] ?? []))));

    /* -------- Autorisations -------- */
    if ($profil === 'coach') {
        if (!$creation) throw new EtErreur('Un coach ne peut que proposer un nouvel exercice.');
        if (!$perimetresDemandes) throw new EtErreur('Choisis au moins un périmètre pour ta proposition.');
    } elseif ($profil === 'responsable_categorie') {
        $miens = et_perimetres_de_validation($session);
        if ($creation) {
            $perimetresDemandes = array_values(array_intersect($perimetresDemandes, $miens));
            if (!$perimetresDemandes) throw new EtErreur('Assigne au moins un de tes périmètres.');
        } else {
            if (!et_a_autorite_sur_exercice($session, $id)) {
                throw new EtErreur('Cet exercice n\'est pas dans tes périmètres.');
            }
        }
    } elseif (!in_array($profil, ['admin','directeur_technique'], true)) {
        throw new EtErreur('Droit insuffisant.');
    }

    $data = et_valider_payload($in, $creation);
    $now  = date('c');

    if ($creation) {
        $data['code']        = trim((string) ($in['code'] ?? '')) ?: et_code_unique();
        $data['source']      = $profil === 'coach' ? 'propose_coach'
                             : (in_array($in['source'] ?? '', ET_SOURCES, true) ? $in['source'] : 'cree_meyrin');
        $data['statut']      = $profil === 'coach' ? 'en_revue' : 'brouillon';
        $data['version']     = 1;
        $data['cree_par']    = $u['erp_id'];
        $data['cree_le']     = $now;
        $data['modifie_par'] = $u['erp_id'];
        $data['modifie_le']  = $now;

        $cols = array_keys($data);
        $db->prepare(
            'INSERT INTO exercices (' . implode(',', $cols) . ') VALUES ('
            . rtrim(str_repeat('?,', count($cols)), ',') . ')'
        )->execute(array_values($data));
        $id = (int) $db->lastInsertId();

        $link = $db->prepare('INSERT OR IGNORE INTO exercice_perimetres (exercice_id, perimetre_id) VALUES (?, ?)');
        foreach ($perimetresDemandes as $pid) $link->execute([$id, $pid]);

        et_journal($id, 1,
            $data['statut'] === 'en_revue' ? 'soumis' : 'restaure',
            $profil === 'coach' ? 'Proposition d\'un coach.' : 'Création.',
            (int) $u['id']);

        return et_exercice_get($id);
    }

    /* -------- Mise à jour -------- */
    $actuel = et_exercice_get($id);
    if (!$actuel) throw new EtErreur('Exercice introuvable.');
    if ($actuel['statut'] === 'archive') {
        throw new EtErreur('Exercice archivé : restaure-le d\'abord.');
    }

    $data['modifie_par'] = $u['erp_id'];
    $data['modifie_le']  = $now;

    $repasseEnRevue = false;
    if ($actuel['statut'] === 'publie') {
        $data['statut']  = 'en_revue';
        $data['version'] = (int) $actuel['version'] + 1;
        $repasseEnRevue  = true;
    } elseif ($actuel['statut'] === 'valide') {
        $data['statut'] = 'en_revue';
        $repasseEnRevue = true;
    }

    $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
    $db->prepare("UPDATE exercices SET $set WHERE id = ?")
       ->execute([...array_values($data), $id]);

    // périmètres : seuls admin / DT / responsable (dans leur périmètre) peuvent les changer
    if (array_key_exists('perimetres', $in) && in_array($profil, ['admin','directeur_technique','responsable_categorie'], true)) {
        $cible = $perimetresDemandes;
        if ($profil === 'responsable_categorie') {
            // ne peut retirer/ajouter que ses propres périmètres ; les autres restent
            $miens   = et_perimetres_de_validation($session);
            $autres  = array_values(array_diff($actuel['perimetres'], $miens));
            $cible   = array_values(array_unique(array_merge($autres, array_intersect($cible, $miens))));
        }
        $db->prepare('DELETE FROM exercice_perimetres WHERE exercice_id = ?')->execute([$id]);
        $link = $db->prepare('INSERT OR IGNORE INTO exercice_perimetres (exercice_id, perimetre_id) VALUES (?, ?)');
        foreach ($cible as $pid) $link->execute([$id, (int) $pid]);
    }

    $v = $data['version'] ?? $actuel['version'];
    if ($repasseEnRevue) {
        et_journal($id, (int) $v, 'soumis',
            $actuel['statut'] === 'publie'
                ? 'Modification d\'un exercice publié : repassé en revue (v' . $v . ').'
                : 'Modification : repassé en revue.',
            (int) $u['id']);
    } else {
        et_journal($id, (int) $v, 'restaure', 'Modification.', (int) $u['id']);
    }

    return et_exercice_get($id);
}

/**
 * Transition de workflow.
 *   $action : soumis | valide | valide_avec_modif | renvoye | rejete | publie | archive | restaure
 *   $in     : commentaire, modifications (pour valide_avec_modif)
 */
function et_exercice_transition(array $session, int $id, string $action, array $in = []): array
{
    if (!isset(ET_TRANSITIONS[$action])) throw new EtErreur('Action inconnue.');
    $db  = et_db();
    $u   = et_user_sync($session);
    $ex  = et_exercice_get($id);
    if (!$ex) throw new EtErreur('Exercice introuvable.');

    $profil     = $u['profil'];
    $commentaire = trim((string) ($in['commentaire'] ?? ''));
    $cible      = ET_TRANSITIONS[$action];

    // Qui a le droit de faire quoi ?
    $besoinAutorite = in_array($action, ['valide','valide_avec_modif','renvoye','rejete','publie'], true);
    if ($besoinAutorite && !et_a_autorite_sur_exercice($session, $id)) {
        throw new EtErreur('Cet exercice n\'est pas dans tes périmètres.');
    }
    if ($action === 'soumis' && $profil === 'coach') {
        // un coach ne soumet que sa propre proposition encore en brouillon
        if ($ex['cree_par'] !== $u['erp_id'] || $ex['statut'] !== 'brouillon') {
            throw new EtErreur('Action non autorisée.');
        }
    } elseif ($action === 'restaure') {
        if (!in_array($profil, ['admin','directeur_technique','responsable_categorie'], true)
            || ($profil === 'responsable_categorie' && !et_a_autorite_sur_exercice($session, $id))) {
            throw new EtErreur('Droit insuffisant.');
        }
    } elseif ($action !== 'soumis' && !$besoinAutorite && $profil === 'coach') {
        throw new EtErreur('Droit insuffisant.');
    }

    // Transitions permises depuis l'état courant
    $depuis = [
        'soumis'            => ['brouillon'],
        'valide'            => ['en_revue'],
        'valide_avec_modif' => ['en_revue'],
        'renvoye'           => ['en_revue'],
        'rejete'            => ['en_revue','valide'],
        'publie'            => ['valide'],
        'archive'           => ['brouillon','en_revue','valide','publie'],
        'restaure'          => ['archive'],
    ];
    if (!in_array($ex['statut'], $depuis[$action], true)) {
        throw new EtErreur("Transition « $action » impossible depuis l'état « {$ex['statut']} ».");
    }
    if (in_array($action, ['renvoye','rejete'], true) && $commentaire === '') {
        throw new EtErreur('Un commentaire est obligatoire pour renvoyer ou rejeter.');
    }

    $version = (int) $ex['version'];

    // valide_avec_modif : on applique d'abord les modifications
    if ($action === 'valide_avec_modif' && !empty($in['modifications']) && is_array($in['modifications'])) {
        $patch = et_valider_payload($in['modifications'], false);
        if ($patch) {
            $patch['modifie_par'] = $u['erp_id'];
            $patch['modifie_le']  = date('c');
            $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($patch)));
            $db->prepare("UPDATE exercices SET $set WHERE id = ?")
               ->execute([...array_values($patch), $id]);
        }
    }

    $db->prepare('UPDATE exercices SET statut = ?, modifie_par = ?, modifie_le = ? WHERE id = ?')
       ->execute([$cible, $u['erp_id'], date('c'), $id]);

    $libelle = [
        'soumis' => 'Soumis pour validation.',
        'valide' => 'Validé.',
        'valide_avec_modif' => 'Validé avec modifications.',
        'renvoye' => 'Renvoyé pour correction.',
        'rejete'  => 'Rejeté.',
        'publie'  => 'Publié.',
        'archive' => 'Archivé.',
        'restaure'=> 'Restauré.',
    ][$action];
    et_journal($id, $version, $action, $commentaire !== '' ? $commentaire : $libelle, (int) $u['id']);

    return et_exercice_get($id);
}
