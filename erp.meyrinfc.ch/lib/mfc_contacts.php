<?php
/**
 * mfc_contacts.php — Référentiel des contacts, socle partagé.
 *
 * SOURCE DE VÉRITÉ UNIQUE des personnes et des organisations du club. Le module
 * Contacts en est l'interface ; RH, Arbitrage, Events, Sponsors, Commandes et
 * demain Comptabilité le lisent et l'alimentent.
 *
 * Pourquoi le magasin vit dans le socle et non dans le dossier du module :
 * si la base appartenait au module Contacts, six modules ouvriraient le fichier
 * d'un septième. Ici, tous passent par cette bibliothèque, comme pour
 * l'authentification (mfc_auth.php) et la structure sportive (mfc_club.php).
 *
 * PRINCIPE : l'identité est ici, ce qu'on en fait reste dans les modules.
 *   - ici        : nom, coordonnées, qualités, IBAN, liens de famille
 *   - dans RH    : salaires, indemnités, décomptes
 *   - dans Events: disponibilités, heures, affectations
 * Un module rattache ses données à `ref_id`, l'identifiant stable d'un contact.
 *
 * DÉDOUBLONNAGE : une même personne existe déjà plusieurs fois dans les modules
 * (Yann Peyrat est à la fois employé RH, bénévole Events et compte ERP). Toute
 * entrée passe donc par mfc_contacts_ingest(), qui reconnaît la personne au lieu
 * de la recréer. Voir mfc_contacts_match() pour les règles.
 */

if (defined('MFC_CONTACTS_LOADED')) return;
define('MFC_CONTACTS_LOADED', true);

if (!defined('ERP_ROOT')) define('ERP_ROOT', true);
require_once __DIR__ . '/../config.php';

/* ================================================================= STOCKAGE */

function mfc_contacts_file(): string {
    return DATA_DIR . 'contacts.sqlite';
}

function mfc_contacts_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dir = dirname(mfc_contacts_file());
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . mfc_contacts_file());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    /* Plusieurs modules écrivent dans cette base : sans attente, une écriture
       concurrente échouerait au lieu de patienter. */
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    mfc_contacts_schema($pdo);
    return $pdo;
}

