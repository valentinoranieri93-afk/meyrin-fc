<?php
/**
 * mfc_club.php — Référentiel club partagé (catégories, équipes, entraîneurs).
 *
 * SOURCE DE VÉRITÉ UNIQUE de la structure sportive du club. L'ERP l'édite
 * (Paramètres > Catégories & Équipes, réservé aux administrateurs), tous les
 * modules le lisent. Modifier une équipe dans l'ERP se répercute partout.
 *
 * Modèle de données, et la raison de sa forme :
 *
 *   - Une ÉQUIPE a une identité stable dans le temps (`tm_xxxxxxxx`), qui ne
 *     change jamais. C'est elle que les modules stockent en `ref_id`.
 *   - Son NOM, sa CATÉGORIE, son ENTRAÎNEUR et sa PRÉSENCE MÊME dépendent de
 *     la saison : ce sont des « appartenances » (memberships), une par saison.
 *
 *   Autrement dit : l'équipe qui s'appelait « Juniors C1 » en U15 la saison
 *   passée peut s'appeler « Juniors B2 » en U17 cette saison, c'est la même
 *   équipe. C'est ce qui permet au module RH de conserver l'historique de ses
 *   affectations (employee_assignments.team_id est en ON DELETE CASCADE : un
 *   identifiant d'équipe qui change de saison en saison effacerait des données
 *   de paie) tout en affichant le nom correct pour la saison consultée.
 *
 * Les CATÉGORIES, elles, sont une liste globale (comme aujourd'hui dans RH et
 * dans Arbitrage). Seul le rattachement d'une équipe à une catégorie varie
 * d'une saison à l'autre.
 *
 * Ce que ce référentiel ne contient PAS, volontairement : tout ce qui est
 * propre à un module (indemnités et cotisations RH, indemnité d'arbitrage et
 * matchs côté Arbitrage). Chaque module greffe ses données sur le `ref_id`.
 *
 * Usage :
 *   require_once '<racine ERP>/lib/mfc_club.php';   // ou via mfc_auth.php
 *   $season = mfc_club_resolve_season(null);        // saison courante
 *   foreach (mfc_club_teams($season) as $t) { ... } // équipes de cette saison
 */

if (defined('MFC_CLUB_LOADED')) return;
define('MFC_CLUB_LOADED', true);

if (!defined('ERP_ROOT')) define('ERP_ROOT', true);
require_once __DIR__ . '/../config.php';

/* ================================================================= STOCKAGE */

function mfc_club_file(): string {
    return DATA_DIR . 'club_structure.json';
}

/** Squelette d'un référentiel vide, utilisé au premier démarrage. */
function mfc_club_empty(): array {
    return [
        'version'     => 1,
        'updated_at'  => '',
        'seasons'     => [],
        'categories'  => [],
        'teams'       => [],
        'memberships' => new stdClass(),
    ];
}

function mfc_club_uid(string $prefix): string {
    return $prefix . '_' . substr(md5(uniqid('', true)), 0, 8);
}

/**
 * Lit le référentiel, normalisé et prêt à l'emploi.
 *
 * Ne crée jamais le fichier : un référentiel absent se lit comme un référentiel
 * vide, ce qui laisse les modules fonctionner sur leurs propres données tant
 * que l'amorçage (tools/mfc_seed_club.php) n'a pas été lancé.
 */
