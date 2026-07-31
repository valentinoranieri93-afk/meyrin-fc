<?php
/**
 * mfc_seed_club.php — Reprise de la structure sportive existante du module RH
 * vers le referentiel club de l'ERP (data/club_structure.json).
 *
 * A lancer UNE FOIS, avant de brancher les modules sur le referentiel. Les
 * equipes, categories et entraineurs saisis dans RH sont deja justes : les
 * ressaisir a la main serait long et source d'erreurs.
 *
 * CE QUE FAIT L'OUTIL :
 *  1. lit la base RH (saisons, categories, equipes, entraineur principal) ;
 *  2. ecrit le referentiel de l'ERP ;
 *  3. inscrit dans RH la colonne `ref_id` qui relie chaque ligne a son
 *     equivalent du referentiel.
 *
 * GARANTIES :
 *  - Aucune suppression de ligne, jamais. Aucune colonne RH modifiee a part
 *    `ref_id`, qui est ajoutee et n'existait pas avant.
 *  - Sauvegarde datee de la base RH et du referentiel avant toute ecriture.
 *  - Sans confirmation explicite, la page est en LECTURE SEULE : elle montre
 *    ce qu'elle ferait, sans rien ecrire.
 *  - Refus de reconstruire un referentiel deja peuple, sauf demande explicite :
 *    les identifiants d'equipe sont references par les modules, les regenerer
 *    detacherait des matchs et des affectations de leur equipe.
 *
 * Reserve aux administrateurs. A SUPPRIMER DU SERVEUR une fois la reprise faite.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/mfc_auth.php';
require_once __DIR__ . '/../lib/mfc_club.php';

$session = mfc_session();
if (!$session)                   { header('Location: ' . ERP_URL . '/'); exit; }
if (empty($session['settings'])) { http_response_code(403); exit('Reserve aux administrateurs.'); }

$confirm = ($_POST['confirm'] ?? '') === 'oui';
$rebuild = ($_POST['rebuild'] ?? '') === 'oui';
$notes   = [];
$errors  = [];

/* ------------------------------------------------------------- Helpers */

function seed_rh_db_path(): ?string {
    $base = mfc_app_path('rh');
    if (!$base) return null;
    $f = $base . '/data/rh.sqlite';
    if (is_file($f)) return $f;
    $g = glob($base . '/data/*.sqlite') ?: [];
    return $g[0] ?? null;
}

function seed_open(string $file): PDO {
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function seed_backup(string $file): string {
    $dest = $file . '.bak-' . date('Ymd-His');
    if (!copy($file, $dest)) throw new RuntimeException("Sauvegarde impossible : $file");
    return basename($dest);
}

function seed_has_col(PDO $pdo, string $table, string $col): bool {
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $c) {
        if ($c['name'] === $col) return true;
    }
    return false;
}

/** Comparaison de libelle insensible aux accents et a la casse. */
function seed_norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                      'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
}

/**
 * Le poste designe-t-il l'entraineur principal d'une equipe ?
 *
 * Les libelles reels melangent les orthographes ("Entraineur Assistant",
 * "Entraîneur Principal") : la comparaison ignore donc les accents, et exige
 * "principal" pour ne jamais confondre avec un adjoint ou un entraineur de
 * gardiens.
 */
function seed_is_head_coach(string $posteLabel): bool {
    $n = seed_norm($posteLabel);
    return str_contains($n, 'entra') && str_contains($n, 'principal');
}

/* ------------------------------------------------------------- Lecture RH */

$plan = null;
$rhFile = seed_rh_db_path();

