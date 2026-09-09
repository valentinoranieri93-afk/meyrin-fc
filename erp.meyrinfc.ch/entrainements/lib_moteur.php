<?php
/**
 * lib_moteur.php — Moteur de composition de séance, mode déterministe (sans IA).
 *
 * Principe (cahier des charges §7) : ASSEMBLAGE SOUS CONTRAINTES. Le moteur ne
 * fabrique rien, il puise dans la bibliothèque PUBLIÉE et assemble selon les
 * règles EASI. Il fonctionne entièrement sans IA ; la couche IA (étape 6) ne
 * fera qu'adapter et rédiger par-dessus ce que le moteur a choisi.
 *
 * Contraintes dures :
 *   - Structure EASI : échauffement -> analytique -> situatif -> intégré.
 *   - Football joué (situatif + intégré) >= 50 % de la durée totale. Le moteur
 *     REFUSE de produire une séance qui ne le respecte pas.
 *   - Filtres : tranche d'âge, filière, genre, nombre de joueurs, surface,
 *     statut = publié, périmètre de l'équipe.
 *   - Anti-répétition : pas d'exercice utilisé par cette équipe dans les 3
 *     dernières semaines, sauf pool trop petit (signalé au coach).
 *
 * Ordre de relâchement quand aucune séance conforme n'est possible (validé) :
 *   1. lever l'anti-répétition
 *   2. élargir la plage de joueurs (±2)
 *   3. autoriser une surface adjacente
 *   La règle des 50 % n'est jamais relâchée.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib_equipes.php';
require_once __DIR__ . '/lib_svg.php';

const ET_PHASES_ORDRE = ['echauffement', 'analytique', 'situatif', 'integre'];
const ET_PHASE_LABEL  = [
    'echauffement' => 'Échauffement', 'analytique' => 'Analytique',
    'situatif' => 'Situationnel', 'integre' => 'Jeu / intégré',
];

/** Surfaces qu'une surface disponible peut accueillir (compatibilité). */
const ET_SURFACE_COMPAT = [
    'salle'      => ['salle'],
    'zone_libre' => ['zone_libre', 'quart'],
    'quart'      => ['quart'],
    'demi'       => ['quart', 'demi'],
    'terrain'    => ['quart', 'demi', 'terrain'],
];
/** Surface adjacente (relâchement niveau 3). */
const ET_SURFACE_ADJ = ['quart' => 'demi', 'demi' => 'terrain', 'zone_libre' => 'quart'];

/* Étend EtErreur : les échecs de composition sont des messages pour l'utilisateur
   (pool insuffisant, équipe non configurée...), renvoyés en 422 par api.php. */
class EtMoteurErreur extends EtErreur {}

/**
 * Génère une séance (brouillon non persisté).
 *
 * $p : equipe_ref_id, date, nb_joueurs, surface, duree, accent (optionnel).
 * Renvoie ['seance' => [...méta...], 'blocs' => [...], 'avertissements' => [...]].
 */