function mfc_club_read(): array {
    /* Cache en variable globale plutôt qu'en static locale : mfc_club_write()
       doit pouvoir l'invalider, sinon une lecture faite après une écriture dans
       la même requête renverrait l'état d'avant (l'écran ERP réafficherait la
       liste non modifiée juste après un enregistrement). */
    if (isset($GLOBALS['__mfc_club_cache'])) return $GLOBALS['__mfc_club_cache'];

    $f = mfc_club_file();
    $d = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];

    $out = [
        'version'     => (int) ($d['version'] ?? 1),
        'updated_at'  => (string) ($d['updated_at'] ?? ''),
        'seasons'     => [],
        'categories'  => [],
        'teams'       => [],
        'memberships' => [],
    ];

    foreach ($d['seasons'] ?? [] as $s) {
        if (empty($s['id'])) continue;
        $out['seasons'][] = [
            'id'         => (string) $s['id'],
            'label'      => (string) ($s['label'] ?? ''),
            'start_date' => (string) ($s['start_date'] ?? ''),
            'end_date'   => (string) ($s['end_date'] ?? ''),
        ];
    }
    /* Plus récente en tête : c'est l'ordre attendu par tous les sélecteurs. */
    usort($out['seasons'], fn($a, $b) => strcmp($b['start_date'], $a['start_date']));

    foreach ($d['categories'] ?? [] as $c) {
        if (empty($c['id'])) continue;
        $out['categories'][] = [
            'id'         => (string) $c['id'],
            'name'       => (string) ($c['name'] ?? ''),
            'sort_order' => (int) ($c['sort_order'] ?? 0),
            'active'     => (bool) ($c['active'] ?? true),
        ];
    }
    usort($out['categories'], fn($a, $b) => $a['sort_order'] <=> $b['sort_order'] ?: strcmp($a['name'], $b['name']));

    foreach ($d['teams'] ?? [] as $t) {
        if (empty($t['id'])) continue;
        $out['teams'][] = [
            'id'         => (string) $t['id'],
            'created_at' => (string) ($t['created_at'] ?? ''),
        ];
    }

    foreach ((array) ($d['memberships'] ?? []) as $seasonId => $rows) {
        $list = [];
        foreach ((array) $rows as $m) {
            if (empty($m['team_id'])) continue;
            $list[] = [
                'team_id'     => (string) $m['team_id'],
                'name'        => (string) ($m['name'] ?? ''),
                'category_id' => (string) ($m['category_id'] ?? ''),
                'coach_name'  => (string) ($m['coach_name'] ?? ''),
                'sort_order'  => (int) ($m['sort_order'] ?? 0),
                'active'      => (bool) ($m['active'] ?? true),
            ];
        }
        $out['memberships'][(string) $seasonId] = $list;
    }

    $GLOBALS['__mfc_club_cache'] = $out;
    return $out;
}

/** Vide le cache mémoire. Appelé automatiquement par mfc_club_write(). */
function mfc_club_flush(): void {
    unset($GLOBALS['__mfc_club_cache']);
}

/**
 * Écrit le référentiel de façon atomique.
 *
 * Écriture dans un fichier temporaire puis rename() : une écriture interrompue
 * ne laisse jamais un référentiel tronqué, que tous les modules liraient.
 */
function mfc_club_write(array $data): bool {
    $data['version']    = 1;
    $data['updated_at'] = date('c');
    if (empty($data['memberships'])) $data['memberships'] = new stdClass();

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $f   = mfc_club_file();
    $dir = dirname($f);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $tmp = $f . '.tmp' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!rename($tmp, $f)) { @unlink($tmp); return false; }

    mfc_club_flush();
    return true;
}

/* ================================================================== SAISONS */

function mfc_club_seasons(): array {
    return mfc_club_read()['seasons'];
}

function mfc_club_season(string $seasonId): ?array {
    foreach (mfc_club_seasons() as $s) {
        if ($s['id'] === $seasonId) return $s;
    }
    return null;
}

/**
 * Saison à utiliser quand l'appelant n'en impose pas.
 *
 * Celle qui couvre la date du jour en priorité (saison sportive 1er juillet -
 * 30 juin), sinon la plus récente. Renvoie null si aucune saison n'existe.
 */
function mfc_club_current_season_id(): ?string {
    $today   = date('Y-m-d');
    $seasons = mfc_club_seasons();
    foreach ($seasons as $s) {
        if ($s['start_date'] !== '' && $s['end_date'] !== ''
            && $s['start_date'] <= $today && $today <= $s['end_date']) {
            return $s['id'];
        }
    }
    return $seasons[0]['id'] ?? null;
}

/** Valide une saison demandée, ou retombe sur la saison courante. */
function mfc_club_resolve_season(?string $seasonId): ?string {
    if ($seasonId !== null && $seasonId !== '' && mfc_club_season($seasonId)) return $seasonId;
    return mfc_club_current_season_id();
}