function mfc_contacts_schema(PDO $pdo): void {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS contacts (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        ref_id       TEXT NOT NULL UNIQUE,
        type         TEXT NOT NULL DEFAULT 'personne',
        first_name   TEXT NOT NULL DEFAULT '',
        last_name    TEXT NOT NULL DEFAULT '',
        email        TEXT NOT NULL DEFAULT '',
        phone        TEXT NOT NULL DEFAULT '',
        mobile       TEXT NOT NULL DEFAULT '',
        street       TEXT NOT NULL DEFAULT '',
        zip          TEXT NOT NULL DEFAULT '',
        city         TEXT NOT NULL DEFAULT '',
        country      TEXT NOT NULL DEFAULT '',
        birth_date   TEXT NOT NULL DEFAULT '',
        lang         TEXT NOT NULL DEFAULT 'fr',
        iban         TEXT NOT NULL DEFAULT '',
        job_title    TEXT NOT NULL DEFAULT '',
        org_ref_id   TEXT NOT NULL DEFAULT '',
        erp_user_id  TEXT NOT NULL DEFAULT '',
        /* Forme du nom destinée à la RECHERCHE, distincte de celle utilisée
           pour le dédoublonnage.
           mfc_contacts_norm_name() trie les mots, ce qui absorbe les inversions
           prénom/nom mais rend toute recherche par sous-chaîne impossible :
           « D'ALOISIO » y devient « aloisio d enzo », où « daloisio » ne se
           trouve pas. Cette colonne garde donc l'ordre naturel.
           Elle ne peut pas non plus vivre dans contact_keys, dont l'unicité
           ferait que seul le premier homonyme détiendrait la clé. */
        norm_name    TEXT NOT NULL DEFAULT '',
        notes        TEXT NOT NULL DEFAULT '',
        active       INTEGER NOT NULL DEFAULT 1,
        batch_id     INTEGER,
        created_at   TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
    );

    /* Qualités : salarie, entraineur, benevole, joueur, membre, parent… */
    CREATE TABLE IF NOT EXISTS contact_qualities (
        contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        quality    TEXT NOT NULL,
        UNIQUE(contact_id, quality)
    );

    /* Clés d'identité : c'est le coeur du dédoublonnage.
       L'unicité est garantie par la base, pas seulement par le code : deux
       contacts ne peuvent pas revendiquer la même adresse e-mail, même si deux
       requêtes concurrentes essaient. Leçon du référentiel des équipes. */
    CREATE TABLE IF NOT EXISTS contact_keys (
        contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        type       TEXT NOT NULL,
        value      TEXT NOT NULL,
        UNIQUE(type, value)
    );

    /* Liens de famille et rattachement aux organisations. */
    CREATE TABLE IF NOT EXISTS contact_relations (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        from_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        to_id   INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        type    TEXT NOT NULL,
        comment TEXT NOT NULL DEFAULT '',
        UNIQUE(from_id, to_id, type)
    );

    /* Rattachement à un objet d'un module : contact <-> employé RH #50. */
    CREATE TABLE IF NOT EXISTS contact_links (
        contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        module     TEXT NOT NULL,
        local_id   TEXT NOT NULL,
        UNIQUE(module, local_id)
    );

    /* File de revue : rapprochements probables, à trancher par un humain. */
    CREATE TABLE IF NOT EXISTS contact_duplicates (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        other_id   INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
        score      INTEGER NOT NULL DEFAULT 0,
        reasons    TEXT NOT NULL DEFAULT '',
        status     TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(contact_id, other_id)
    );

    /* Lots d'import, pour pouvoir annuler un essai en entier. */
    CREATE TABLE IF NOT EXISTS contact_batches (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        source     TEXT NOT NULL,
        filename   TEXT NOT NULL DEFAULT '',
        stats      TEXT NOT NULL DEFAULT '',
        status     TEXT NOT NULL DEFAULT 'done',
        created_by TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE INDEX IF NOT EXISTS ix_contacts_last  ON contacts(last_name);
    CREATE INDEX IF NOT EXISTS ix_contacts_norm  ON contacts(norm_name);
    CREATE INDEX IF NOT EXISTS ix_contacts_batch ON contacts(batch_id);
    CREATE INDEX IF NOT EXISTS ix_keys_contact   ON contact_keys(contact_id);
    CREATE INDEX IF NOT EXISTS ix_dup_status     ON contact_duplicates(status);
    ");
}

function mfc_contacts_uid(): string {
    return 'ct_' . substr(md5(uniqid('', true)), 0, 10);
}

/* ============================================================ NORMALISATION
 *
 * Comparer « Enzo D'ALOISIO » et « enzo d aloisio » doit donner le même
 * résultat. Toute clé d'identité est donc normalisée avant d'être stockée,
 * jamais comparée telle qu'elle a été saisie.
 */

function mfc_contacts_norm_text(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n','ý'=>'y','ÿ'=>'y','ß'=>'ss','œ'=>'oe','æ'=>'ae',
    ]);
    /* Apostrophes, tirets et points deviennent des espaces : « D'Aloisio »,
       « D Aloisio » et « D-Aloisio » désignent la même personne. */
    $s = preg_replace("/['’\-\.]+/u", ' ', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9 ]+/u', '', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function mfc_contacts_norm_email(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : '';
}

/**
 * Téléphone réduit à sa forme comparable, au format suisse.
 *
 * 079 123 45 67, +41 79 123 45 67 et 0041791234567 sont le même numéro. Sans
 * cette réduction, le téléphone serait inutilisable comme clé.
 */
function mfc_contacts_norm_phone(string $s): string {
    $d = preg_replace('/[^0-9+]/', '', trim($s)) ?? '';
    if ($d === '') return '';
    if (str_starts_with($d, '00')) $d = '+' . substr($d, 2);
    if (str_starts_with($d, '+41')) $d = '0' . substr($d, 3);
    elseif (str_starts_with($d, '41') && strlen($d) === 11) $d = '0' . substr($d, 2);
    $d = ltrim($d, '+');
    /* Un numéro suisse fait 10 chiffres avec le 0 initial. En deçà, la valeur
       n'est pas fiable comme identifiant (extensions, numéros tronqués). */
    return strlen($d) >= 9 ? $d : '';
}

/**
 * Le numéro est-il un mobile suisse (075 à 079) ?
 *
 * La distinction ne peut pas venir du champ d'origine : le module RH range
 * mobiles et fixes dans une seule colonne « phone ». Elle vient donc du
 * préfixe, seul indice fiable.
 *
 * L'enjeu est concret : sur les données réelles du club, le fixe
 * 022 782 41 33 — le numéro du secrétariat — figure sur la fiche de
 * 14 employés. Traité comme un identifiant personnel, il rapprochait à tort
 * ces 14 personnes deux à deux.
 */