if (!$rhFile) {
    $errors[] = "Base du module RH introuvable. L'outil ne peut rien reprendre.";
} else {
    try {
        $rh = seed_open($rhFile);

        $rhSeasons = $rh->query('SELECT * FROM seasons ORDER BY start_date')->fetchAll();
        $rhCats    = $rh->query('SELECT * FROM team_categories ORDER BY sort_order, name')->fetchAll();
        $rhTeams   = $rh->query('SELECT * FROM teams ORDER BY sort_order, name')->fetchAll();

        /* Entraineur principal par (saison, equipe). Un seul retenu par equipe :
           s'il y en a plusieurs, le premier suffit pour le referentiel, RH
           conserve la liste complete de son cote. */
        $coachSt = $rh->query('
            SELECT ea.season_id, ea.team_id, p.label AS poste,
                   TRIM(COALESCE(e.first_name, "") || " " || COALESCE(e.last_name, "")) AS coach
            FROM employee_assignments ea
            JOIN postes p ON p.id = ea.poste_id
            LEFT JOIN employees e ON e.id = ea.employee_id
            WHERE ea.active = 1 AND ea.team_id IS NOT NULL
        ');
        $coaches = [];
        foreach ($coachSt->fetchAll() as $r) {
            if (!seed_is_head_coach((string)$r['poste'])) continue;
            $coach = trim((string)$r['coach']);
            if ($coach === '') continue;
            $key = $r['season_id'] . '|' . $r['team_id'];
            if (!isset($coaches[$key])) $coaches[$key] = $coach;
        }

        if (!$rhSeasons) {
            $errors[] = 'Aucune saison dans le module RH : creez-en une avant de lancer la reprise.';
        }

        /* --------------------------------------------------- Construction */

        $existing  = mfc_club_read();
        $isPopulated = !empty($existing['teams']) || !empty($existing['seasons']);

        $data = mfc_club_empty();
        $data['seasons'] = $data['categories'] = $data['teams'] = [];
        $data['memberships'] = [];

        $seasonMap = [];   // id RH -> ref_id
        foreach ($rhSeasons as $s) {
            $ref = mfc_club_uid('sea');
            $seasonMap[(int)$s['id']] = $ref;
            $data['seasons'][] = [
                'id'         => $ref,
                'label'      => (string)$s['label'],
                'start_date' => (string)$s['start_date'],
                'end_date'   => (string)$s['end_date'],
            ];
        }

        $catMap = [];      // id RH -> ref_id
        foreach ($rhCats as $i => $c) {
            $ref = mfc_club_uid('cat');
            $catMap[(int)$c['id']] = $ref;
            $data['categories'][] = [
                'id'         => $ref,
                'name'       => (string)$c['name'],
                'sort_order' => (int)($c['sort_order'] ?? $i),
                'active'     => (bool)($c['active'] ?? 1),
            ];
        }

        $teamMap = [];     // id RH -> ref_id
        foreach ($rhTeams as $t) {
            $ref = mfc_club_uid('tm');
            $teamMap[(int)$t['id']] = $ref;
            $data['teams'][] = ['id' => $ref, 'created_at' => (string)($t['created_at'] ?? date('c'))];
        }

        /* Les equipes de RH sont permanentes : elles sont rattachees a chacune
           des saisons connues. C'est le referentiel qui rend ce rattachement
           modifiable saison par saison a partir de maintenant. */
        $withCoach = 0;
        foreach ($rhSeasons as $s) {
            $sref = $seasonMap[(int)$s['id']];
            $rows = [];
            foreach ($rhTeams as $i => $t) {
                $coach = $coaches[$s['id'] . '|' . $t['id']] ?? '';
                if ($coach !== '') $withCoach++;
                $rows[] = [
                    'team_id'     => $teamMap[(int)$t['id']],
                    'name'        => (string)$t['name'],
                    'category_id' => $catMap[(int)($t['category_id'] ?? 0)] ?? '',
                    'coach_name'  => $coach,
                    'sort_order'  => (int)($t['sort_order'] ?? $i),
                    'active'      => (bool)($t['active'] ?? 1),
                ];
            }
            $data['memberships'][$sref] = $rows;
        }

        $plan = [
            'rh_file'     => $rhFile,
            'seasons'     => $data['seasons'],
            'categories'  => $data['categories'],
            'teams'       => $rhTeams,
            'team_map'    => $teamMap,
            'cat_map'     => $catMap,
            'season_map'  => $seasonMap,
            'data'        => $data,
            'coaches'     => $coaches,
            'with_coach'  => $withCoach,
            'populated'   => $isPopulated,
        ];

        if ($isPopulated && !$rebuild) {
            $errors[] = 'Le referentiel contient deja des donnees. Reconstruire regenererait tous les '
                      . 'identifiants d\'equipe, ce qui detacherait les matchs d\'Arbitrage et les affectations '
                      . 'RH deja rattaches. Cochez la case de reconstruction si c\'est vraiment ce que vous voulez.';
        }

        /* --------------------------------------------------- Ecriture */

        if ($confirm && !$errors) {
            $bk = [];
            if (is_file(mfc_club_file())) $bk[] = seed_backup(mfc_club_file());
            $bk[] = seed_backup($rhFile);

            if (!mfc_club_write($data)) throw new RuntimeException('Ecriture du referentiel impossible.');
            $notes[] = 'Referentiel ecrit : ' . count($data['teams']) . ' equipe(s), '
                     . count($data['categories']) . ' categorie(s), ' . count($data['seasons']) . ' saison(s).';

            /* Lien retour dans RH : chaque ligne porte desormais l'identifiant
               du referentiel. C'est ce qui evite un rapprochement par nom, qui
               casserait des qu'une equipe est renommee dans l'ERP. */
            foreach ([['teams', $teamMap], ['team_categories', $catMap], ['seasons', $seasonMap]] as [$table, $map]) {
                if (!seed_has_col($rh, $table, 'ref_id')) {
                    $rh->exec("ALTER TABLE $table ADD COLUMN ref_id TEXT NOT NULL DEFAULT ''");
                }
                $st = $rh->prepare("UPDATE $table SET ref_id = ? WHERE id = ?");
                foreach ($map as $localId => $ref) $st->execute([$ref, $localId]);
                $notes[] = "RH.$table : " . count($map) . ' ligne(s) reliee(s).';
            }

            $notes[] = 'Sauvegardes creees : ' . implode(', ', $bk);
        }

    } catch (Throwable $e) {
        $errors[] = 'Erreur : ' . $e->getMessage();
    }
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reprise de la structure club — Meyrin FC</title>
<style>
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#F5F3EC;color:#1A1813;margin:0;padding:32px}
  .wrap{max-width:900px;margin:0 auto}
  h1{font-size:22px;margin:0 0 6px}
  .sub{color:#6E6C61;font-size:14px;margin-bottom:24px}
  .card{background:#fff;border:1px solid #E4E0D4;border-radius:14px;padding:24px;margin-bottom:18px}
  .ok{background:#E9F5EC;border-color:#BEDCC7;color:#1F5B33}
  .err{background:#FBE9E7;border-color:#F0C4BD;color:#A3281A}
  table{width:100%;border-collapse:collapse;font-size:13px;margin-top:12px}
  th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#8A8778;padding:8px 10px;border-bottom:1px solid #E4E0D4}
  td{padding:7px 10px;border-bottom:1px solid #F0EDE4}
  .muted{color:#8A8778}
  .btn{display:inline-block;background:#1A1813;color:#F5F3EC;border:none;border-radius:9px;padding:11px 20px;font-size:14px;font-weight:600;cursor:pointer}
  label{font-size:13.5px;display:block;margin-bottom:10px}
  code{background:#F0EDE4;border-radius:4px;padding:1px 5px;font-size:12.5px}
</style>
</head>
<body>
<div class="wrap">
  <h1>Reprise de la structure club</h1>
  <div class="sub">Recopie les categories, equipes et entraineurs du module RH vers le referentiel de l'ERP.</div>

  <?php foreach ($errors as $e): ?>
    <div class="card err"><?= h($e) ?></div>
  <?php endforeach ?>

  <?php foreach ($notes as $n): ?>
    <div class="card ok"><?= h($n) ?></div>
  <?php endforeach ?>

  <?php if ($notes): ?>
    <div class="card">
      <strong>Reprise terminee.</strong>
      <p>Ouvrez <a href="<?= h(ERP_URL) ?>/?tab=settings">Parametres &gt; Categories &amp; Equipes</a> pour verifier la liste,
      puis supprimez ce fichier du serveur.</p>
    </div>
  <?php elseif ($plan): ?>
    <div class="card">
      <strong>A reprendre depuis <code><?= h(basename($plan['rh_file'])) ?></code></strong>
      <p class="muted" style="font-size:13px">
        <?= count($plan['seasons']) ?> saison(s), <?= count($plan['categories']) ?> categorie(s),
        <?= count($plan['teams']) ?> equipe(s), dont <?= (int)$plan['with_coach'] ?> avec un entraineur principal identifie.
        Rien n'est ecrit tant que vous n'avez pas confirme.
      </p>

      <table>
        <thead><tr><th>Equipe</th><th>Categorie</th><th>Entraineur principal</th><th>Statut</th></tr></thead>
        <tbody>
        <?php
          $catById = [];
          foreach ($plan['categories'] as $c) $catById[$c['id']] = $c['name'];
          $firstSeason = $plan['seasons'][0]['id'] ?? null;
          foreach ($plan['data']['memberships'][$firstSeason] ?? [] as $m):
        ?>
          <tr>
            <td><?= h($m['name']) ?></td>
            <td class="muted"><?= h($catById[$m['category_id']] ?? '—') ?></td>
            <td><?= $m['coach_name'] !== '' ? h($m['coach_name']) : '<span class="muted">—</span>' ?></td>
            <td class="muted"><?= $m['active'] ? 'active' : 'inactive' ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <p class="muted" style="font-size:12.5px;margin-top:14px">
        Apercu de la saison <?= h($plan['seasons'][0]['label'] ?? '') ?>. Les autres saisons recoivent la meme liste d'equipes,
        avec l'entraineur enregistre pour chacune.
      </p>
    </div>

    <form method="post" class="card">
      <?php if ($plan['populated']): ?>
        <label><input type="checkbox" name="rebuild" value="oui"> Reconstruire un referentiel deja peuple (regenere tous les identifiants, detache les donnees des modules)</label>
      <?php endif ?>
      <label><input type="checkbox" name="confirm" value="oui" required> Je confirme la reprise. Une sauvegarde datee de la base RH et du referentiel sera creee avant ecriture.</label>
      <button class="btn" type="submit">Lancer la reprise</button>
    </form>
  <?php endif ?>
</div>
</body>
</html>
