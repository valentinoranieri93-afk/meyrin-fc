<?php
/**
 * lib_ia.php — Couche IA du module Entraînements.
 *
 * L'IA n'intervient que sur trois tâches (cahier des charges §7.1) :
 *   1. adapter chaque exercice au nombre réel de joueurs (note d'adaptation)
 *   2. rédiger les consignes de coaching contextualisées
 *   3. expliquer la cohérence de la séance en deux phrases
 *
 * Elle ne choisit jamais un exercice, n'en invente jamais. Le moteur
 * déterministe (lib_moteur.php) a déjà tout composé ; l'IA enrichit par-dessus.
 *
 * Sécurités :
 *   - Aucune clé -> aucun appel, la séance reste en mode déterministe.
 *   - Plafond mensuel atteint (club / périmètre / équipe) -> aucun appel,
 *     journalisé en `desactive_budget` (coût 0).
 *   - Timeout court -> abandon, mode déterministe. Le coach n'attend jamais.
 *   - Toute erreur d'appel ou de parsing -> mode déterministe.
 *
 * Appel HTTP brut (cURL) : pas de SDK Composer sur l'hébergement mutualisé.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib_moteur.php';

const ET_IA_ENDPOINT = 'https://api.anthropic.com/v1/messages';

/* ============================================================ COÛT / BUDGET */

/** Coût estimé d'un appel, en CHF, d'après les tarifs de config.local.php. */
function et_ia_cout(int $tokensIn, int $tokensOut): float
{
    $c = et_config();
    $usd = $tokensIn / 1_000_000 * (float) $c['ia_prix_input_usd_mtok']
         + $tokensOut / 1_000_000 * (float) $c['ia_prix_output_usd_mtok'];
    return round($usd * (float) $c['ia_taux_usd_chf'], 4);
}

/** Consommation IA du mois courant pour une portée donnée (CHF). */
function et_ia_consomme(string $mois, string $portee, ?string $ref): float
{
    $db = et_db();
    if ($portee === 'club') {
        $st = $db->prepare("SELECT COALESCE(SUM(cout_chf),0) FROM ia_usage WHERE mois = ? AND statut = 'ok'");
        $st->execute([$mois]);
    } elseif ($portee === 'perimetre') {
        $st = $db->prepare("SELECT COALESCE(SUM(cout_chf),0) FROM ia_usage WHERE mois = ? AND statut = 'ok' AND perimetre_id = ?");
        $st->execute([$mois, (int) $ref]);
    } else {
        $st = $db->prepare("SELECT COALESCE(SUM(cout_chf),0) FROM ia_usage WHERE mois = ? AND statut = 'ok' AND equipe_ref_id = ?");
        $st->execute([$mois, (string) $ref]);
    }
    return (float) $st->fetchColumn();
}

/**
 * État des plafonds applicables à une séance (équipe + périmètre + club).
 * Renvoie ['bloque' => bool, 'raison' => string, 'details' => [...]].
 */
function et_ia_budget_etat(?string $equipeRef, ?int $perimId): array
{
    $mois = date('Y-m');
    $db   = et_db();
    $details = [];
    $bloque  = false;
    $raison  = '';

    $st = $db->query("SELECT portee, portee_ref, plafond_mensuel_chf FROM ia_budgets WHERE actif = 1");
    foreach ($st->fetchAll() as $b) {
        $portee = $b['portee'];
        if ($portee === 'perimetre' && (int) $b['portee_ref'] !== (int) $perimId) continue;
        if ($portee === 'equipe' && (string) $b['portee_ref'] !== (string) $equipeRef) continue;

        $conso   = et_ia_consomme($mois, $portee, $b['portee_ref']);
        $plafond = (float) $b['plafond_mensuel_chf'];
        $restant = $plafond - $conso;
        $details[] = ['portee' => $portee, 'plafond' => $plafond, 'consomme' => round($conso, 2), 'restant' => round($restant, 2)];
        if ($restant <= 0.005) {
            $bloque = true;
            $raison = match ($portee) {
                'club'      => 'Plafond IA mensuel du club atteint.',
                'perimetre' => 'Plafond IA mensuel de ce périmètre atteint.',
                default     => 'Plafond IA mensuel de cette équipe atteint.',
            };
        }
    }
    return ['bloque' => $bloque, 'raison' => $raison, 'details' => $details];
}