function mfc_contacts_is_mobile(string $normalized): bool {
    return (bool)preg_match('/^07[5-9]/', $normalized);
}

function mfc_contacts_norm_iban(string $s): string {
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($s)) ?? '');
    return strlen($s) >= 15 ? $s : '';
}

/**
 * Découpe « Prénom Nom » quand la source ne sépare pas les deux.
 *
 * Odoo n'a qu'un champ `name`, et plusieurs modules du club font pareil. Le
 * premier mot est pris pour le prénom, le reste pour le nom : « Momen ABDEL
 * MAKSOUD » donne « Momen » + « ABDEL MAKSOUD », ce qui est correct, alors que
 * l'inverse ne le serait pas. Le découpage reste une supposition, à confirmer
 * à l'écran d'import plutôt qu'à considérer comme acquis.
 */
function mfc_contacts_split_name(string $full): array {
    $full = trim(preg_replace('/\s+/', ' ', $full) ?? $full);
    if ($full === '') return ['', ''];
    $parts = explode(' ', $full);
    if (count($parts) === 1) return ['', $parts[0]];
    $first = array_shift($parts);
    return [$first, implode(' ', $parts)];
}

/**
 * Nom normalisé pour la RECHERCHE : ordre naturel conservé, espaces retirés.
 *
 * « Enzo D'ALOISIO » donne « enzodaloisio », ce qui permet de le retrouver en
 * tapant « daloisio », « enzo » ou « enzo d aloisio ». À ne pas confondre avec
 * mfc_contacts_norm_name(), qui trie les mots pour le rapprochement.
 */
function mfc_contacts_norm_search(string $first, string $last): string {
    return str_replace(' ', '', mfc_contacts_norm_text($first . ' ' . $last));
}

/** Nom complet normalisé, prénom et nom triés pour absorber les inversions. */
function mfc_contacts_norm_name(string $first, string $last): string {
    $a = mfc_contacts_norm_text($first);
    $b = mfc_contacts_norm_text($last);
    $parts = array_filter(array_merge(explode(' ', $a), explode(' ', $b)));
    sort($parts);
    return implode(' ', $parts);
}

/* ========================================================= CLÉS D'IDENTITÉ */

/**
 * Clés fortes déductibles d'une fiche.
 *
 * Une clé forte est censée n'appartenir qu'à une seule personne. Deux absentes
 * de cette liste, volontairement :
 *
 *  - le NOM seul, jamais : sur les données réelles du club, un rapprochement
 *    par le nom a confondu « Ana Branco » et « Paulo BRANCO ». Deux personnes,
 *    une famille.
 *  - le TÉLÉPHONE FIXE, jamais : c'est le numéro du foyer. Dans un club de
 *    football, le père et ses deux enfants le partagent. En faire une clé
 *    forte fusionnerait une famille entière en une seule fiche. Il ne sert donc
 *    que d'indice de renfort (voir mfc_contacts_match).
 *
 * Le mobile, lui, est personnel : il reste une clé forte.
 */
function mfc_contacts_keys_of(array $c): array {
    $keys = [];

    /* Identifiant d'origine (Odoo) : rend un import relançable sans doublon. */
    $odoo = mfc_contacts_norm_text((string)($c['odoo_ref'] ?? ''));
    if ($odoo !== '') $keys[] = ['odoo', $odoo];

    $email = mfc_contacts_norm_email((string)($c['email'] ?? ''));
    if ($email !== '') $keys[] = ['email', $email];

    $iban = mfc_contacts_norm_iban((string)($c['iban'] ?? ''));
    if ($iban !== '') $keys[] = ['iban', $iban];

    /* Les deux champs sont examinés, et c'est le PRÉFIXE qui décide : un mobile
       saisi dans la colonne « fixe » reste une clé forte, un fixe saisi dans la
       colonne « mobile » n'en devient pas une. */
    foreach (['mobile', 'phone'] as $f) {
        $p = mfc_contacts_norm_phone((string)($c[$f] ?? ''));
        if ($p !== '' && mfc_contacts_is_mobile($p)) $keys[] = ['mobile', $p];
    }

    /* Nom + date de naissance : deux homonymes nés le même jour dans un même
       club sont assez improbables pour traiter la combinaison comme sûre. */
    $name = mfc_contacts_norm_name((string)($c['first_name'] ?? ''), (string)($c['last_name'] ?? ''));
    $dob  = trim((string)($c['birth_date'] ?? ''));
    if ($name !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $keys[] = ['name_dob', $name . '|' . $dob];
    }

    return $keys;
}

