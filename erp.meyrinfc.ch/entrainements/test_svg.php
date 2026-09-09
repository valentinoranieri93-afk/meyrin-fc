<?php
/**
 * test_svg.php — Page de contrôle du renderer de schémas.
 *
 * Affiche 10 géométries variées (types de zone, matériel, mouvements, densité
 * de joueurs) pour vérifier et itérer sur le rendu. Réservée à l'admin du
 * module. Ajouter `?print=1` pour voir la variante impression A5.
 *
 * Non liée à l'interface principale : c'est un outil de mise au point, à
 * retirer ou laisser, sans impact fonctionnel.
 */

declare(strict_types=1);

require_once __DIR__ . '/mfc_boot.php';
require_once __DIR__ . '/lib_entrainements.php';
require_once __DIR__ . '/lib_svg.php';

$user = mfc_require_login('entrainements');
if (et_profil_courant($user) !== 'admin') {
    http_response_code(403);
    exit('Réservé à l\'administrateur du module Entraînements.');
}

$print = isset($_GET['print']);
$anime = isset($_GET['anime']);
$opt   = $print ? ['print' => true] : ($anime ? ['anime' => true] : []);

/* ---------------------------------------------------- géométries de test */
$cas = [];

/* Deux exercices RÉELS, séquencés (ordre + acteur) : c'est cette qualité de
   géométrie qui rend un schéma lisible une fois animé. */

$cas['Passe et suit en triangle'] = [
    'zone' => ['type' => 'quart', 'longueur' => 24, 'largeur' => 24],
    'joueurs' => [
        ['x' => 3,  'y' => 3,  'equipe' => 'A', 'label' => '1'],
        ['x' => 21, 'y' => 3,  'equipe' => 'A', 'label' => '2'],
        ['x' => 12, 'y' => 21, 'equipe' => 'A', 'label' => '3'],
    ],
    'groupes' => [['x' => 1, 'y' => 8, 'nombre' => 3]],
    'materiel' => [
        ['type' => 'coupelle', 'x' => 3, 'y' => 3], ['type' => 'coupelle', 'x' => 21, 'y' => 3], ['type' => 'coupelle', 'x' => 12, 'y' => 21],
    ],
    'mouvements' => [
        ['from' => [3, 3],   'to' => [21, 3],  'type' => 'passe',   'ordre' => 1],
        ['from' => [3, 3],   'to' => [21, 3],  'type' => 'course',  'ordre' => 1, 'acteur' => '1'],
        ['from' => [21, 3],  'to' => [12, 21], 'type' => 'passe',   'ordre' => 2],
        ['from' => [21, 3],  'to' => [12, 21], 'type' => 'course',  'ordre' => 2, 'acteur' => '2'],
        ['from' => [12, 21], 'to' => [3, 3],   'type' => 'passe',   'ordre' => 3],
        ['from' => [12, 21], 'to' => [3, 3],   'type' => 'course',  'ordre' => 3, 'acteur' => '3'],
    ],
];

$cas['Conservation 4v2 (rondo)'] = [
    'zone' => ['type' => 'quart', 'longueur' => 20, 'largeur' => 20],
    'joueurs' => [
        ['x' => 3,  'y' => 3,  'equipe' => 'A', 'label' => '1'],
        ['x' => 17, 'y' => 3,  'equipe' => 'A', 'label' => '2'],
        ['x' => 17, 'y' => 17, 'equipe' => 'A', 'label' => '3'],
        ['x' => 3,  'y' => 17, 'equipe' => 'A', 'label' => '4'],
        ['x' => 9,  'y' => 8,  'equipe' => 'B', 'label' => '1'],
        ['x' => 11, 'y' => 12, 'equipe' => 'B', 'label' => '2'],
    ],
    'mouvements' => [
        ['from' => [3, 3],   'to' => [17, 3],  'type' => 'passe', 'ordre' => 1],
        ['from' => [17, 3],  'to' => [17, 17], 'type' => 'passe', 'ordre' => 2],
        ['from' => [17, 17], 'to' => [3, 17],  'type' => 'passe', 'ordre' => 3],
        ['from' => [3, 17],  'to' => [3, 3],   'type' => 'passe', 'ordre' => 4],
    ],
];

$cas['Terrain complet, bloc de jeu 8v8'] = [
    'zone' => ['type' => 'terrain', 'longueur' => 100, 'largeur' => 64],
    'joueurs' => array_merge(
        array_map(fn($i) => ['x' => 20 + $i * 8, 'y' => 18 + ($i % 2) * 10, 'equipe' => 'A', 'label' => (string) ($i + 1)], range(0, 7)),
        array_map(fn($i) => ['x' => 78 - $i * 8, 'y' => 20 + ($i % 2) * 12, 'equipe' => 'B', 'label' => (string) ($i + 1)], range(0, 7)),
        [['x' => 3, 'y' => 32, 'equipe' => 'gardien', 'label' => 'G'], ['x' => 97, 'y' => 32, 'equipe' => 'gardien', 'label' => 'G']]
    ),
    'materiel' => [['type' => 'but', 'x' => 0, 'y' => 32, 'orientation' => 0], ['type' => 'but', 'x' => 100, 'y' => 32, 'orientation' => 180]],
    'mouvements' => [
        ['from' => [28, 28], 'to' => [45, 22], 'type' => 'passe'],
        ['from' => [45, 22], 'to' => [62, 30], 'type' => 'course'],
    ],
];

