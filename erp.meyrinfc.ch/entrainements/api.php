<?php
/**
 * Entraînements Meyrin FC — API backend.
 * PHP 8.3 + PDO SQLite. Aucune dépendance externe. Hébergement mutualisé.
 *
 * Routeur : api.php?action=xxx  (même convention que les autres modules).
 *
 * LOT 2 : bibliothèque d'exercices et workflow de validation.
 *   - CRUD exercices, avec périmètres et règles de droits par profil
 *   - transitions : soumis | valide | valide_avec_modif | renvoye | rejete
 *     | publie | archive | restaure, toutes tracées dans exercice_revisions
 *   - file de validation filtrée sur les périmètres du responsable
 *   - tableau de bord d'avancement (admin / DT)
 *
 * Le moteur de séance, le renderer SVG et l'IA arrivent aux étapes 3 à 7.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/mfc_boot.php';
require_once __DIR__ . '/lib_exercices.php';
require_once __DIR__ . '/lib_svg.php';
require_once __DIR__ . '/lib_admin.php';
require_once __DIR__ . '/lib_moteur.php';
require_once __DIR__ . '/lib_ia.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$session = mfc_require_api('entrainements');

function jbad(int $code, string $msg, array $extra = []): never
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
function jok(array $data = []): never
{
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/** Corps JSON éventuel, fusionné avec le POST classique. */
function body(): array
{
    static $b = null;
    if ($b !== null) return $b;
    $b = $_POST;
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $j = json_decode(file_get_contents('php://input') ?: '', true);
        if (is_array($j)) $b = array_merge($b, $j);
    }
    return $b;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db     = et_db();
$user   = et_user_sync($session);
$profil = $user['profil'];

$peutValider = in_array($profil, ['admin', 'directeur_technique', 'responsable_categorie'], true);