/** Journalise un appel (ou un appel évité). */
function et_ia_log(array $row): int
{
    $db = et_db();
    $db->prepare(
        'INSERT INTO ia_usage (mois, seance_id, utilisateur_id, equipe_ref_id, perimetre_id,
            tache, modele, tokens_in, tokens_out, cout_chf, statut, detail)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        date('Y-m'), $row['seance_id'] ?? null, $row['utilisateur_id'] ?? null,
        (string) ($row['equipe_ref_id'] ?? ''), $row['perimetre_id'] ?? null,
        (string) ($row['tache'] ?? 'enrichir'), (string) ($row['modele'] ?? ''),
        (int) ($row['tokens_in'] ?? 0), (int) ($row['tokens_out'] ?? 0),
        (float) ($row['cout_chf'] ?? 0), (string) ($row['statut'] ?? 'ok'),
        (string) ($row['detail'] ?? ''),
    ]);
    return (int) $db->lastInsertId();
}

/* ================================================================ APPEL IA */

/**
 * Un appel Messages API. Renvoie ['text','in','out','cout'] ou null en cas
 * d'échec (clé absente, réseau, timeout, HTTP != 200, corps illisible).
 */
function et_ia_call(string $system, string $userContent, int $maxTokens): ?array
{
    $c   = et_config();
    $key = trim((string) $c['anthropic_api_key']);
    if ($key === '' || !function_exists('curl_init')) return null;

    $body = json_encode([
        'model'         => $c['ia_modele'],
        'max_tokens'    => $maxTokens,
        'thinking'      => ['type' => 'disabled'],   // latence : le coach attend
        'output_config' => ['effort' => 'low'],
        'system'        => $system,
        'messages'      => [['role' => 'user', 'content' => $userContent]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(ET_IA_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(3, (int) $c['ia_timeout']),
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $code !== 200) {
        error_log('[entrainements/ia] HTTP ' . $code . ' ' . $err . ' ' . substr((string) $raw, 0, 300));
        return null;
    }
    $d = json_decode((string) $raw, true);
    if (!is_array($d) || ($d['stop_reason'] ?? '') === 'refusal') return null;

    $text = '';
    foreach ($d['content'] ?? [] as $blk) {
        if (($blk['type'] ?? '') === 'text') $text .= $blk['text'];
    }
    $in  = (int) ($d['usage']['input_tokens'] ?? 0) + (int) ($d['usage']['cache_read_input_tokens'] ?? 0);
    $out = (int) ($d['usage']['output_tokens'] ?? 0);
    return ['text' => trim($text), 'in' => $in, 'out' => $out, 'cout' => et_ia_cout($in, $out)];
}

/** Extrait le premier objet JSON d'une chaîne (le modèle peut l'entourer de texte). */
function et_ia_json(string $s): ?array
{
    $a = strpos($s, '{');
    $b = strrpos($s, '}');
    if ($a === false || $b === false || $b <= $a) return null;
    $d = json_decode(substr($s, $a, $b - $a + 1), true);
    return is_array($d) ? $d : null;
}

/* ===================================================== ENRICHISSEMENT SÉANCE */

/**
 * Enrichit une séance générée : consignes contextualisées, note d'adaptation
 * par bloc, explication de cohérence. Ne touche pas au choix des exercices ni
 * à la géométrie. Renvoie le draft (modifié ou non) avec un bloc `ia`.
 */
function et_ia_enrichir_seance(array $session, array $draft): array
{
    $u        = et_user_sync($session);
    $s        = $draft['seance'];
    $equipe   = et_equipe($s['equipe_ref_id']);
    $perimId  = $equipe['config']['perimetre_id'] ?? null;

    $draft['ia'] = ['utilisee' => false, 'cout_chf' => 0.0, 'statut' => 'inactif', 'explication' => ''];

    if (!et_ia_active()) {
        $draft['ia']['statut'] = 'inactif';
        return $draft;
    }

    $bud = et_ia_budget_etat($s['equipe_ref_id'], $perimId ? (int) $perimId : null);
    if ($bud['bloque']) {
        et_ia_log([
            'utilisateur_id' => (int) $u['id'], 'equipe_ref_id' => $s['equipe_ref_id'],
            'perimetre_id' => $perimId, 'tache' => 'enrichir', 'statut' => 'desactive_budget',
            'detail' => $bud['raison'], 'modele' => et_config()['ia_modele'],
        ]);
        $draft['ia']['statut'] = 'budget';
        $draft['ia']['raison'] = $bud['raison'];
        return $draft;
    }

    /* --- construction du contexte pour le modèle --- */
    $blocsIn = [];
    foreach ($draft['blocs'] as $b) {
        $blocsIn[] = [
            'ordre'      => $b['ordre'],
            'phase'      => $b['phase_label'],
            'duree'      => $b['duree'],
            'titre'      => $b['titre'],
            'objectif'   => $b['objectif'] ?? '',
            'deroulement'=> mb_substr((string) ($b['description'] ?? ''), 0, 600),
            'consignes_origine' => mb_substr((string) ($b['consignes'] ?? ''), 0, 400),
        ];
    }
    $ctx = [
        'equipe'      => $equipe['nom'],
        'tranche'     => $equipe['config']['tranche_age'],
        'filiere'     => $equipe['config']['filiere'],
        'joueurs_reels' => $s['nb_joueurs_prevus'],
        'surface'     => $s['surface_disponible'],
        'duree_totale'=> $s['duree_totale'],
        'accent'      => $s['accent_principal'],
        'blocs'       => $blocsIn,
    ];

    $system = <<<TXT
Tu es adjoint d'un entraîneur du FC Meyrin, club suisse. On te donne une séance
déjà composée par un moteur (exercices choisis dans une bibliothèque validée par
la direction technique). Tu n'en changes RIEN : ni les exercices, ni l'ordre, ni
les durées. Ton rôle est seulement :
1. rédiger pour chaque bloc des consignes de coaching courtes, concrètes,
   adaptées à la tranche d'âge et au nombre RÉEL de joueurs ;
2. donner une note d'adaptation par bloc si le nombre réel de joueurs ou la
   surface impose de modifier le format (ex : passer un 8v8 en 5v5, réduire
   l'espace), sinon chaîne vide ;
3. expliquer en deux phrases maximum la cohérence globale de la séance.

Écris en français, ton direct de terrain, pas de blabla. N'emploie aucune balise
XML ni Markdown. Réponds UNIQUEMENT avec un objet JSON de cette forme :
{"explication":"...","blocs":[{"ordre":1,"consignes":"...","adaptation":"..."}]}
TXT;

    $res = et_ia_call($system, "Séance à enrichir :\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 1400);

    if (!$res) {
        et_ia_log([
            'utilisateur_id' => (int) $u['id'], 'equipe_ref_id' => $s['equipe_ref_id'],
            'perimetre_id' => $perimId, 'tache' => 'enrichir', 'statut' => 'echec',
            'detail' => 'Appel IA indisponible', 'modele' => et_config()['ia_modele'],
        ]);
        $draft['ia']['statut'] = 'echec';
        return $draft;
    }

    $usageId = et_ia_log([
        'utilisateur_id' => (int) $u['id'], 'equipe_ref_id' => $s['equipe_ref_id'],
        'perimetre_id' => $perimId, 'tache' => 'enrichir', 'statut' => 'ok',
        'modele' => et_config()['ia_modele'],
        'tokens_in' => $res['in'], 'tokens_out' => $res['out'], 'cout_chf' => $res['cout'],
    ]);

    $parsed = et_ia_json($res['text']) ?? [];
    $parBloc = [];
    foreach ($parsed['blocs'] ?? [] as $pb) {
        $parBloc[(int) ($pb['ordre'] ?? 0)] = $pb;
    }
    foreach ($draft['blocs'] as &$b) {
        $pb = $parBloc[$b['ordre']] ?? null;
        if ($pb) {
            if (!empty($pb['consignes']))   $b['consignes']   = trim((string) $pb['consignes']);
            if (!empty($pb['adaptation'])) {
                $b['adaptations'] = trim(($b['adaptations'] ? $b['adaptations'] . ' ' : '') . (string) $pb['adaptation']);
            }
        }
    }
    unset($b);

    $draft['ia'] = [
        'utilisee'    => true,
        'statut'      => 'ok',
        'cout_chf'    => $res['cout'],
        'explication' => trim((string) ($parsed['explication'] ?? '')),
        'usage_id'    => $usageId,
    ];
    return $draft;
}

/* ============================================================ ADMIN BUDGETS */

function et_ia_budgets_list(): array
{
    $rows = et_db()->query('SELECT * FROM ia_budgets ORDER BY portee, portee_ref')->fetchAll();
    $mois = date('Y-m');
    foreach ($rows as &$r) {
        $r['consomme_mois'] = round(et_ia_consomme($mois, $r['portee'], $r['portee_ref']), 2);
    }
    return $rows;
}

function et_ia_budget_save(array $in, string $parErpId): array
{
    $portee = (string) ($in['portee'] ?? '');
    if (!in_array($portee, ['club', 'perimetre', 'equipe'], true)) throw new EtErreur('Portée invalide.');
    $ref = $portee === 'club' ? null : (string) ($in['portee_ref'] ?? '');
    if ($portee !== 'club' && ($ref === '' || $ref === null)) throw new EtErreur('Cible du plafond manquante.');
    $plafond = max(0.0, (float) ($in['plafond_mensuel_chf'] ?? 0));
    $actif   = array_key_exists('actif', $in) ? (int) (bool) $in['actif'] : 1;

    $db = et_db();
    $st = $db->prepare('SELECT id FROM ia_budgets WHERE portee = ? AND IFNULL(portee_ref, \'\') = ?');
    $st->execute([$portee, (string) $ref]);
    $id = $st->fetchColumn();
    if ($id) {
        $db->prepare('UPDATE ia_budgets SET plafond_mensuel_chf = ?, actif = ?, modifie_par = ?, modifie_le = ? WHERE id = ?')
           ->execute([$plafond, $actif, $parErpId, date('c'), (int) $id]);
    } else {
        $db->prepare('INSERT INTO ia_budgets (portee, portee_ref, plafond_mensuel_chf, actif, modifie_par) VALUES (?, ?, ?, ?, ?)')
           ->execute([$portee, $ref, $plafond, $actif, $parErpId]);
    }
    return et_ia_budgets_list();
}

function et_ia_budget_supprimer(int $id): void
{
    et_db()->prepare('DELETE FROM ia_budgets WHERE id = ?')->execute([$id]);
}

/** Synthèse de consommation du mois : total + par périmètre + par équipe. */
function et_ia_usage_resume(?string $mois = null): array
{
    $mois = $mois ?: date('Y-m');
    $db = et_db();
    $total = (float) $db->query("SELECT COALESCE(SUM(cout_chf),0) FROM ia_usage WHERE mois = " . $db->quote($mois) . " AND statut = 'ok'")->fetchColumn();
    $appels = (int) $db->query("SELECT COUNT(*) FROM ia_usage WHERE mois = " . $db->quote($mois) . " AND statut = 'ok'")->fetchColumn();
    $bloques = (int) $db->query("SELECT COUNT(*) FROM ia_usage WHERE mois = " . $db->quote($mois) . " AND statut = 'desactive_budget'")->fetchColumn();

    $st = $db->prepare(
        "SELECT p.libelle, COALESCE(SUM(iu.cout_chf),0) AS cout, COUNT(iu.id) AS n
         FROM ia_usage iu JOIN perimetres p ON p.id = iu.perimetre_id
         WHERE iu.mois = ? AND iu.statut = 'ok' GROUP BY p.id ORDER BY cout DESC"
    );
    $st->execute([$mois]);

    return [
        'mois'       => $mois,
        'total_chf'  => round($total, 2),
        'appels'     => $appels,
        'bloques'    => $bloques,
        'par_perimetre' => array_map(fn($r) => ['libelle' => $r['libelle'], 'cout' => round((float) $r['cout'], 2), 'n' => (int) $r['n']], $st->fetchAll()),
    ];
}
