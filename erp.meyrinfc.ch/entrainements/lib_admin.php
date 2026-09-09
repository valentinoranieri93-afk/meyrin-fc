<?php
/**
 * lib_admin.php — Administration du module (profils, périmètres).
 *
 * Réservé au profil `admin`. Le mapping des équipes et les plafonds de budget
 * IA sont administrés aux étapes où ils servent (générateur, couche IA).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib_exercices.php';

const ET_PROFILS = ['coach', 'responsable_categorie', 'directeur_technique', 'admin'];

/** Liste des utilisateurs connus du module, avec leurs périmètres de responsabilité. */
function et_admin_utilisateurs(): array
{
    $db  = et_db();
    $rows = $db->query(
        'SELECT id, erp_id, nom, email, profil, actif, dernier_acces
         FROM utilisateurs ORDER BY (profil = \'admin\') DESC,
              (profil = \'directeur_technique\') DESC,
              (profil = \'responsable_categorie\') DESC, nom'
    )->fetchAll();

    $resp = [];
    foreach ($db->query('SELECT perimetre_id, utilisateur_id FROM perimetre_responsables')->fetchAll() as $r) {
        $resp[(int) $r['utilisateur_id']][] = (int) $r['perimetre_id'];
    }
    $eq = [];
    foreach ($db->query('SELECT utilisateur_id, equipe_ref_id FROM utilisateurs_equipes')->fetchAll() as $r) {
        $eq[(int) $r['utilisateur_id']][] = $r['equipe_ref_id'];
    }
    foreach ($rows as &$u) {
        $u['perimetres'] = $resp[(int) $u['id']] ?? [];
        $u['equipes']    = $eq[(int) $u['id']] ?? [];
    }
    return $rows;
}

/**
 * Change le profil d'un utilisateur, et (pour un responsable) ses périmètres.
 * Garde-fou : on ne retire pas le dernier admin.
 */
function et_admin_set_profil(array $sessionAdmin, string $erpId, string $profil, array $perimetreIds): array
{
    if (!in_array($profil, ET_PROFILS, true)) {
        throw new EtErreur('Profil inconnu.');
    }
    $db = et_db();
    $st = $db->prepare('SELECT * FROM utilisateurs WHERE erp_id = ?');
    $st->execute([$erpId]);
    $cible = $st->fetch();
    if (!$cible) throw new EtErreur('Utilisateur introuvable dans le module.');

    if ($cible['profil'] === 'admin' && $profil !== 'admin') {
        $nbAdmins = (int) $db->query("SELECT COUNT(*) FROM utilisateurs WHERE profil = 'admin' AND actif = 1")->fetchColumn();
        if ($nbAdmins <= 1) {
            throw new EtErreur('Impossible : ce serait le dernier administrateur du module.');
        }
    }

    $db->prepare('UPDATE utilisateurs SET profil = ? WHERE id = ?')
       ->execute([$profil, (int) $cible['id']]);

    $db->prepare('DELETE FROM perimetre_responsables WHERE utilisateur_id = ?')->execute([(int) $cible['id']]);
    if ($profil === 'responsable_categorie') {
        $valides = array_column(et_perimetres_tous(), 'id');
        $link = $db->prepare('INSERT OR IGNORE INTO perimetre_responsables (perimetre_id, utilisateur_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $perimetreIds)) as $pid) {
            if (in_array($pid, array_map('intval', $valides), true)) {
                $link->execute([$pid, (int) $cible['id']]);
            }
        }
    }

    return array_values(array_filter(et_admin_utilisateurs(), fn($u) => $u['erp_id'] === $erpId))[0];
}

/** Modifie un périmètre. Le code reste immuable (référencé ailleurs). */
function et_admin_perimetre_save(int $id, array $in): array
{
    $db = et_db();
    $st = $db->prepare('SELECT * FROM perimetres WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) throw new EtErreur('Périmètre introuvable.');

    $libelle = trim((string) ($in['libelle'] ?? $p['libelle']));
    if ($libelle === '') throw new EtErreur('Le libellé est requis.');

    $tranches = $in['tranches_age'] ?? json_decode((string) $p['tranches_age'], true) ?? [];
    if (is_string($tranches)) $tranches = json_decode($tranches, true) ?: [];
    $refTranches = et_tranches_age();
    $tranches = array_values(array_filter((array) $tranches, fn($t) => in_array($t, $refTranches, true)));

    $seuil = max(1, (int) ($in['seuil_ouverture'] ?? $p['seuil_ouverture']));
    $modeLibre = array_key_exists('mode_libre', $in) ? (int) (bool) $in['mode_libre'] : (int) $p['mode_libre'];
    $actif = array_key_exists('actif', $in) ? (int) (bool) $in['actif'] : (int) $p['actif'];

    $db->prepare(
        'UPDATE perimetres SET libelle = ?, tranches_age = ?, seuil_ouverture = ?, mode_libre = ?, actif = ?
         WHERE id = ?'
    )->execute([$libelle, json_encode($tranches), $seuil, $modeLibre, $actif, $id]);

    $st->execute([$id]);
    return $st->fetch();
}
