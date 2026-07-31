<?php
/**
 * Alimentation automatique du référentiel depuis les modules existants.
 *
 * Les personnes saisies avant le module Contacts (employés RH, bénévoles
 * Events, contacts Sponsors, comptes ERP) n'ont pas à être ressaisies ni
 * réimportées : elles sont reprises ici.
 *
 * Idempotente : chaque objet source porte une clé de provenance
 * (module + identifiant local). Relancer la synchro ne recrée rien, elle se
 * contente de rattacher ce qui est apparu depuis.
 *
 * Le dédoublonnage est celui du socle : une personne présente dans trois
 * modules donne UNE fiche portant trois liens, pas trois fiches.
 */

declare(strict_types=1);

/** Ouvre la base d'un module, ou null si l'application n'est pas installée. */
function contacts_module_db(string $slug, string $filename): ?PDO
{
    $base = mfc_app_path($slug);
    if (!$base) return null;
    $f = $base . '/data/' . $filename;
    if (!is_file($f)) {
        $g = glob($base . '/data/*.sqlite') ?: [];
        $f = $g[0] ?? '';
    }
    if ($f === '' || !is_file($f)) return null;
    $pdo = new PDO('sqlite:' . $f);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function contacts_sync_all(PDO $pdo, int $batchId): array
{
    $stats = [];

    /* ---------------------------------------------------------- RH */
    $stats['rh'] = ['lus' => 0, 'crees' => 0, 'relies' => 0];
    if ($db = contacts_module_db('rh', 'rh.sqlite')) {
        foreach ($db->query('SELECT * FROM employees') as $e) {
            $r = mfc_contacts_ingest($pdo, [
                'first_name' => (string)$e['first_name'],
                'last_name'  => (string)$e['last_name'],
                'email'      => (string)($e['email'] ?? ''),
                /* RH range mobiles et fixes dans une seule colonne : c'est le
                   moteur qui tranche, d'après le préfixe. */
                'phone'      => (string)($e['phone'] ?? ''),
                'iban'       => (string)($e['iban'] ?? ''),
                'active'     => (int)($e['active'] ?? 1),
                'qualities'  => ['salarie'],
            ], 'rh', 'employee:' . $e['id'], $batchId);
            $stats['rh']['lus']++;
            $r['verdict'] === MFC_CONTACTS_CERTAIN ? $stats['rh']['relies']++ : $stats['rh']['crees']++;
        }
    }

    /* ------------------------------------------------------- Events
       Les bénévoles sont stockés en JSON dans une table générique. */
    $stats['events'] = ['lus' => 0, 'crees' => 0, 'relies' => 0];
    if ($db = contacts_module_db('events', 'evenements.sqlite')) {
        try {
            $q = $db->query("SELECT * FROM records WHERE collection = 'volunteers'");
            foreach ($q as $row) {
                $d = json_decode((string)$row['payload'], true);
                if (!is_array($d)) continue;
                $r = mfc_contacts_ingest($pdo, [
                    'first_name' => (string)($d['p'] ?? ''),
                    'last_name'  => (string)($d['n'] ?? ''),
                    'email'      => (string)($d['mail'] ?? ''),
                    'phone'      => (string)($d['tel'] ?? ''),
                    'qualities'  => ['benevole'],
                ], 'events', 'volunteer:' . ($d['id'] ?? $row['rec_id']), $batchId);
                $stats['events']['lus']++;
                $r['verdict'] === MFC_CONTACTS_CERTAIN ? $stats['events']['relies']++ : $stats['events']['crees']++;
            }
        } catch (PDOException $e) { /* collection absente : rien à reprendre */ }
    }

    /* ----------------------------------------------------- Sponsors
       Deux populations : les entreprises et leurs interlocuteurs. */
    $stats['sponsors'] = ['lus' => 0, 'crees' => 0, 'relies' => 0];
    if ($db = contacts_module_db('sponsors', 'sponsorflow.sqlite')) {
        $orgRefs = [];
        try {
            foreach ($db->query('SELECT * FROM sponsors') as $sp) {
                $r = mfc_contacts_ingest($pdo, [
                    'type'      => 'organisation',
                    'last_name' => (string)$sp['name'],
                    'qualities' => ['contact_sponsor'],
                ], 'sponsors', 'sponsor:' . $sp['id'], $batchId);
                $orgRefs[(string)$sp['id']] = $r['ref_id'];
                $stats['sponsors']['lus']++;
                $r['verdict'] === MFC_CONTACTS_CERTAIN ? $stats['sponsors']['relies']++ : $stats['sponsors']['crees']++;
            }
        } catch (PDOException $e) { /* table absente */ }

        try {
            foreach ($db->query('SELECT * FROM contacts') as $c) {
                [$first, $last] = mfc_contacts_split_name((string)($c['name'] ?? ''));
                $r = mfc_contacts_ingest($pdo, [
                    'first_name' => $first,
                    'last_name'  => $last,
                    'email'      => (string)($c['email'] ?? ''),
                    'phone'      => (string)($c['phone'] ?? ''),
                    'org_ref_id' => $orgRefs[(string)($c['sponsor_id'] ?? '')] ?? '',
                    'qualities'  => ['contact_sponsor'],
                ], 'sponsors', 'contact:' . $c['id'], $batchId);
                $stats['sponsors']['lus']++;
                $r['verdict'] === MFC_CONTACTS_CERTAIN ? $stats['sponsors']['relies']++ : $stats['sponsors']['crees']++;
            }
        } catch (PDOException $e) { /* table absente */ }
    }

    /* --------------------------------------------------------- ERP
       Les comptes restent la source de l'authentification : on ne les
       déplace pas, on note simplement quel contact peut se connecter. */
    $stats['erp'] = ['lus' => 0, 'crees' => 0, 'relies' => 0];
    $uf = DATA_DIR . 'users.json';
    if (is_file($uf)) {
        foreach ((json_decode((string)file_get_contents($uf), true) ?: []) as $u) {
            [$first, $last] = mfc_contacts_split_name((string)($u['name'] ?? ''));
            if ($last === '') continue;
            $r = mfc_contacts_ingest($pdo, [
                'first_name' => $first,
                'last_name'  => $last,
                'email'      => (string)($u['email'] ?? ''),
                'active'     => (int)($u['active'] ?? 1),
            ], 'erp', 'user:' . $u['id'], $batchId);
            $pdo->prepare('UPDATE contacts SET erp_user_id = ? WHERE id = ? AND erp_user_id = ""')
                ->execute([(string)$u['id'], $r['contact_id']]);
            $stats['erp']['lus']++;
            $r['verdict'] === MFC_CONTACTS_CERTAIN ? $stats['erp']['relies']++ : $stats['erp']['crees']++;
        }
    }

    $stats['total_contacts'] = (int)$pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    $stats['a_verifier']     = mfc_contacts_pending_duplicates($pdo);
    return $stats;
}