function et_seance_generer(array $session, array $p): array
{
    $db     = et_db();
    $refId  = (string) ($p['equipe_ref_id'] ?? '');
    $equipe = et_equipe($refId);
    if (!$equipe) throw new EtMoteurErreur('Équipe inconnue.');
    $cfg = $equipe['config'];
    if (!$cfg['configure']) {
        throw new EtMoteurErreur('Cette équipe n\'est pas encore configurée (tranche d\'âge, filière, genre). Vois avec ton administrateur.');
    }

    $duree   = max(30, min(120, (int) ($p['duree'] ?? 75)));
    $nb      = max(2, (int) ($p['nb_joueurs'] ?? 12));
    $surface = in_array($p['surface'] ?? '', array_keys(ET_SURFACE_COMPAT), true) ? $p['surface'] : 'demi';
    $date    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['date'] ?? '')) ? $p['date'] : date('Y-m-d');
    $tranche = $cfg['tranche_age'];
    $accent  = (string) ($p['accent'] ?? (ET_ACCENT_DEFAUT[$tranche] ?? ''));
    $modeLibre = $cfg['mode'] === 'libre';

    /* --- Répartition EASI --- */
    $split = ET_EASI[et_easi_groupe($tranche)];
    $minutes = [];
    $cumul = 0;
    foreach (ET_PHASES_ORDRE as $ph) {
        $m = (int) round($split[$ph] / 100 * $duree);
        $minutes[$ph] = $m;
        $cumul += $m;
    }
    $minutes['integre'] += $duree - $cumul;   // le reste d'arrondi va au jeu

    /* --- Pool d'exercices publiés du périmètre de l'équipe --- */
    $perimId = $cfg['perimetre_id'];
    $pool = et_moteur_pool($perimId);
    if (!$pool) {
        throw new EtMoteurErreur('Aucun exercice publié pour le périmètre de cette équipe. La bibliothèque doit d\'abord être alimentée.');
    }

    /* --- Exercices récents de l'équipe (anti-répétition, 21 j) --- */
    $recent = [];
    $st = $db->prepare(
        "SELECT sb.exercice_id, MAX(s.date) AS d
         FROM seance_blocs sb JOIN seances s ON s.id = sb.seance_id
         WHERE s.equipe_id = ? AND s.date >= date('now', '-21 days') AND sb.exercice_id IS NOT NULL
         GROUP BY sb.exercice_id"
    );
    $st->execute([$refId]);
    foreach ($st->fetchAll() as $r) $recent[(int) $r['exercice_id']] = $r['d'];

    /* --- Composition phase par phase --- */
    $avert = [];
    $blocs = [];
    $utilises = [];
    $ordre = 0;
    $relacheAntiRep = false;

    // fusion des phases non remplies vers la suivante, football joué préservé
    $reste = 0;
    foreach (ET_PHASES_ORDRE as $ph) {
        $mPhase = $minutes[$ph] + $reste;
        $reste = 0;
        if ($mPhase <= 0) continue;

        $cands = et_moteur_candidats($pool, $ph, $tranche, $cfg, $nb, $surface, $modeLibre);
        $relax = 0;

        // Relâchements successifs
        while (!$cands && $relax < 3) {
            $relax++;
            if ($relax === 1) {
                $cands = et_moteur_candidats($pool, $ph, $tranche, $cfg, $nb, $surface, $modeLibre); // (anti-rep géré plus bas)
            } elseif ($relax === 2) {
                $cands = et_moteur_candidats($pool, $ph, $tranche, $cfg, $nb, $surface, $modeLibre, 2);
                if ($cands) $avert[] = "Phase « " . ET_PHASE_LABEL[$ph] . " » : plage de joueurs élargie (pool restreint).";
            } elseif ($relax === 3) {
                $sAdj = ET_SURFACE_ADJ[$surface] ?? $surface;
                $cands = et_moteur_candidats($pool, $ph, $tranche, $cfg, $nb, $sAdj, $modeLibre, 2);
                if ($cands) $avert[] = "Phase « " . ET_PHASE_LABEL[$ph] . " » : surface adjacente autorisée (pool restreint).";
            }
        }

        // Nombre de blocs pour la phase
        $nbBlocs = ($mPhase >= 22 && count($cands) >= 2 && $ph !== 'echauffement') ? 2 : 1;
        if (!$cands) {
            if (in_array($ph, ['situatif', 'integre'], true)) {
                throw new EtMoteurErreur(
                    'Impossible de composer une séance conforme : aucun exercice de jeu (' . ET_PHASE_LABEL[$ph]
                    . ') ne correspond, et le football joué doit représenter au moins la moitié de la séance. '
                    . 'Alimente la bibliothèque ou élargis les critères.'
                );
            }
            // échauffement / analytique introuvable : on reverse le temps sur la phase suivante
            $reste = $mPhase;
            $avert[] = "Phase « " . ET_PHASE_LABEL[$ph] . " » : aucun exercice disponible, temps reporté sur la suite.";
            continue;
        }

        $dureeBloc = intdiv($mPhase, $nbBlocs);
        for ($b = 0; $b < $nbBlocs; $b++) {
            $d = ($b === $nbBlocs - 1) ? $mPhase - $dureeBloc * ($nbBlocs - 1) : $dureeBloc;
            $choix = et_moteur_choisir($cands, $nb, $surface, $d, $accent, $recent, $utilises, $relacheAntiRep);
            if (!$choix) break;
            $utilises[$choix['id']] = true;
            if (isset($recent[$choix['id']]) && !$relacheAntiRep) {
                $relacheAntiRep = true;
            }
            $blocs[] = et_moteur_bloc(++$ordre, $ph, $choix, $d, $nb, $surface);
        }
    }

    if ($relacheAntiRep) {
        $avert[] = 'Anti-répétition levée : le pool était trop petit pour éviter tous les exercices des 3 dernières semaines.';
    }

    /* --- Contrôle final : football joué >= 50 % --- */
    $joue = 0;
    $tot = 0;
    foreach ($blocs as $bl) {
        $tot += $bl['duree'];
        if (in_array($bl['phase_easi'], ['situatif', 'integre'], true)) $joue += $bl['duree'];
    }
    if ($tot > 0 && $joue / $tot < 0.5) {
        throw new EtMoteurErreur('Séance rejetée : le football joué représenterait moins de la moitié du temps. Bibliothèque insuffisante en exercices de jeu.');
    }
    if (!$blocs) throw new EtMoteurErreur('Aucun exercice n\'a pu être sélectionné.');

    return [
        'seance' => [
            'equipe_ref_id'    => $refId,
            'equipe_nom'       => $equipe['nom'],
            'date'             => $date,
            'duree_totale'     => $tot,
            'nb_joueurs_prevus'=> $nb,
            'surface_disponible' => $surface,
            'accent_principal' => $accent,
            'mode'             => $modeLibre ? 'libre' : 'guide',
        ],
        'blocs'          => $blocs,
        'avertissements' => array_values(array_unique($avert)),
    ];
}