/** Clé de provenance : « cet objet du module X est déjà rattaché ». */
function mfc_contacts_source_key(string $module, string $localId): array {
    return ['source', $module . ':' . $localId];
}

/* ============================================================ RAPPROCHEMENT */

const MFC_CONTACTS_CERTAIN  = 'certain';
const MFC_CONTACTS_PROBABLE = 'probable';
const MFC_CONTACTS_NOUVEAU  = 'nouveau';

/**
 * Cherche à qui correspond une fiche entrante.
 *
 * Renvoie ['verdict' => …, 'contact_id' => ?int, 'score' => int, 'reasons' => []].
 *
 *  - certain  : une clé forte concorde (e-mail, IBAN, téléphone, nom+naissance,
 *               ou provenance déjà rattachée). On relie, sans rien demander.
 *  - probable : les noms concordent sans clé forte, ou une clé faible s'ajoute.
 *               On crée quand même la fiche, mais on la signale à la revue :
 *               bloquer l'ingestion arrêterait le travail dans les modules.
 *  - nouveau  : rien de concordant.
 */
function mfc_contacts_match(PDO $pdo, array $c, ?string $module = null, ?string $localId = null): array
{
    $reasons = [];

    /* 1. Provenance : cet objet du module a-t-il déjà un contact ? */
    if ($module !== null && $localId !== null) {
        $st = $pdo->prepare('SELECT contact_id FROM contact_links WHERE module = ? AND local_id = ?');
        $st->execute([$module, $localId]);
        if ($id = $st->fetchColumn()) {
            return ['verdict' => MFC_CONTACTS_CERTAIN, 'contact_id' => (int)$id, 'score' => 100,
                    'reasons' => ["déjà rattaché à $module #$localId"]];
        }
    }

    /* 2. Clés fortes — mais jamais contre l'évidence du nom.
     *
     * Une clé forte suffit SEULEMENT si le nom ne la contredit pas. Cas réel
     * d'un club de football : un parent inscrit ses deux enfants avec sa propre
     * adresse e-mail. La clé « même e-mail » concorde, mais « Lucas Martin » et
     * « Emma Martin » ne sont pas la même personne. Fusionner serait pire que
     * dupliquer : on perdrait un enfant. Dans ce cas on signale au lieu de
     * conclure, et un humain tranche.
     */
    $incomingName = mfc_contacts_norm_name((string)($c['first_name'] ?? ''), (string)($c['last_name'] ?? ''));
    $labels = ['email' => 'même e-mail', 'iban' => 'même IBAN', 'mobile' => 'même mobile',
               'name_dob' => 'même nom et date de naissance', 'odoo' => 'même identifiant Odoo'];

    foreach (mfc_contacts_keys_of($c) as [$type, $value]) {
        $st = $pdo->prepare('SELECT contact_id FROM contact_keys WHERE type = ? AND value = ?');
        $st->execute([$type, $value]);
        $id = $st->fetchColumn();
        if (!$id) continue;

        $label = $labels[$type] ?? $type;

        /* L'identifiant d'origine désigne la fiche sans ambiguïté : c'est la
           même ligne du même export, le nom a pu être corrigé entre-temps. */
        if ($type === 'odoo' || $type === 'name_dob') {
            return ['verdict' => MFC_CONTACTS_CERTAIN, 'contact_id' => (int)$id, 'score' => 100,
                    'reasons' => [$label]];
        }

        $ex = $pdo->prepare('SELECT first_name, last_name FROM contacts WHERE id = ?');
        $ex->execute([$id]);
        $e = $ex->fetch();
        $existingName = $e ? mfc_contacts_norm_name((string)$e['first_name'], (string)$e['last_name']) : '';

        /* Nom absent d'un côté : rien ne contredit, on conclut. */
        if ($incomingName === '' || $existingName === '' || $incomingName === $existingName) {
            return ['verdict' => MFC_CONTACTS_CERTAIN, 'contact_id' => (int)$id, 'score' => 100,
                    'reasons' => [$label]];
        }

        return ['verdict' => MFC_CONTACTS_PROBABLE, 'contact_id' => (int)$id, 'score' => 75,
                'reasons' => [$label, 'mais noms différents — à vérifier']];
    }

    /* 3. Concordance de nom, jamais suffisante seule. */
    $name = mfc_contacts_norm_name((string)($c['first_name'] ?? ''), (string)($c['last_name'] ?? ''));
    if ($name === '') {
        return ['verdict' => MFC_CONTACTS_NOUVEAU, 'contact_id' => null, 'score' => 0, 'reasons' => []];
    }

    $st = $pdo->prepare('SELECT contact_id FROM contact_keys WHERE type = "name" AND value = ?');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if (!$id) {
        return ['verdict' => MFC_CONTACTS_NOUVEAU, 'contact_id' => null, 'score' => 0, 'reasons' => []];
    }

    $reasons[] = 'nom et prénom identiques';
    $score = 60;

    /* Les indices ci-dessous renforcent sans jamais rendre certain : deux
       frères partagent le nom, l'adresse et le téléphone du foyer. */
    $other = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
    $other->execute([$id]);
    $o = $other->fetch();
    if ($o) {
        $cz = trim((string)($c['zip'] ?? ''));
        if ($cz !== '' && $cz === $o['zip']) { $score += 10; $reasons[] = 'même code postal'; }

        /* Le fixe ne sert qu'ici, comme renfort d'une concordance de nom déjà
           établie. Le numéro du secrétariat figurant sur de nombreuses fiches,
           il ne prouve rien à lui seul. */
        foreach (['phone', 'mobile'] as $f) {
            $cp = mfc_contacts_norm_phone((string)($c[$f] ?? ''));
            if ($cp === '' || mfc_contacts_is_mobile($cp)) continue;
            if ($cp === mfc_contacts_norm_phone((string)$o['phone'])
                || $cp === mfc_contacts_norm_phone((string)$o['mobile'])) {
                $score += 10; $reasons[] = 'même téléphone fixe';
                break;
            }
        }

        /* Deux dates de naissance connues et différentes : ce sont deux
           personnes, quelle que soit la ressemblance du reste. */
        $cd = trim((string)($c['birth_date'] ?? ''));
        $od = trim((string)$o['birth_date']);
        if ($cd !== '' && $od !== '' && $cd !== $od) {
            return ['verdict' => MFC_CONTACTS_NOUVEAU, 'contact_id' => null, 'score' => 0,
                    'reasons' => ['homonyme, dates de naissance différentes']];
        }

        if (($c['type'] ?? 'personne') !== $o['type']) { $score -= 40; $reasons[] = 'types différents'; }
    }

    return ['verdict' => $score >= 50 ? MFC_CONTACTS_PROBABLE : MFC_CONTACTS_NOUVEAU,
            'contact_id' => (int)$id, 'score' => $score, 'reasons' => $reasons];
}