/* =============================================================== CATÉGORIES */

function mfc_club_categories(bool $activeOnly = false): array {
    $rows = mfc_club_read()['categories'];
    if (!$activeOnly) return $rows;
    return array_values(array_filter($rows, fn($c) => $c['active']));
}

function mfc_club_category(string $categoryId): ?array {
    foreach (mfc_club_categories() as $c) {
        if ($c['id'] === $categoryId) return $c;
    }
    return null;
}

/* =================================================================== ÉQUIPES */

/**
 * Équipes d'une saison, à plat et prêtes à afficher.
 *
 * Chaque ligne porte le `ref_id` (identité stable, c'est lui que les modules
 * stockent) et les valeurs propres à la saison demandée. Une équipe absente de
 * la saison n'apparaît simplement pas : c'est ainsi qu'une équipe dissoute
 * disparaît des écrans sans qu'aucune donnée historique ne soit détruite.
 *
 * @param bool $activeOnly Exclure les équipes désactivées pour cette saison.
 */
function mfc_club_teams(?string $seasonId, bool $activeOnly = true): array {
    $seasonId = mfc_club_resolve_season($seasonId);
    if ($seasonId === null) return [];

    $data   = mfc_club_read();
    $cats   = [];
    foreach ($data['categories'] as $c) $cats[$c['id']] = $c;

    $out = [];
    foreach ($data['memberships'][$seasonId] ?? [] as $m) {
        if ($activeOnly && !$m['active']) continue;
        $cat = $cats[$m['category_id']] ?? null;
        $out[] = [
            'ref_id'         => $m['team_id'],
            'name'           => $m['name'],
            'category_ref_id'=> $m['category_id'],
            'category_name'  => $cat['name'] ?? '',
            'category_order' => $cat['sort_order'] ?? 9999,
            'coach_name'     => $m['coach_name'],
            'sort_order'     => $m['sort_order'],
            'active'         => $m['active'],
            'season_id'      => $seasonId,
        ];
    }

    usort($out, fn($a, $b) =>
        ($a['category_order'] <=> $b['category_order'])
        ?: ($a['sort_order'] <=> $b['sort_order'])
        ?: strcmp($a['name'], $b['name'])
    );

    return $out;
}

/** Une équipe précise pour une saison, ou null si elle n'y figure pas. */
function mfc_club_team(?string $seasonId, string $refId): ?array {
    foreach (mfc_club_teams($seasonId, false) as $t) {
        if ($t['ref_id'] === $refId) return $t;
    }
    return null;
}

/**
 * Toutes les saisons où une équipe apparaît, plus récente en tête.
 *
 * Sert aux modules qui doivent afficher une donnée historique (une affectation
 * RH d'une saison passée) sans se limiter à la saison consultée.
 */
function mfc_club_team_history(string $refId): array {
    $data = mfc_club_read();
    $out  = [];
    foreach ($data['seasons'] as $s) {
        foreach ($data['memberships'][$s['id']] ?? [] as $m) {
            if ($m['team_id'] === $refId) {
                $out[] = ['season' => $s, 'membership' => $m];
                break;
            }
        }
    }
    return $out;
}

/**
 * Nom d'affichage d'une équipe, quelle que soit la saison consultée.
 *
 * Cherche d'abord dans la saison demandée, puis retombe sur la saison la plus
 * récente où l'équipe existe. Évite qu'un écran affiche une ligne vide pour une
 * équipe qui n'est plus active cette saison mais dont on montre l'historique.
 */
function mfc_club_team_label(?string $seasonId, string $refId): string {
    $t = mfc_club_team($seasonId, $refId);
    if ($t) return $t['name'];
    $hist = mfc_club_team_history($refId);
    return $hist[0]['membership']['name'] ?? '';
}

