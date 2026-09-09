<?php
/**
 * seed/seed_exercices.php — Amorçage de la bibliothèque d'exercices.
 *
 * SÉPARÉ du code applicatif : on peut le relancer, le compléter ou le
 * régénérer sans toucher au module. Il ne modifie JAMAIS un exercice déjà
 * présent (repérage par `code`), il ne fait qu'insérer ce qui manque.
 *
 * Tous les exercices sont insérés en statut `en_revue`, jamais `publie`
 * directement : la direction technique valide, périmètre par périmètre.
 *
 * LOT 1 : le contenu est vide. Les lots d'exercices (30 à 40 par périmètre,
 * proportions EASI du §11 du cahier des charges) seront ajoutés à l'étape 8,
 * dans des fichiers `seed/lots/<perimetre>.php` chargés ci-dessous.
 *
 * Usage :
 *   - en ligne de commande :  php seed/seed_exercices.php
 *   - depuis un navigateur  :  /entrainements/seed/seed_exercices.php
 *                              (réservé au profil admin du module)
 */

declare(strict_types=1);

$estCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/../lib_db.php';

if (!$estCli) {
    /* Accès web : réservé à l'admin du module. En CLI, pas d'auth (accès disque). */
    require_once __DIR__ . '/../mfc_boot.php';
    $user = mfc_require_login('entrainements');
    require_once __DIR__ . '/../lib_entrainements.php';
    if (et_profil_courant($user) !== 'admin') {
        http_response_code(403);
        exit('Réservé à l\'administrateur du module Entraînements.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$db = et_db();

function ecrire(string $ligne): void
{
    echo $ligne . "\n";
}

ecrire('== Amorçage bibliothèque Entraînements ==');
ecrire('Base : ' . ET_DB_FILE);
ecrire('Schéma version : ' . (et_meta_get($db, 'schema_version') ?? '?'));
ecrire('');

/* ------------------------------------------------------------------ Périmètres */
$perims = $db->query('SELECT id, code, libelle FROM perimetres ORDER BY id')->fetchAll();
ecrire('Périmètres (' . count($perims) . ') :');
foreach ($perims as $p) {
    $n = (int) $db->query(
        'SELECT COUNT(*) FROM exercice_perimetres ep
         JOIN exercices e ON e.id = ep.exercice_id
         WHERE ep.perimetre_id = ' . (int) $p['id']
    )->fetchColumn();
    $publies = (int) $db->query(
        'SELECT COUNT(*) FROM exercice_perimetres ep
         JOIN exercices e ON e.id = ep.exercice_id
         WHERE ep.perimetre_id = ' . (int) $p['id'] . " AND e.statut = 'publie'"
    )->fetchColumn();
    ecrire(sprintf('  %-20s %-46s  %2d exercice(s), %d publié(s)',
        $p['code'], $p['libelle'], $n, $publies));
}
ecrire('');

/* ------------------------------------------------------------- Chargement des lots */
/**
 * Insère un exercice s'il n'existe pas déjà (repérage par `code`), le rattache
 * à ses périmètres, et journalise la soumission. Renvoie true si créé.
 */
function seed_inserer_exercice(PDO $db, array $ex, array $codesPerimetres): bool
{
    $st = $db->prepare('SELECT id FROM exercices WHERE code = ?');
    $st->execute([$ex['code']]);
    if ($st->fetchColumn()) return false;

    $champs = [
        'code','titre','objectif_principal','categorie_technique','accents',
        'phase_easi','tranches_age','filieres','genre','joueurs_min','joueurs_max',
        'surface_type','surface_largeur','surface_longueur','duree_min','duree_max','materiel',
        'description_deroulement','consignes_coach','criteres_reussite',
        'variante_facile','variante_difficile','geometrie','source','source_reference',
    ];
    $defauts = ['source' => 'cree_meyrin', 'source_reference' => '', 'objectif_principal' => '',
        'genre' => 'mixte', 'surface_type' => 'quart', 'categorie_technique' => 'technique',
        'phase_easi' => 'analytique', 'joueurs_min' => 1, 'joueurs_max' => 30,
        'surface_largeur' => 0, 'surface_longueur' => 0, 'duree_min' => 10, 'duree_max' => 20,
        'description_deroulement' => '', 'consignes_coach' => '', 'criteres_reussite' => '',
        'variante_facile' => '', 'variante_difficile' => ''];
    $vals = [];
    foreach ($champs as $c) {
        $v = $ex[$c] ?? $defauts[$c] ?? null;
        if (in_array($c, ['accents','tranches_age','filieres','materiel','geometrie'], true)
            && !is_string($v)) {
            $v = json_encode($v ?? ($c === 'geometrie' ? new stdClass() : []));
        }
        $vals[] = $v;
    }
    $vals[] = 'en_revue'; // statut imposé

    $db->prepare(
        'INSERT INTO exercices (' . implode(',', $champs) . ', statut)
         VALUES (' . rtrim(str_repeat('?,', count($champs)), ',') . ', ?)'
    )->execute($vals);
    $exId = (int) $db->lastInsertId();

    $findP = $db->prepare('SELECT id FROM perimetres WHERE code = ?');
    $linkP = $db->prepare(
        'INSERT OR IGNORE INTO exercice_perimetres (exercice_id, perimetre_id) VALUES (?, ?)'
    );
    foreach ($codesPerimetres as $code) {
        $findP->execute([$code]);
        if ($pid = $findP->fetchColumn()) {
            $linkP->execute([$exId, (int) $pid]);
        }
    }

    $db->prepare(
        'INSERT INTO exercice_revisions (exercice_id, version, action, commentaire)
         VALUES (?, 1, ?, ?)'
    )->execute([$exId, 'soumis', 'Amorçage bibliothèque (seed).']);

    return true;
}

$dossierLots = __DIR__ . '/lots';
$total = 0;
if (is_dir($dossierLots)) {
    foreach (glob($dossierLots . '/*.php') as $fichier) {
        /* Chaque fichier de lot renvoie [['code_perimetre', ...], [ {exercice}, ... ]]. */
        $lot = require $fichier;
        [$codesPerimetres, $exercices] = $lot + [[], []];
        $cree = 0;
        $db->beginTransaction();
        foreach ($exercices as $ex) {
            if (seed_inserer_exercice($db, $ex, $codesPerimetres)) $cree++;
        }
        $db->commit();
        $total += $cree;
        ecrire(sprintf('Lot %-24s : %d exercice(s) ajouté(s).',
            basename($fichier), $cree));
    }
} else {
    ecrire('Aucun dossier seed/lots/ : contenu à livrer à l\'étape 8.');
}

ecrire('');
ecrire("Terminé. $total exercice(s) créé(s) sur cette exécution.");