/* ================================================================ ÉCRITURE */

/**
 * Enregistre les clés d'un contact, en ignorant celles déjà prises.
 *
 * Une clé déjà attribuée à quelqu'un d'autre n'est pas volée : c'est le signe
 * d'un doublon, traité par la file de revue, pas par un écrasement silencieux.
 */
function mfc_contacts_store_keys(PDO $pdo, int $contactId, array $c): void {
    $ins = $pdo->prepare('INSERT OR IGNORE INTO contact_keys (contact_id, type, value) VALUES (?,?,?)');
    foreach (mfc_contacts_keys_of($c) as [$type, $value]) {
        $ins->execute([$contactId, $type, $value]);
    }
    /* Le nom est indexé à part, comme indice faible : il sert à repérer un
       rapprochement probable, jamais à conclure seul.
       L'unicité de la table fait que seul le PREMIER porteur d'un nom donné
       détient la clé. C'est voulu : les homonymes suivants tombent tous sur
       lui, donc toutes les fiches d'un même nom finissent comparées entre
       elles au lieu de se manquer deux à deux. */
    $name = mfc_contacts_norm_name((string)($c['first_name'] ?? ''), (string)($c['last_name'] ?? ''));
    if ($name !== '') {
        $pdo->prepare('INSERT OR IGNORE INTO contact_keys (contact_id, type, value) VALUES (?,"name",?)')
            ->execute([$contactId, $name]);
    }
}

/**
 * Crée le contact s'il est inconnu, le relie s'il existe déjà.
 *
 * C'est LE point d'entrée : import Odoo comme alimentation automatique depuis
 * un module passent par ici, donc par le même dédoublonnage.
 *
 * @return array ['contact_id'=>int, 'ref_id'=>string, 'verdict'=>string, 'reasons'=>array]
 */