/* -------------------------------------------------------------- POOL / FILTRES */

/** Exercices publiés rattachés au périmètre (ou tous les publiés si périmètre nul). */
function et_moteur_pool(?int $perimId): array
{
    $db = et_db();
    if ($perimId) {
        $st = $db->prepare(
            "SELECT e.* FROM exercices e
             JOIN exercice_perimetres ep ON ep.exercice_id = e.id
             WHERE e.statut = 'publie' AND ep.perimetre_id = ?"
        );
        $st->execute([$perimId]);
    } else {
        $st = $db->query("SELECT * FROM exercices WHERE statut = 'publie'");
    }
    $out = [];
    foreach ($st->fetchAll() as $e) {
        foreach (['accents', 'tranches_age', 'filieres', 'geometrie'] as $j) {
            $e[$j] = json_decode((string) $e[$j], true) ?: [];
        }
        $out[$e['id']] = $e;
    }
    return $out;
}

/**
 * Candidats pour une phase : filtres tranche / filière / genre / joueurs /
 * surface. $tolJoueurs élargit la plage de ±N (relâchement 2).
 */
function et_moteur_candidats(array $pool, string $phase, string $tranche, array $cfg, int $nb, string $surface, bool $modeLibre, int $tolJoueurs = 0): array
{
    $surfOk = ET_SURFACE_COMPAT[$surface] ?? [$surface];
    $out = [];
    foreach ($pool as $e) {
        if ($e['phase_easi'] !== $phase) continue;
        // Tranche : si l'exercice cible des tranches, l'équipe doit y figurer.
        if (!empty($e['tranches_age']) && !in_array($tranche, $e['tranches_age'], true)) continue;
        // Filière (sauf mode libre, plus permissif).
        if (!$modeLibre && $cfg['filiere'] !== '' && !empty($e['filieres'])
            && !in_array($cfg['filiere'], $e['filieres'], true)) continue;
        // Genre : mixte accepte tout ; sinon l'exercice doit matcher ou être mixte.
        if ($cfg['genre'] !== 'mixte' && $e['genre'] !== 'mixte' && $e['genre'] !== $cfg['genre']) continue;
        // Joueurs.
        if ($nb + $tolJoueurs < (int) $e['joueurs_min'] || $nb - $tolJoueurs > (int) $e['joueurs_max']) continue;
        // Surface.
        if (!in_array($e['surface_type'], $surfOk, true)) continue;
        $out[] = $e;
    }
    return $out;
}

/**
 * Choix déterministe d'un exercice parmi les candidats.
 * Score = ajustement joueurs + surface + durée + accent + fraîcheur.
 * Départage : score, puis le moins récemment utilisé, puis id le plus bas.
 */