try {
    switch ($action) {

        /* ------------------------------------------------ diagnostic (lot 1) */
        case 'ping':
            jok(['module' => 'entrainements', 'profil' => $profil, 'iaActive' => et_ia_active()]);

        case 'etat':
            if ($profil !== 'admin') jbad(403, 'Réservé à l\'administrateur du module.');
            $tables = $db->query(
                "SELECT name FROM sqlite_master WHERE type='table'
                 AND name NOT LIKE 'sqlite_%' ORDER BY name"
            )->fetchAll(PDO::FETCH_COLUMN);
            jok([
                'schema_version' => et_meta_get($db, 'schema_version'),
                'tables'         => $tables,
                'compte'         => [
                    'exercices'    => (int) $db->query('SELECT COUNT(*) FROM exercices')->fetchColumn(),
                    'seances'      => (int) $db->query('SELECT COUNT(*) FROM seances')->fetchColumn(),
                    'utilisateurs' => (int) $db->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn(),
                ],
                'avancement'  => et_avancement(),
                'referentiel' => [
                    'tranches_age' => et_tranches_age(),
                    'filieres'     => et_filieres(),
                    'genres'       => et_genres(),
                ],
            ]);

        /* --------------------------------------------------- référentiel */
        case 'referentiel':
            jok([
                'perimetres'          => et_perimetres_tous(),
                'perimetres_validation' => et_perimetres_de_validation($session),
                'tranches_age'        => et_tranches_age(),
                'filieres'            => et_filieres(),
                'genres'              => et_genres(),
                'categories'          => ET_CATEGORIES,
                'phases'              => ET_PHASES,
                'accents'             => ET_ACCENTS,
                'surfaces'            => ET_SURFACES,
                'sources'             => ET_SOURCES,
                'statuts'             => ET_STATUTS,
                'profil'              => $profil,
                'peutValider'         => $peutValider,
            ]);

        /* ---------------------------------------------------- exercices */
        case 'exercices':
            jok(et_exercices_list($session, [
                'statut'              => $_GET['statut'] ?? null,
                'perimetre_id'        => $_GET['perimetre_id'] ?? null,
                'phase_easi'          => $_GET['phase_easi'] ?? null,
                'categorie_technique' => $_GET['categorie_technique'] ?? null,
                'source'              => $_GET['source'] ?? null,
                'recherche'           => $_GET['q'] ?? null,
                'limit'               => $_GET['limit'] ?? 25,
                'offset'              => $_GET['offset'] ?? 0,
            ]));

        case 'exercice':
            $id = (int) ($_GET['id'] ?? 0);
            $ex = et_exercice_get($id);
            if (!$ex) jbad(404, 'Exercice introuvable.');
            // un coach ne voit qu'un exercice publié
            if ($profil === 'coach' && $ex['statut'] !== 'publie') {
                jbad(403, 'Cet exercice n\'est pas encore publié.');
            }
            $ex['revisions'] = et_exercice_revisions($id);
            jok(['exercice' => $ex]);

        case 'exercice_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jbad(405, 'POST requis.');
            $ex = et_exercice_save($session, body());
            jok(['exercice' => $ex, 'message' => 'Exercice enregistré.']);

        case 'exercice_transition':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jbad(405, 'POST requis.');
            $in  = body();
            $id  = (int) ($in['id'] ?? 0);
            $act = (string) ($in['transition'] ?? '');
            $ex  = et_exercice_transition($session, $id, $act, $in);
            jok(['exercice' => $ex, 'message' => 'Exercice mis à jour.']);

        case 'exercice_revisions':
            $id = (int) ($_GET['id'] ?? 0);
            if (!et_exercice_get($id)) jbad(404, 'Exercice introuvable.');
            jok(['revisions' => et_exercice_revisions($id)]);

        /* ------------------------------------------------------- schéma SVG */
        case 'schema':
            // Animé par défaut ; ?fixe=1 ou ?print=1 pour l'image statique (vignettes, PDF).
            $svgOpt = [];
            if (!empty($_GET['print']) || !empty(body()['print'])) $svgOpt['print'] = true;
            if (!empty($_GET['fixe']) || !empty(body()['fixe'])) $svgOpt['anime'] = false;
            if (isset($_GET['id'])) {
                $ex = et_exercice_get((int) $_GET['id']);
                if (!$ex) jbad(404, 'Exercice introuvable.');
                if ($profil === 'coach' && $ex['statut'] !== 'publie') {
                    jbad(403, 'Cet exercice n\'est pas encore publié.');
                }
                $geo = is_array($ex['geometrie']) ? $ex['geometrie'] : [];
            } else {
                if (!$peutValider) jbad(403, 'Aperçu réservé aux responsables et à la direction technique.');
                $g = body()['geometrie'] ?? null;
                if (is_string($g)) $g = json_decode($g, true);
                if (!is_array($g)) jbad(422, 'Géométrie manquante ou invalide.');
                $geo = $g;
            }
            header('Content-Type: image/svg+xml; charset=utf-8');
            header('Cache-Control: private, max-age=60');
            echo et_svg_schema($geo, $svgOpt);
            exit;

        /* --------------------------------------------------- validation */
        case 'file_validation':
            if (!$peutValider) jbad(403, 'Réservé aux responsables et à la direction technique.');
            jok(['file' => et_file_validation($session), 'perimetres' => et_perimetres_de_validation($session)]);

        case 'avancement':
            if (!in_array($profil, ['admin', 'directeur_technique'], true)) {
                jbad(403, 'Réservé à la direction technique.');
            }
            jok(['avancement' => et_avancement()]);

        /* ---------------------------------------------------------- admin */
        case 'admin_users':
            if ($profil !== 'admin') jbad(403, 'Réservé à l\'administrateur du module.');
            jok(['utilisateurs' => et_admin_utilisateurs(), 'profils' => ET_PROFILS]);

        case 'admin_set_profil':
            if ($profil !== 'admin') jbad(403, 'Réservé à l\'administrateur du module.');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jbad(405, 'POST requis.');
            $in = body();
            $u  = et_admin_set_profil($session, (string) ($in['erp_id'] ?? ''),
                (string) ($in['profil'] ?? ''), (array) ($in['perimetres'] ?? []));
            jok(['utilisateur' => $u, 'message' => 'Profil mis à jour.']);

        case 'admin_perimetre_save':
            if ($profil !== 'admin') jbad(403, 'Réservé à l\'administrateur du module.');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jbad(405, 'POST requis.');
            $in = body();
            jok([
                'perimetre' => et_admin_perimetre_save((int) ($in['id'] ?? 0), $in),
                'message'   => 'Périmètre enregistré.',
            ]);

        /* --------------------------------------------------------- équipes */
        case 'equipes':
            jok([
                'saison'          => et_saison_courante(),
                'equipes'         => et_equipes(),
                'mes_equipes'     => et_equipes_du_coach($session),
                'accent_defaut'   => ET_ACCENT_DEFAUT,
            ]);

        case 'equipe_config_save':
            if ($profil !== 'admin') jbad(403, 'Réservé à l\'administrateur du module.');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jbad(405, 'POST requis.');
            $in = body();
            jok([
                'equipe'  => et_equipe_config_save((string) ($in['ref_id'] ?? ''),
                    (string) ($in['saison_id'] ?? et_saison_courante()), $in, (string) ($user['erp_id'])),
                'message' => 'Équipe configurée.',
            ]);

        case 'coach_equipes_save':
            if ($profil !== 'admin') jbad(403, 'Réservé à l\'administrateur du module.');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jbad(405, 'POST requis.');
            $in  = body();
            $cur = et_db()->prepare('SELECT id FROM utilisateurs WHERE erp_id = ?');
            $cur->execute([(string) ($in['erp_id'] ?? '')]);
            $uid = $cur->fetchColumn();
            if (!$uid) jbad(404, 'Utilisateur introuvable.');
            et_coach_equipes_set((int) $uid, (array) ($in['equipes'] ?? []));
            jok(['message' => 'Équipes du coach mises à jour.']);

        /* -------------------------------------------------------- séances */
        case 'seance_generer':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jbad(405, 'POST requis.');
            $in    = body();
            $draft = et_seance_generer($session, $in);
            // Enrichissement IA : par défaut si l'IA est active, désactivable par avec_ia:false.
            if (($in['avec_ia'] ?? true) !== false) {
                $draft = et_ia_enrichir_seance($session, $draft);
            }
            jok($draft);

        case 'seance_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jbad(405, 'POST requis.');
            jok(['seance' => et_seance_save($session, body()), 'message' => 'Séance enregistrée.']);

        /* ------------------------------------------------------- budget IA */
        case 'ia_budgets':
            if ($profil !== 'admin') jbad(403, 'Réservé à l\'administrateur du module.');
            jok(['budgets' => et_ia_budgets_list(), 'ia_active' => et_ia_active(),
                 'resume' => et_ia_usage_resume()]);

        case 'ia_budget_save':
            if ($profil !== 'admin') jbad(403, 'Réservé à l\'administrateur du module.');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jbad(405, 'POST requis.');
            jok(['budgets' => et_ia_budget_save(body(), (string) $user['erp_id']), 'message' => 'Plafond enregistré.']);

        case 'ia_budget_supprimer':
            if ($profil !== 'admin') jbad(403, 'Réservé à l\'administrateur du module.');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jbad(405, 'POST requis.');
            et_ia_budget_supprimer((int) (body()['id'] ?? 0));
            jok(['budgets' => et_ia_budgets_list(), 'message' => 'Plafond supprimé.']);

        case 'ia_usage':
            if (!in_array($profil, ['admin', 'directeur_technique'], true)) jbad(403, 'Réservé à la direction technique.');
            jok(['resume' => et_ia_usage_resume($_GET['mois'] ?? null)]);

        case 'seances':
            jok(['seances' => et_seances_list($session, $_GET['equipe_ref_id'] ?? null)]);

        case 'seance':
            $s = et_seance_get($session, (int) ($_GET['id'] ?? 0));
            if (!$s) jbad(404, 'Séance introuvable.');
            $u = et_user_sync($session);
            if ($profil === 'coach' && (int) $s['coach_id'] !== (int) $u['id']) {
                jbad(403, 'Cette séance n\'est pas la tienne.');
            }
            jok(['seance' => $s]);

        case 'seance_supprimer':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jbad(405, 'POST requis.');
            et_seance_supprimer($session, (int) (body()['id'] ?? 0));
            jok(['message' => 'Séance supprimée.']);

        default:
            jbad(400, 'Action inconnue.', ['action' => $action]);
    }
} catch (EtErreur $e) {
    jbad(422, $e->getMessage());
} catch (Throwable $e) {
    error_log('[entrainements] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    jbad(500, 'Erreur interne.');
}