function mfc_contacts_ingest(PDO $pdo, array $c, ?string $module = null, ?string $localId = null,
                             ?int $batchId = null): array
{
    $m = mfc_contacts_match($pdo, $c, $module, $localId);

    if ($m['verdict'] === MFC_CONTACTS_CERTAIN) {
        $id = (int)$m['contact_id'];
        mfc_contacts_complete($pdo, $id, $c);          // enrichit les champs vides
        mfc_contacts_store_keys($pdo, $id, $c);
        if ($module !== null && $localId !== null) mfc_contacts_link($pdo, $id, $module, $localId);
        if (!empty($c['qualities'])) mfc_contacts_add_qualities($pdo, $id, (array)$c['qualities']);
        $ref = $pdo->query("SELECT ref_id FROM contacts WHERE id = $id")->fetchColumn();
        return ['contact_id' => $id, 'ref_id' => (string)$ref, 'verdict' => MFC_CONTACTS_CERTAIN,
                'reasons' => $m['reasons']];
    }

    /* Les espaces de bord viennent souvent des exports : « Adent Service SA  »
       et « Adent Service SA » doivent être le même nom. */
    $first = trim((string)($c['first_name'] ?? ''));
    $last  = trim((string)($c['last_name'] ?? ''));

    $ref = mfc_contacts_uid();
    $pdo->prepare(
        'INSERT INTO contacts (ref_id, type, first_name, last_name, email, phone, mobile, street, zip,
                               city, country, birth_date, lang, iban, job_title, org_ref_id, notes,
                               active, batch_id, norm_name)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $ref,
        (string)($c['type'] ?? 'personne'),
        $first, $last,
        mfc_contacts_norm_email((string)($c['email'] ?? '')),
        (string)($c['phone'] ?? ''), (string)($c['mobile'] ?? ''),
        (string)($c['street'] ?? ''), (string)($c['zip'] ?? ''), (string)($c['city'] ?? ''),
        (string)($c['country'] ?? ''), (string)($c['birth_date'] ?? ''),
        (string)($c['lang'] ?? 'fr'), mfc_contacts_norm_iban((string)($c['iban'] ?? '')),
        (string)($c['job_title'] ?? ''), (string)($c['org_ref_id'] ?? ''), (string)($c['notes'] ?? ''),
        (int)($c['active'] ?? 1), $batchId,
        mfc_contacts_norm_search($first, $last),
    ]);
    $id = (int)$pdo->lastInsertId();

    mfc_contacts_store_keys($pdo, $id, $c);
    if ($module !== null && $localId !== null) mfc_contacts_link($pdo, $id, $module, $localId);
    if (!empty($c['qualities'])) mfc_contacts_add_qualities($pdo, $id, (array)$c['qualities']);

    /* Rapprochement probable : la fiche est créée pour ne pas bloquer le module,
       et signalée pour arbitrage humain. */
    if ($m['verdict'] === MFC_CONTACTS_PROBABLE && $m['contact_id']) {
        mfc_contacts_flag_duplicate($pdo, $id, (int)$m['contact_id'], (int)$m['score'], $m['reasons']);
    }

    return ['contact_id' => $id, 'ref_id' => $ref, 'verdict' => $m['verdict'], 'reasons' => $m['reasons']];
}

/** Complète les champs vides sans jamais écraser une valeur déjà saisie. */
function mfc_contacts_complete(PDO $pdo, int $id, array $c): void {
    $cur = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
    $cur->execute([$id]);
    $row = $cur->fetch();
    if (!$row) return;

    $map = ['first_name','last_name','email','phone','mobile','street','zip','city',
            'country','birth_date','iban','job_title','org_ref_id','notes'];
    $set = []; $val = [];
    foreach ($map as $f) {
        $new = trim((string)($c[$f] ?? ''));
        if ($new === '' || (string)$row[$f] !== '') continue;
        if ($f === 'email') $new = mfc_contacts_norm_email($new);
        if ($f === 'iban')  $new = mfc_contacts_norm_iban($new);
        if ($new === '') continue;
        $set[] = "$f = ?"; $val[] = $new;
    }
    if (!$set) return;
    $val[] = $id;
    $pdo->prepare('UPDATE contacts SET ' . implode(', ', $set) . ", updated_at = datetime('now') WHERE id = ?")
        ->execute($val);
    mfc_contacts_refresh_norm($pdo, $id);
}

/** Recalcule la forme comparable du nom après toute modification. */
function mfc_contacts_refresh_norm(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT first_name, last_name FROM contacts WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return;
    $pdo->prepare('UPDATE contacts SET norm_name = ? WHERE id = ?')
        ->execute([mfc_contacts_norm_search((string)$r['first_name'], (string)$r['last_name']), $id]);
}