$cas['Demi-terrain (exemple du cahier des charges)'] = [
    'zone' => ['type' => 'demi-terrain', 'largeur' => 50, 'longueur' => 34],
    'joueurs' => [['x' => 10, 'y' => 20, 'equipe' => 'A', 'label' => '1', 'role' => 'attaquant']],
    'materiel' => [['type' => 'cone', 'x' => 5, 'y' => 5], ['type' => 'but', 'x' => 0, 'y' => 17, 'orientation' => 0]],
    'mouvements' => [['from' => [10, 20], 'to' => [25, 20], 'type' => 'passe']],
];

$cas['Quart de terrain, rondo 5+2'] = [
    'zone' => ['type' => 'quart', 'longueur' => 24, 'largeur' => 24],
    'joueurs' => [
        ['x' => 4, 'y' => 4, 'equipe' => 'A', 'label' => '1'],
        ['x' => 20, 'y' => 4, 'equipe' => 'A', 'label' => '2'],
        ['x' => 22, 'y' => 12, 'equipe' => 'A', 'label' => '3'],
        ['x' => 20, 'y' => 20, 'equipe' => 'A', 'label' => '4'],
        ['x' => 4, 'y' => 20, 'equipe' => 'A', 'label' => '5'],
        ['x' => 11, 'y' => 11, 'equipe' => 'B', 'label' => '1'],
        ['x' => 14, 'y' => 14, 'equipe' => 'B', 'label' => '2'],
    ],
    'materiel' => array_map(fn($p) => ['type' => 'coupelle', 'x' => $p[0], 'y' => $p[1]], [[2, 2], [22, 2], [22, 22], [2, 22]]),
    'mouvements' => [
        ['from' => [4, 4], 'to' => [20, 4], 'type' => 'passe'],
        ['from' => [20, 4], 'to' => [20, 20], 'type' => 'passe'],
        ['from' => [20, 20], 'to' => [4, 20], 'type' => 'passe'],
    ],
];

$cas['Zone libre, ateliers de conduite'] = [
    'zone' => ['type' => 'zone_libre', 'longueur' => 30, 'largeur' => 20],
    'joueurs' => [
        ['x' => 3, 'y' => 5, 'equipe' => 'neutre', 'label' => '1'],
        ['x' => 3, 'y' => 15, 'equipe' => 'neutre', 'label' => '2'],
    ],
    'materiel' => [
        ['type' => 'echelle', 'x' => 8, 'y' => 5], ['type' => 'haie', 'x' => 16, 'y' => 5], ['type' => 'haie', 'x' => 20, 'y' => 5],
        ['type' => 'mannequin', 'x' => 16, 'y' => 15], ['type' => 'mannequin', 'x' => 22, 'y' => 15],
        ['type' => 'ballon', 'x' => 27, 'y' => 5], ['type' => 'ballon', 'x' => 27, 'y' => 15],
    ],
    'mouvements' => [
        ['from' => [3, 5], 'to' => [27, 5], 'type' => 'conduite'],
        ['from' => [3, 15], 'to' => [27, 15], 'type' => 'conduite'],
    ],
];

$cas['Salle, futsal 4v4'] = [
    'zone' => ['type' => 'salle', 'longueur' => 40, 'largeur' => 20],
    'joueurs' => array_merge(
        array_map(fn($i) => ['x' => 8 + $i * 4, 'y' => 6 + ($i % 2) * 8, 'equipe' => 'A', 'label' => (string) ($i + 1)], range(0, 3)),
        array_map(fn($i) => ['x' => 26 + $i * 4, 'y' => 6 + ($i % 2) * 8, 'equipe' => 'B', 'label' => (string) ($i + 1)], range(0, 3))
    ),
    'materiel' => [['type' => 'mini_but', 'x' => 0, 'y' => 10, 'orientation' => 0], ['type' => 'mini_but', 'x' => 40, 'y' => 10, 'orientation' => 180]],
    'mouvements' => [['from' => [12, 6], 'to' => [30, 6], 'type' => 'tir']],
];