/* =========================================== DÉDOUBLONNAGE DES MIROIRS
 *
 * Les modules recopient le référentiel dans leurs propres tables. Cette recopie
 * lisait puis écrivait sans verrou : au chargement d'une page, plusieurs
 * requêtes partent en parallèle (équipes, catégories, tableau de bord) et
 * chacune pouvait insérer la même équipe, personne n'ayant encore vu l'insertion
 * des autres. D'où des lignes en double, toutes actives et identiques.
 *
 * La parade est en deux temps : cette fonction répare l'existant, puis un index
 * unique (posé par chaque module juste après) rend la situation impossible à
 * reproduire, quel que soit l'entrelacement des requêtes.
 */

/**
 * Fusionne les lignes en double d'une table miroir.
 *
 * La ligne au plus petit identifiant est conservée. Tout ce qui pointe vers une
 * doublure lui est d'abord rattaché, ENSUITE seulement les doublures sont
 * supprimées : côté RH, employee_assignments.team_id est en ON DELETE CASCADE,
 * supprimer d'abord effacerait les postes, les montants et l'historique de paie
 * rattachés à la doublure.
 *
 * @param array $keyCols  Colonnes formant l'identité (ex. ['season_id','ref_id']).
 * @param array $reassign Références à faire suivre : [['table'=>…, 'col'=>…], …].
 * @param array $mergeMax Colonnes numériques dont on garde la plus grande valeur
 *                        (une saisie faite sur la doublure ne doit pas être perdue).
 * @return int Nombre de lignes supprimées.
 */
function mfc_club_dedup(PDO $pdo, string $table, array $keyCols, array $reassign, array $mergeMax = []): int
{
    $keyExpr = implode(', ', $keyCols);
    $where   = implode(' AND ', array_map(fn($c) => "$c IS NOT NULL AND $c != ''", ['ref_id']));

    $groups = $pdo->query(
        "SELECT $keyExpr, MIN(id) AS keep_id, COUNT(*) AS n
         FROM $table WHERE $where
         GROUP BY $keyExpr HAVING COUNT(*) > 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$groups) return 0;

    $deleted = 0;
    foreach ($groups as $g) {
        $keepId = (int) $g['keep_id'];
        $cond   = [];
        $vals   = [];
        foreach ($keyCols as $c) { $cond[] = "$c = ?"; $vals[] = $g[$c]; }
        $condSql = implode(' AND ', $cond);

        $dupSt = $pdo->prepare("SELECT * FROM $table WHERE $condSql AND id != ?");
        $dupSt->execute([...$vals, $keepId]);
        $dups = $dupSt->fetchAll(PDO::FETCH_ASSOC);
        if (!$dups) continue;
        $dupIds = array_map(fn($d) => (int) $d['id'], $dups);
        $ph     = implode(',', array_fill(0, count($dupIds), '?'));

        /* 1. Récupérer les valeurs saisies sur les doublures. */
        foreach ($mergeMax as $col) {
            $best = null;
            $keepSt = $pdo->prepare("SELECT $col FROM $table WHERE id = ?");
            $keepSt->execute([$keepId]);
            $best = (float) $keepSt->fetchColumn();
            foreach ($dups as $d) {
                if ((float) ($d[$col] ?? 0) > $best) $best = (float) $d[$col];
            }
            $pdo->prepare("UPDATE $table SET $col = ? WHERE id = ?")->execute([$best, $keepId]);
        }

        /* 2. Faire suivre les références, AVANT toute suppression. */
        foreach ($reassign as $r) {
            $pdo->prepare("UPDATE {$r['table']} SET {$r['col']} = ? WHERE {$r['col']} IN ($ph)")
                ->execute([$keepId, ...$dupIds]);
        }

        /* 3. Supprimer les doublures, désormais sans rien qui les référence. */
        $pdo->prepare("DELETE FROM $table WHERE id IN ($ph)")->execute($dupIds);
        $deleted += count($dupIds);
    }

    return $deleted;
}

/* ============================================================== DIAGNOSTIC */

/** Le référentiel a-t-il été amorcé ? Sert aux écrans à afficher un état vide utile. */
function mfc_club_is_empty(): bool {
    $d = mfc_club_read();
    return empty($d['seasons']) && empty($d['teams']);
}
