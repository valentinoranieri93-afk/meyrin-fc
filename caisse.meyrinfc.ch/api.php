<?php
/**
 * Caisse Meyrin FC — API backend
 * Lit et écrit les données dans caisse_data.json
 *
 * Authentification : session unique de l'ERP (cookie JWT), via mfc_boot.php.
 * L'ancienne clé passée en clair dans l'URL (?key=...) a été supprimée : elle
 * était lisible dans le JavaScript par n'importe quel visiteur et suffisait à
 * lire et écrire l'intégralité de la caisse.
 */

declare(strict_types=1);

require_once __DIR__ . '/mfc_boot.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
/* Plus de CORS : l'interface est servie par le même domaine que cette API.
   « Access-Control-Allow-Origin: * » serait d'ailleurs incompatible avec une
   authentification par cookie. */

define('DATA_FILE', __DIR__ . '/caisse_data.json');

/* Session valide + accès à l'application. Répond 401/403 en JSON sinon. */
$user = mfc_require_api('caisse');

function read_db(): array {
    if (!file_exists(DATA_FILE)) return ['pin' => null, 'days' => []];
    return json_decode((string)file_get_contents(DATA_FILE), true) ?: ['pin' => null, 'days' => []];
}

/** Dates dont l'état de clôture change entre l'ancien et le nouveau contenu. */
function cloture_changes(array $old, array $new): array {
    $state = function (array $db): array {
        $s = [];
        foreach ($db['days'] ?? [] as $d) {
            if (isset($d['date'])) $s[(string)$d['date']] = !empty($d['cloture']);
        }
        return $s;
    };
    $a = $state($old);
    $b = $state($new);
    $changed = [];
    foreach ($b as $date => $closed) {
        if (($a[$date] ?? null) !== $closed) $changed[] = $date;
    }
    return $changed;
}

/* ------------------------------------------------------------- Lecture */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!mfc_can('caisse.register.use') && !mfc_can('caisse.register.reports')) {
        mfc_json_die(403, 'Droit insuffisant pour consulter la caisse.');
    }
    echo json_encode(read_db(), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------- Écriture */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mfc_require_perm('caisse.register.use');

    $body = (string)file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!is_array($data)) {
        mfc_json_die(400, 'JSON invalide');
    }
    /* Garde-fou de forme : l'interface envoie tout l'état à chaque
       enregistrement. Un corps mal formé écraserait la caisse entière. */
    if (!array_key_exists('days', $data) || !is_array($data['days'])) {
        mfc_json_die(400, 'Contenu inattendu : la liste des journées est absente.');
    }

    /* Ouvrir ou rouvrir une journée est une opération à part : elle est
       soumise à sa propre permission, vérifiée côté serveur en comparant
       l'état reçu à l'état stocké, et pas seulement en masquant un bouton. */
    $changed = cloture_changes(read_db(), $data);
    if ($changed && !mfc_can('caisse.register.close')) {
        mfc_json_die(403, 'Droit insuffisant pour clôturer ou rouvrir une journée.', ['dates' => $changed]);
    }

    $ok = file_put_contents(DATA_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if ($ok === false) {
        mfc_json_die(500, 'Impossible d\'écrire le fichier');
    }

    echo json_encode(['ok' => true]);
    exit;
}

mfc_json_die(405, 'Méthode non autorisée');
