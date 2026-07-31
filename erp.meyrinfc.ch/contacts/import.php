<?php
/**
 * Import d'un fichier de contacts (modèle Odoo).
 *
 * Le navigateur convertit l'Excel en CSV avant l'envoi, comme le fait déjà
 * l'import de calendrier d'Arbitrage : PHP ne voit qu'un CSV, aucune extension
 * serveur n'est nécessaire pour lire du .xlsx.
 *
 * Deux temps, toujours :
 *   1. aperçu — ce qui serait créé, reconnu, signalé ou rejeté. Rien n'est écrit.
 *   2. écriture — dans un lot annulable, précédée d'une sauvegarde datée.
 *
 * Le lot est ce qui rend les essais possibles directement sur le serveur : un
 * import raté se défait en entier, sans toucher aux fiches qu'il n'a fait
 * qu'enrichir.
 */

declare(strict_types=1);

/** Colonnes attendues, dans l'ordre du modèle fourni. */
const CT_IMPORT_COLS = ['ref_odoo','type','prenom','nom','nom_complet','email','telephone','mobile',
                        'qualites','organisation_ref','fonction','rue','npa','ville','pays',
                        'date_naissance','langue','iban','actif','notes'];

function ct_detect_sep(string $line): string {
    $c = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
    arsort($c);
    return (string)array_key_first($c);
}

function ct_parse_csv(string $content): array {
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = array_values(array_filter(explode("\n", $content), fn($l) => trim($l) !== ''));
    if (!$lines) return ['headers' => [], 'rows' => []];
    $sep = ct_detect_sep($lines[0]);
    $rows = array_map(fn($l) => array_map('trim', str_getcsv($l, $sep)), $lines);
    return ['headers' => array_shift($rows), 'rows' => $rows, 'sep' => $sep];
}

/**
 * Les lignes 2 à 4 du modèle sont des repères de lecture, pas des données.
 *
 * La détection se fait d'abord sur le contenu (identifiant vide, « OBLIGATOIRE »,
 * libellé de champ Odoo), de sorte que supprimer ou garder ces lignes reste sans
 * conséquence. La position ne sert que de garde-fou complémentaire pour la ligne
 * de descriptions, dont le texte est libre et donc impossible à reconnaître
 * autrement.
 */
function ct_is_annotation(array $row, array $idx, int $position): bool {
    $ref = mb_strtoupper(trim((string)($row[$idx['ref_odoo']] ?? '')));
    if ($ref === '') return true;
    if ($ref === 'OBLIGATOIRE') return true;
    if (str_contains($ref, 'ID EXTERNE') || str_contains($ref, 'IDENTIFIANT ODOO')) return true;

    /* Les trois lignes qui suivent l'en-tête portent des repères de lecture, et
       leur contenu varie (libellé Odoo, description libre). On les reconnaît à
       leur colonne « type », qui ne peut pas valoir autre chose que personne ou
       organisation sur une vraie ligne. Le contrôle est borné aux trois
       premières positions : plus loin dans le fichier, un type invalide est une
       vraie erreur, qui doit être signalée et non avalée. */
    if ($position < 3 && isset($idx['type'])) {
        $t = mfc_contacts_norm_text((string)($row[$idx['type']] ?? ''));
        if ($t !== '' && !in_array($t, ['personne', 'organisation'], true)) return true;
    }
    return false;
}