$cas['Tous les types de mouvement'] = [
    'zone' => ['type' => 'quart', 'longueur' => 30, 'largeur' => 22],
    'joueurs' => [
        ['x' => 4, 'y' => 4, 'equipe' => 'A', 'label' => 'C'],
        ['x' => 4, 'y' => 11, 'equipe' => 'A', 'label' => 'P'],
        ['x' => 4, 'y' => 18, 'equipe' => 'A', 'label' => 'D'],
        ['x' => 4, 'y' => 22, 'equipe' => 'A', 'label' => 'T'],
    ],
    'materiel' => [['type' => 'but', 'x' => 30, 'y' => 11, 'orientation' => 180]],
    'mouvements' => [
        ['from' => [4, 4], 'to' => [26, 4], 'type' => 'course'],
        ['from' => [4, 11], 'to' => [26, 11], 'type' => 'passe'],
        ['from' => [4, 18], 'to' => [26, 18], 'type' => 'conduite'],
        ['from' => [4, 22], 'to' => [28, 13], 'type' => 'tir'],
    ],
];

$cas['Densité forte, 22 joueurs (décollision des labels)'] = [
    'zone' => ['type' => 'terrain', 'longueur' => 90, 'largeur' => 58],
    'joueurs' => array_merge(
        array_map(fn($i) => ['x' => 25 + ($i % 4) * 4, 'y' => 20 + intdiv($i, 4) * 4, 'equipe' => 'A', 'label' => (string) ($i + 1)], range(0, 10)),
        array_map(fn($i) => ['x' => 55 + ($i % 4) * 4, 'y' => 22 + intdiv($i, 4) * 4, 'equipe' => 'B', 'label' => (string) ($i + 1)], range(0, 10))
    ),
    'materiel' => [],
    'mouvements' => [],
];

$cas['Croisements de flèches'] = [
    'zone' => ['type' => 'quart', 'longueur' => 26, 'largeur' => 26],
    'joueurs' => [
        ['x' => 3, 'y' => 3, 'equipe' => 'A', 'label' => '1'],
        ['x' => 23, 'y' => 3, 'equipe' => 'A', 'label' => '2'],
        ['x' => 3, 'y' => 23, 'equipe' => 'A', 'label' => '3'],
        ['x' => 23, 'y' => 23, 'equipe' => 'A', 'label' => '4'],
    ],
    'materiel' => [],
    'mouvements' => [
        ['from' => [3, 3], 'to' => [23, 23], 'type' => 'passe'],
        ['from' => [23, 3], 'to' => [3, 23], 'type' => 'passe'],
        ['from' => [3, 23], 'to' => [23, 3], 'type' => 'course'],
        ['from' => [23, 23], 'to' => [3, 3], 'type' => 'course'],
    ],
];

$cas['Échauffement en cercle'] = [
    'zone' => ['type' => 'zone_libre', 'longueur' => 24, 'largeur' => 24],
    'joueurs' => array_map(function ($i) {
        $a = $i / 8 * 2 * M_PI;
        return ['x' => 12 + 9 * cos($a), 'y' => 12 + 9 * sin($a), 'equipe' => 'neutre', 'label' => (string) ($i + 1)];
    }, range(0, 7)),
    'materiel' => [['type' => 'ballon', 'x' => 12, 'y' => 12]],
    'mouvements' => [
        ['from' => [21, 12], 'to' => [12, 3], 'type' => 'passe'],
        ['from' => [12, 3], 'to' => [3, 12], 'type' => 'passe'],
    ],
];

$cas['Géométrie minimale (zone seule)'] = [
    'zone' => ['type' => 'demi'],
];

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Test renderer · Entraînements</title>
<style>
  body{font-family:Inter,-apple-system,sans-serif;background:#F6F4ED;color:#15140F;margin:0;padding:24px}
  h1{font-size:18px;margin:0 0 4px}
  .meta{font-size:13px;color:#6E6C61;margin-bottom:20px}
  .meta a{color:#15140F}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:18px}
  .card{background:#fff;border:1px solid #E9E6DC;border-radius:14px;padding:14px}
  .card h2{font-size:13px;font-weight:700;margin:0 0 10px}
  .w380{max-width:380px;margin:0 auto}
</style>
</head>
<body>
  <h1>Contrôle du renderer de schémas</h1>
  <div class="meta">
    Mode : <strong><?= $print ? 'impression A5' : ($anime ? 'animé (SVG SMIL)' : 'écran (contenu à 380 px)') ?></strong>
    &nbsp;·&nbsp;
    <a href="?">écran</a> · <a href="?anime=1">animé</a> · <a href="?print=1">impression</a>
    &nbsp;·&nbsp;<a href="/entrainements">retour au module</a>
  </div>
  <div class="grid">
    <?php foreach ($cas as $titre => $geo): ?>
      <div class="card">
        <h2><?= htmlspecialchars($titre) ?></h2>
        <div class="<?= $print ? '' : 'w380' ?>"><?= et_svg_schema($geo, $opt) ?></div>
      </div>
    <?php endforeach ?>
  </div>
</body>
</html>