function et_moteur_choisir(array $cands, int $nb, string $surface, int $dureeBloc, string $accent, array $recent, array $deja, bool $antiRepLevee): ?array
{
    $best = null; $bestScore = -PHP_INT_MAX; $bestRecency = -1;
    foreach ($cands as $e) {
        if (isset($deja[$e['id']])) continue;
        $estRecent = isset($recent[$e['id']]);
        if ($estRecent && !$antiRepLevee) continue;   // 1er passage : on évite les récents

        $s = 0;
        if ($nb >= (int) $e['joueurs_min'] && $nb <= (int) $e['joueurs_max']) $s += 3;
        else $s -= 2;
        $s += ($e['surface_type'] === $surface) ? 2 : 1;
        if ($dureeBloc >= (int) $e['duree_min'] && $dureeBloc <= (int) $e['duree_max']) $s += 2;
        elseif ($dureeBloc >= (int) $e['duree_min'] - 5 && $dureeBloc <= (int) $e['duree_max'] + 5) $s += 1;
        if ($accent !== '' && in_array($accent, $e['accents'], true)) $s += 2;
        if (!$estRecent) $s += 2;

        // ancienneté de la dernière utilisation (jours), 999 si jamais
        $rec = $estRecent ? (int) ((time() - strtotime($recent[$e['id']])) / 86400) : 999;

        if ($s > $bestScore || ($s === $bestScore && ($rec > $bestRecency || ($rec === $bestRecency && $best && $e['id'] < $best['id'])))) {
            $best = $e; $bestScore = $s; $bestRecency = $rec;
        }
    }
    return $best;
}

/** Construit un bloc de séance à partir d'un exercice, avec instantané figé. */
function et_moteur_bloc(int $ordre, string $phase, array $e, int $duree, int $nb, string $surface): array
{
    $adaptations = [];
    if ($nb < (int) $e['joueurs_min'] || $nb > (int) $e['joueurs_max']) {
        $adaptations[] = "Prévu pour {$e['joueurs_min']}-{$e['joueurs_max']} joueurs, à adapter pour $nb.";
    }
    if ($e['surface_type'] !== $surface) {
        $adaptations[] = "Surface d'origine : " . $e['surface_type'] . ".";
    }
    if ($duree < (int) $e['duree_min'] || $duree > (int) $e['duree_max']) {
        $adaptations[] = "Durée d'origine : {$e['duree_min']}-{$e['duree_max']} min.";
    }
    return [
        'ordre'       => $ordre,
        'phase_easi'  => $phase,
        'phase_label' => ET_PHASE_LABEL[$phase],
        'duree'       => $duree,
        'exercice_id' => (int) $e['id'],
        'titre'       => $e['titre'],
        'objectif'    => $e['objectif_principal'],
        'description' => $e['description_deroulement'],
        'consignes'   => $e['consignes_coach'],
        'criteres'    => $e['criteres_reussite'],
        'materiel'    => json_decode((string) $e['materiel'], true) ?: [],
        'adaptations' => implode(' ', $adaptations),
        'snapshot'    => [
            'titre' => $e['titre'], 'geometrie' => $e['geometrie'],
            'description_deroulement' => $e['description_deroulement'],
            'consignes_coach' => $e['consignes_coach'],
            'criteres_reussite' => $e['criteres_reussite'],
            'materiel' => json_decode((string) $e['materiel'], true) ?: [],
        ],
    ];
}

/* ------------------------------------------------------------- PERSISTANCE */