function contacts_import_run(PDO $pdo, bool $commit, string $user, bool $canBank): never
{
    if (empty($_FILES['csv'])) fail('Fichier requis');
    $f = $_FILES['csv'];
    if ($f['error'] !== UPLOAD_ERR_OK)  fail('Erreur de téléversement (code ' . $f['error'] . ')');
    if ($f['size'] > 20 * 1024 * 1024)  fail('Fichier trop volumineux (20 Mo max)');

    $content = file_get_contents($f['tmp_name']);
    if ($content === false) fail('Lecture du fichier impossible');
    $enc = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($enc && $enc !== 'UTF-8') $content = mb_convert_encoding($content, 'UTF-8', $enc);

    $parsed = ct_parse_csv($content);
    if (!$parsed['headers']) fail('Fichier vide ou illisible');

    /* Correspondance des colonnes par leur nom technique (ligne 1 du modèle).
       La normalisation doit préserver le tiret bas, sinon « ref_odoo » devient
       « refodoo » et aucune colonne n'est reconnue. */
    $idx = [];
    foreach ($parsed['headers'] as $i => $h) {
        $h = mfc_contacts_norm_quality((string)$h);
        if (in_array($h, CT_IMPORT_COLS, true)) $idx[$h] = $i;
    }
    $missing = array_diff(['ref_odoo', 'nom'], array_keys($idx));
    if ($missing) {
        fail('Colonnes introuvables : ' . implode(', ', $missing)
           . '. Utilisez le modèle fourni sans renommer la première ligne.');
    }

    $val = function (array $row, string $key) use ($idx): string {
        return isset($idx[$key]) ? trim((string)($row[$idx[$key]] ?? '')) : '';
    };

    $prepared = [];
    $errors   = [];
    $seenRefs = [];

    foreach ($parsed['rows'] as $n => $row) {
        $line = $n + 2;
        if (ct_is_annotation($row, $idx, $n)) continue;

        $ref = $val($row, 'ref_odoo');
        if (isset($seenRefs[$ref])) {
            $errors[] = "Ligne $line : l'identifiant « $ref » apparaît déjà ligne {$seenRefs[$ref]}";
            continue;
        }
        $seenRefs[$ref] = $line;

        $first = $val($row, 'prenom');
        $last  = $val($row, 'nom');
        $full  = $val($row, 'nom_complet');
        if ($last === '' && $full !== '') {
            [$first2, $last2] = mfc_contacts_split_name($full);
            if ($first === '') $first = $first2;
            $last = $last2;
        }
        if ($last === '') { $errors[] = "Ligne $line : nom manquant"; continue; }

        $type = mfc_contacts_norm_text($val($row, 'type')) ?: 'personne';
        if (!in_array($type, ['personne', 'organisation'], true)) {
            $errors[] = "Ligne $line : type « " . $val($row, 'type') . " » invalide (personne ou organisation)";
            continue;
        }

        $dob = $val($row, 'date_naissance');
        if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            /* Les tableurs suisses écrivent souvent 12.04.1985. */
            if (preg_match('#^(\d{1,2})[./](\d{1,2})[./](\d{4})$#', $dob, $m)) {
                $dob = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            } else {
                $errors[] = "Ligne $line : date de naissance « $dob » non reconnue";
                $dob = '';
            }
        }

        $actif = mfc_contacts_norm_text($val($row, 'actif'));
        $qual  = array_values(array_filter(array_map('trim', explode(';', $val($row, 'qualites')))));

        $c = [
            'odoo_ref'   => $ref,
            'type'       => $type,
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $val($row, 'email'),
            'phone'      => $val($row, 'telephone'),
            'mobile'     => $val($row, 'mobile'),
            'street'     => $val($row, 'rue'),
            'zip'        => $val($row, 'npa'),
            'city'       => $val($row, 'ville'),
            'country'    => $val($row, 'pays'),
            'birth_date' => $dob,
            'lang'       => mfc_contacts_norm_text($val($row, 'langue')) ?: 'fr',
            'job_title'  => $val($row, 'fonction'),
            'notes'      => $val($row, 'notes'),
            'active'     => ($actif === 'non' || $actif === '0') ? 0 : 1,
            'qualities'  => $qual,
            '_org_ref'   => $val($row, 'organisation_ref'),
            '_line'      => $line,
        ];
        /* Sans la permission bancaire, la colonne IBAN du fichier est ignorée :
           un import ne doit pas être un moyen détourné d'écrire un champ qu'on
           n'a pas le droit de modifier à l'écran. */
        if ($canBank) $c['iban'] = $val($row, 'iban');

        $prepared[] = $c;
    }

    /* ------------------------------------------------------- Aperçu */
    if (!$commit) {
        $nouveau = $reconnu = $probable = 0;
        $apercu  = [];
        foreach ($prepared as $c) {
            $m = mfc_contacts_match($pdo, $c);
            if ($m['verdict'] === MFC_CONTACTS_CERTAIN)       $reconnu++;
            elseif ($m['verdict'] === MFC_CONTACTS_PROBABLE)  $probable++;
            else                                              $nouveau++;
            if (count($apercu) < 25) {
                $apercu[] = ['ligne' => $c['_line'], 'nom' => trim($c['first_name'] . ' ' . $c['last_name']),
                             'email' => $c['email'], 'verdict' => $m['verdict'],
                             'raisons' => implode(' · ', $m['reasons'])];
            }
        }
        out(['step' => 'apercu', 'lignes' => count($prepared),
             'nouveaux' => $nouveau, 'reconnus' => $reconnu, 'a_verifier' => $probable,
             'erreurs' => count($errors), 'liste_erreurs' => array_slice($errors, 0, 40),
             'apercu' => $apercu]);
    }

    /* ------------------------------------------------------ Écriture */
    $backup = mfc_contacts_backup();
    $batch  = mfc_contacts_batch_open($pdo, 'fichier', (string)($f['name'] ?? ''), $user);

    $crees = $relies = $signales = 0;
    $refMap = [];                                   // ref_odoo => ref_id du contact

    $pdo->beginTransaction();
    try {
        foreach ($prepared as $c) {
            $org  = $c['_org_ref'];
            unset($c['_org_ref'], $c['_line']);
            $r = mfc_contacts_ingest($pdo, $c, null, null, $batch);
            $refMap[$c['odoo_ref']] = ['ref_id' => $r['ref_id'], 'id' => $r['contact_id'], 'org' => $org];
            if ($r['verdict'] === MFC_CONTACTS_CERTAIN)       $relies++;
            elseif ($r['verdict'] === MFC_CONTACTS_PROBABLE)  { $crees++; $signales++; }
            else                                              $crees++;
        }

        /* Rattachements aux organisations, une fois toutes les fiches connues :
           une personne peut citer une entreprise située plus bas dans le fichier. */
        $orgLies = 0;
        foreach ($refMap as $entry) {
            if ($entry['org'] === '' || !isset($refMap[$entry['org']])) continue;
            $pdo->prepare('UPDATE contacts SET org_ref_id = ? WHERE id = ?')
                ->execute([$refMap[$entry['org']]['ref_id'], $entry['id']]);
            $orgLies++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail('Import interrompu, rien n\'a été écrit : ' . $e->getMessage(), 500);
    }

    $stats = ['crees' => $crees, 'relies' => $relies, 'signales' => $signales,
              'organisations_liees' => $orgLies, 'erreurs' => count($errors)];
    mfc_contacts_batch_close($pdo, $batch, $stats);

    out(['ok' => true, 'batch_id' => $batch, 'backup' => $backup] + $stats
        + ['liste_erreurs' => array_slice($errors, 0, 40),
           'pending_duplicates' => mfc_contacts_pending_duplicates($pdo)]);
}
