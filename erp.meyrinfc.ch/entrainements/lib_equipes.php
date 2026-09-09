<?php
/**
 * lib_equipes.php — Équipes du module, greffées sur le référentiel club de l'ERP.
 *
 * Aucune équipe n'est stockée ici : `equipe_config` référence l'identité stable
 * d'équipe du référentiel club (lib/mfc_club.php) et n'ajoute que ce qui est
 * propre à l'entraînement (les 3 axes, le mode, le périmètre). Nom, catégorie,
 * entraîneur et saison restent lus depuis le socle.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib_exercices.php';

/** Accent proposé par défaut selon la tranche d'âge (indicatif, modifiable). */
const ET_ACCENT_DEFAUT = [
    'G' => 'CO', 'F' => 'CO', 'E' => 'TE', 'D' => 'TE',
    'FE-12' => 'TE', 'FE-13' => 'TA', 'FE-14' => 'TA', 'M15' => 'TA',
    'C' => 'TA', 'B' => 'TA', 'A' => 'TA', 'ACTIFS' => 'TA', 'SENIORS' => 'TE',
];

/** Répartition EASI (%) de la durée, par groupe de tranches (cahier des charges §7). */
const ET_EASI = [
    'jeune'  => ['echauffement' => 20, 'analytique' => 20, 'situatif' => 25, 'integre' => 35], // G, F, E
    'milieu' => ['echauffement' => 15, 'analytique' => 20, 'situatif' => 30, 'integre' => 35], // D, FE-*, M15
    'grand'  => ['echauffement' => 15, 'analytique' => 15, 'situatif' => 30, 'integre' => 40], // C, B, A, ACTIFS
];

function et_easi_groupe(string $tranche): string
{
    if (in_array($tranche, ['G', 'F', 'E'], true)) return 'jeune';
    if (in_array($tranche, ['D', 'FE-12', 'FE-13', 'FE-14', 'M15'], true)) return 'milieu';
    return 'grand'; // C, B, A, ACTIFS, SENIORS
}

/** Saison courante du référentiel club (ou '' si le club n'a pas de saison). */
function et_saison_courante(): string
{
    if (function_exists('mfc_club_current_season_id')) {
        return (string) (mfc_club_current_season_id() ?? '');
    }
    return '';
}

/**
 * Équipes de la saison courante : référentiel club + config d'entraînement.
 * Une équipe jamais configurée apparaît avec `config.configure = 0`.
 */
function et_equipes(?string $saisonId = null): array
{
    $saisonId ??= et_saison_courante();
    $clubTeams = function_exists('mfc_club_teams') ? mfc_club_teams($saisonId ?: null, true) : [];

    $cfgRows = [];
    $st = et_db()->prepare('SELECT * FROM equipe_config WHERE saison_id = ?');
    $st->execute([$saisonId]);
    foreach ($st->fetchAll() as $r) $cfgRows[$r['ref_id']] = $r;

    $out = [];
    foreach ($clubTeams as $t) {
        $c = $cfgRows[$t['ref_id']] ?? null;
        $out[] = [
            'ref_id'        => $t['ref_id'],
            'nom'           => $t['name'],
            'categorie'     => $t['category_name'],
            'entraineur'    => $t['coach_name'],
            'saison_id'     => $saisonId,
            'config'        => [
                'tranche_age'  => $c['tranche_age'] ?? '',
                'filiere'      => $c['filiere'] ?? '',
                'genre'        => $c['genre'] ?? 'mixte',
                'mode'         => $c['mode'] ?? 'guide',
                'perimetre_id' => $c ? (int) $c['perimetre_id'] : null,
                'configure'    => $c ? (int) $c['configure'] : 0,
            ],
        ];
    }
    return $out;
}

function et_equipe(string $refId, ?string $saisonId = null): ?array
{
    foreach (et_equipes($saisonId) as $e) {
        if ($e['ref_id'] === $refId) return $e;
    }
    return null;
}

/** Enregistre la configuration d'entraînement d'une équipe (admin). */
function et_equipe_config_save(string $refId, string $saisonId, array $in, string $parErpId): array
{
    if ($refId === '') throw new EtErreur('Équipe manquante.');
    $tranche = (string) ($in['tranche_age'] ?? '');
    $filiere = (string) ($in['filiere'] ?? '');
    $genre   = (string) ($in['genre'] ?? 'mixte');
    $mode    = (string) ($in['mode'] ?? 'guide');
    $perim   = $in['perimetre_id'] ?? null;

    if ($tranche !== '' && !in_array($tranche, et_tranches_age(), true)) throw new EtErreur('Tranche d\'âge invalide.');
    if ($filiere !== '' && !in_array($filiere, et_filieres(), true)) throw new EtErreur('Filière invalide.');
    if (!in_array($genre, et_genres(), true)) throw new EtErreur('Genre invalide.');
    if (!in_array($mode, ['guide', 'libre'], true)) throw new EtErreur('Mode invalide.');
    if ($perim !== null && $perim !== '' && !et_db()->query('SELECT 1 FROM perimetres WHERE id = ' . (int) $perim)->fetchColumn()) {
        throw new EtErreur('Périmètre invalide.');
    }
    $perim = ($perim === null || $perim === '') ? null : (int) $perim;
    $configure = ($tranche !== '' && $filiere !== '' && $genre !== '') ? 1 : 0;

    et_db()->prepare(
        'INSERT INTO equipe_config (ref_id, saison_id, tranche_age, filiere, genre, mode, perimetre_id, configure, modifie_par, modifie_le)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(ref_id, saison_id) DO UPDATE SET
            tranche_age = excluded.tranche_age, filiere = excluded.filiere, genre = excluded.genre,
            mode = excluded.mode, perimetre_id = excluded.perimetre_id, configure = excluded.configure,
            modifie_par = excluded.modifie_par, modifie_le = excluded.modifie_le'
    )->execute([$refId, $saisonId, $tranche, $filiere, $genre, $mode, $perim, $configure, $parErpId, date('c')]);

    return et_equipe($refId, $saisonId);
}

/** Équipes rattachées à un coach (identités stables). */
function et_coach_equipes(int $utilisateurId): array
{
    $st = et_db()->prepare('SELECT equipe_ref_id FROM utilisateurs_equipes WHERE utilisateur_id = ?');
    $st->execute([$utilisateurId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function et_coach_equipes_set(int $utilisateurId, array $refIds): void
{
    $db = et_db();
    $db->prepare('DELETE FROM utilisateurs_equipes WHERE utilisateur_id = ?')->execute([$utilisateurId]);
    $ins = $db->prepare('INSERT OR IGNORE INTO utilisateurs_equipes (utilisateur_id, equipe_ref_id) VALUES (?, ?)');
    foreach (array_unique($refIds) as $r) {
        if ($r !== '') $ins->execute([$utilisateurId, (string) $r]);
    }
}

/** ref_ids des équipes du coach connecté (pour pré-remplir le générateur). */
function et_equipes_du_coach(array $session): array
{
    $u = et_user_sync($session);
    return et_coach_equipes((int) $u['id']);
}