/** Enregistre une séance générée (statut planifiee). */
function et_seance_save(array $session, array $in): array
{
    $db = et_db();
    $u  = et_user_sync($session);
    $s  = $in['seance'] ?? [];
    $blocs = $in['blocs'] ?? [];
    if (!$blocs) throw new EtMoteurErreur('Séance vide.');

    $refId = (string) ($s['equipe_ref_id'] ?? '');
    if (!et_equipe($refId)) throw new EtMoteurErreur('Équipe inconnue.');

    $tot = array_sum(array_map(fn($b) => max(0, (int) ($b['duree'] ?? 0)), $blocs));

    $db->prepare(
        'INSERT INTO seances (equipe_id, coach_id, date, duree_totale, nb_joueurs_prevus,
            surface_disponible, accent_principal, mode, statut, cree_le)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $refId, (int) $u['id'],
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($s['date'] ?? '')) ? $s['date'] : date('Y-m-d'),
        $tot, max(0, (int) ($s['nb_joueurs_prevus'] ?? 0)),
        (string) ($s['surface_disponible'] ?? ''), (string) ($s['accent_principal'] ?? ''),
        ($s['mode'] ?? 'guide') === 'libre' ? 'libre' : 'guide', 'planifiee', date('c'),
    ]);
    $seanceId = (int) $db->lastInsertId();

    $stB = $db->prepare(
        'INSERT INTO seance_blocs (seance_id, ordre, exercice_id, phase_easi, duree, adaptations, snapshot)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($blocs as $i => $b) {
        $stB->execute([
            $seanceId, (int) ($b['ordre'] ?? $i + 1),
            isset($b['exercice_id']) ? (int) $b['exercice_id'] : null,
            (string) ($b['phase_easi'] ?? ''), max(0, (int) ($b['duree'] ?? 0)),
            (string) ($b['adaptations'] ?? ''),
            json_encode($b['snapshot'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
        ]);
    }
    return et_seance_get($session, $seanceId);
}

function et_seance_get(array $session, int $id): ?array
{
    $db = et_db();
    $st = $db->prepare('SELECT * FROM seances WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) return null;

    $eq = et_equipe($s['equipe_id']);
    $s['equipe_nom'] = $eq['nom'] ?? $s['equipe_id'];

    $stB = $db->prepare('SELECT * FROM seance_blocs WHERE seance_id = ? ORDER BY ordre');
    $stB->execute([$id]);
    $blocs = [];
    foreach ($stB->fetchAll() as $b) {
        $snap = json_decode((string) $b['snapshot'], true) ?: [];
        $blocs[] = [
            'id' => (int) $b['id'], 'ordre' => (int) $b['ordre'],
            'phase_easi' => $b['phase_easi'], 'phase_label' => ET_PHASE_LABEL[$b['phase_easi']] ?? $b['phase_easi'],
            'duree' => (int) $b['duree'], 'exercice_id' => $b['exercice_id'] ? (int) $b['exercice_id'] : null,
            'adaptations' => $b['adaptations'],
            'titre' => $snap['titre'] ?? '', 'description' => $snap['description_deroulement'] ?? '',
            'consignes' => $snap['consignes_coach'] ?? '', 'criteres' => $snap['criteres_reussite'] ?? '',
            'materiel' => $snap['materiel'] ?? [], 'geometrie' => $snap['geometrie'] ?? [],
        ];
    }
    $s['blocs'] = $blocs;
    return $s;
}

function et_seances_list(array $session, ?string $refId = null): array
{
    $db = et_db();
    $u  = et_user_sync($session);
    $where = [];
    $args  = [];
    if ($u['profil'] === 'coach') { $where[] = 'coach_id = ?'; $args[] = (int) $u['id']; }
    if ($refId) { $where[] = 'equipe_id = ?'; $args[] = $refId; }
    $sql = 'SELECT id, equipe_id, date, duree_totale, nb_joueurs_prevus, accent_principal, statut, cree_le
            FROM seances' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY date DESC, id DESC LIMIT 60';
    $st = $db->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $eq = et_equipe($r['equipe_id']);
        $r['equipe_nom'] = $eq['nom'] ?? $r['equipe_id'];
        $stc = $db->prepare('SELECT COUNT(*) FROM seance_blocs WHERE seance_id = ?');
        $stc->execute([$r['id']]);
        $r['nb_blocs'] = (int) $stc->fetchColumn();
    }
    return $rows;
}

function et_seance_supprimer(array $session, int $id): void
{
    $db = et_db();
    $u  = et_user_sync($session);
    $st = $db->prepare('SELECT coach_id FROM seances WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new EtMoteurErreur('Séance introuvable.');
    if ($u['profil'] === 'coach' && (int) $row['coach_id'] !== (int) $u['id']) {
        throw new EtMoteurErreur('Tu ne peux supprimer que tes propres séances.');
    }
    $db->prepare('DELETE FROM seances WHERE id = ?')->execute([$id]); // seance_blocs en CASCADE
}