function mfc_contacts_link(PDO $pdo, int $contactId, string $module, string $localId): void {
    $pdo->prepare('INSERT OR IGNORE INTO contact_links (contact_id, module, local_id) VALUES (?,?,?)')
        ->execute([$contactId, $module, $localId]);
}

/**
 * Normalise un nom de qualité en gardant le tiret bas.
 *
 * mfc_contacts_norm_text() supprime la ponctuation, y compris « _ » :
 * l'utiliser ici transformait « contact_sponsor » en « contactsponsor », qui ne
 * correspondait plus à la valeur attendue par les filtres.
 */
function mfc_contacts_norm_quality(string $q): string {
    $q = mfc_contacts_norm_text(str_replace('_', ' ', $q));
    return str_replace(' ', '_', $q);
}

function mfc_contacts_add_qualities(PDO $pdo, int $contactId, array $qualities): void {
    $ins = $pdo->prepare('INSERT OR IGNORE INTO contact_qualities (contact_id, quality) VALUES (?,?)');
    foreach ($qualities as $q) {
        $q = mfc_contacts_norm_quality((string)$q);
        if ($q !== '') $ins->execute([$contactId, $q]);
    }
}

function mfc_contacts_flag_duplicate(PDO $pdo, int $a, int $b, int $score, array $reasons): void {
    if ($a === $b) return;
    [$lo, $hi] = $a < $b ? [$a, $b] : [$b, $a];
    $pdo->prepare('INSERT OR IGNORE INTO contact_duplicates (contact_id, other_id, score, reasons)
                   VALUES (?,?,?,?)')
        ->execute([$lo, $hi, $score, implode(' · ', $reasons)]);
}

/* ==================================================================== FUSION */

/**
 * Fusionne deux contacts, sans perte.
 *
 * Tout ce qui pointait vers la fiche absorbée est d'abord reporté sur la fiche
 * conservée ; la suppression n'intervient qu'ensuite. C'est la même discipline
 * que pour les équipes, où l'ordre inverse aurait effacé des données de paie
 * par cascade.
 */
function mfc_contacts_merge(PDO $pdo, int $keepId, int $dropId): bool {
    if ($keepId === $dropId) return false;

    $chk = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE id IN (?,?)');
    $chk->execute([$keepId, $dropId]);
    if ((int)$chk->fetchColumn() !== 2) return false;

    $pdo->beginTransaction();
    try {
        $drop = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
        $drop->execute([$dropId]);
        $d = $drop->fetch();

        /* 1. Champs manquants récupérés depuis la fiche absorbée. */
        mfc_contacts_complete($pdo, $keepId, $d);

        /* 2. Qualités, liens modules, relations et clés reportés. */
        $pdo->prepare('UPDATE OR IGNORE contact_qualities SET contact_id = ? WHERE contact_id = ?')
            ->execute([$keepId, $dropId]);
        $pdo->prepare('UPDATE OR IGNORE contact_links SET contact_id = ? WHERE contact_id = ?')
            ->execute([$keepId, $dropId]);
        $pdo->prepare('UPDATE OR IGNORE contact_keys SET contact_id = ? WHERE contact_id = ?')
            ->execute([$keepId, $dropId]);
        $pdo->prepare('UPDATE OR IGNORE contact_relations SET from_id = ? WHERE from_id = ?')
            ->execute([$keepId, $dropId]);
        $pdo->prepare('UPDATE OR IGNORE contact_relations SET to_id = ? WHERE to_id = ?')
            ->execute([$keepId, $dropId]);

        /* 3. Les modules qui citaient le ref_id absorbé doivent suivre : on
              conserve donc l'ancien ref_id comme clé d'identité de la fiche
              gardée, plutôt que de le perdre. */
        $pdo->prepare('INSERT OR IGNORE INTO contact_keys (contact_id, type, value) VALUES (?,"ref_merged",?)')
            ->execute([$keepId, $d['ref_id']]);

        /* 4. La file de revue oublie la paire traitée. */
        $pdo->prepare('UPDATE contact_duplicates SET status = "merged"
                       WHERE (contact_id = ? AND other_id = ?) OR (contact_id = ? AND other_id = ?)')
            ->execute([$keepId, $dropId, $dropId, $keepId]);
        $pdo->prepare('DELETE FROM contact_duplicates WHERE contact_id = ? OR other_id = ?')
            ->execute([$dropId, $dropId]);

        $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$dropId]);
        $pdo->prepare("UPDATE contacts SET updated_at = datetime('now') WHERE id = ?")->execute([$keepId]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Deux fiches déclarées distinctes ne doivent plus jamais remonter en revue. */
function mfc_contacts_separate(PDO $pdo, int $a, int $b): void {
    [$lo, $hi] = $a < $b ? [$a, $b] : [$b, $a];
    $pdo->prepare('UPDATE contact_duplicates SET status = "separated"
                   WHERE contact_id = ? AND other_id = ?')->execute([$lo, $hi]);
}

/* ================================================================= LECTURE */

function mfc_contacts_by_ref(PDO $pdo, string $refId): ?array {
    $st = $pdo->prepare('SELECT * FROM contacts WHERE ref_id = ?');
    $st->execute([$refId]);
    $c = $st->fetch();
    if (!$c) {
        /* Fiche absorbée par une fusion : on suit le lien plutôt que de
           renvoyer « introuvable » à un module qui cite l'ancien identifiant. */
        $st = $pdo->prepare('SELECT c.* FROM contact_keys k JOIN contacts c ON c.id = k.contact_id
                             WHERE k.type = "ref_merged" AND k.value = ?');
        $st->execute([$refId]);
        $c = $st->fetch() ?: null;
    }
    if ($c) $c['qualities'] = mfc_contacts_qualities($pdo, (int)$c['id']);
    return $c ?: null;
}

/** Contact rattaché à un objet d'un module, ou null. */
function mfc_contacts_by_source(PDO $pdo, string $module, string $localId): ?array {
    $st = $pdo->prepare('SELECT c.* FROM contact_links l JOIN contacts c ON c.id = l.contact_id
                         WHERE l.module = ? AND l.local_id = ?');
    $st->execute([$module, $localId]);
    $c = $st->fetch();
    if ($c) $c['qualities'] = mfc_contacts_qualities($pdo, (int)$c['id']);
    return $c ?: null;
}

function mfc_contacts_qualities(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT quality FROM contact_qualities WHERE contact_id = ? ORDER BY quality');
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function mfc_contacts_pending_duplicates(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM contact_duplicates WHERE status = "pending"')->fetchColumn();
}

function mfc_contacts_is_empty(): bool {
    return (int)mfc_contacts_db()->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === 0;
}

/* ===================================================== LOTS D'IMPORT / ANNULATION */

function mfc_contacts_batch_open(PDO $pdo, string $source, string $filename, string $user): int {
    $pdo->prepare('INSERT INTO contact_batches (source, filename, created_by, status) VALUES (?,?,?,"running")')
        ->execute([$source, $filename, $user]);
    return (int)$pdo->lastInsertId();
}

function mfc_contacts_batch_close(PDO $pdo, int $batchId, array $stats): void {
    $pdo->prepare('UPDATE contact_batches SET stats = ?, status = "done" WHERE id = ?')
        ->execute([json_encode($stats, JSON_UNESCAPED_UNICODE), $batchId]);
}

/**
 * Annule un lot : supprime les contacts qu'il a CRÉÉS.
 *
 * Les fiches que le lot s'est contenté d'enrichir ou de relier ne sont pas
 * touchées : elles préexistaient. Permet de refaire un essai d'import autant de
 * fois que nécessaire sur le serveur réel sans accumuler de résidus.
 */
function mfc_contacts_batch_rollback(PDO $pdo, int $batchId): array {
    $st = $pdo->prepare('SELECT id FROM contacts WHERE batch_id = ?');
    $st->execute([$batchId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        $pdo->prepare('UPDATE contact_batches SET status = "rolled_back" WHERE id = ?')->execute([$batchId]);
        return ['deleted' => 0];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        /* Les tables liées partent par cascade (ON DELETE CASCADE), sauf la file
           de revue qui référence deux contacts : on la nettoie explicitement. */
        $pdo->prepare("DELETE FROM contact_duplicates WHERE contact_id IN ($ph) OR other_id IN ($ph)")
            ->execute([...$ids, ...$ids]);
        $pdo->prepare("DELETE FROM contacts WHERE id IN ($ph)")->execute($ids);
        $pdo->prepare('UPDATE contact_batches SET status = "rolled_back" WHERE id = ?')->execute([$batchId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['deleted' => count($ids)];
}

/** Copie datée de la base, avant toute écriture de masse. */
function mfc_contacts_backup(): string {
    $f = mfc_contacts_file();
    if (!is_file($f)) return '';
    $dest = $f . '.bak-' . date('Ymd-His');
    if (!copy($f, $dest)) throw new RuntimeException('Sauvegarde du référentiel impossible');
    return basename($dest);
}
