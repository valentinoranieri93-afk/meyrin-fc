<?php
/**
 * lib_entrainements.php — Logique commune du module (hors accès base).
 *
 * Lot 1 : configuration serveur, synchronisation de l'annuaire local avec la
 * session ERP, résolution du profil métier. Le moteur de séance, le renderer
 * SVG et la couche IA arrivent aux étapes suivantes.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib_db.php';

/** Permission ERP qui ouvre l'accès au module. */
const ET_PERM_ACCESS = 'entrainements.access';
/** Permission ERP qui donne le profil admin du module. */
const ET_PERM_ADMIN  = 'entrainements.admin';

/**
 * Réglages serveur, fusionnés avec les valeurs par défaut de l'exemple.
 * Un `config.local.php` absent n'est pas une erreur : l'IA est simplement
 * désactivée (clé vide) et le moteur reste 100 % déterministe.
 */
function et_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $defaut = [
        'anthropic_api_key'        => '',
        'ia_modele'                => 'claude-sonnet-5',
        'ia_prix_input_usd_mtok'   => 2.0,
        'ia_prix_output_usd_mtok'  => 10.0,
        'ia_taux_usd_chf'          => 0.90,
        'ia_timeout'               => 8,
    ];

    $local = [];
    $f = __DIR__ . '/config.local.php';
    if (is_file($f)) {
        $r = require $f;
        if (is_array($r)) $local = $r;
    }
    return $cfg = array_merge($defaut, $local);
}

/** L'IA est-elle utilisable (clé présente) ? */
function et_ia_active(): bool
{
    return trim((string) et_config()['anthropic_api_key']) !== '';
}

/**
 * Retrouve ou crée la ligne `utilisateurs` correspondant à la session ERP.
 * NON destructif : ne modifie que le nom, l'e-mail et l'horodatage d'accès.
 * Le profil, lui, n'est jamais écrasé une fois posé (sauf premier passage).
 */
function et_user_sync(array $session): array
{
    $db     = et_db();
    $erpId  = (string) ($session['sub'] ?? '');
    $nom    = (string) ($session['name'] ?? '');
    $email  = (string) ($session['login'] ?? '');

    if ($erpId === '') {
        throw new RuntimeException('Session ERP sans identifiant.');
    }

    $st = $db->prepare('SELECT * FROM utilisateurs WHERE erp_id = ?');
    $st->execute([$erpId]);
    $row = $st->fetch();

    if (!$row) {
        /* Profil initial : admin si la personne porte la permission dédiée,
           sinon coach. L'admin promeut ensuite les DT et responsables. */
        $profil = mfc_can(ET_PERM_ADMIN) ? 'admin' : 'coach';
        $db->prepare(
            'INSERT INTO utilisateurs (erp_id, nom, email, profil, dernier_acces)
             VALUES (?, ?, ?, ?, datetime(\'now\'))'
        )->execute([$erpId, $nom, $email, $profil]);
        $st->execute([$erpId]);
        return $st->fetch();
    }

    $db->prepare(
        'UPDATE utilisateurs SET nom = ?, email = ?, dernier_acces = datetime(\'now\')
         WHERE id = ?'
    )->execute([$nom, $email, (int) $row['id']]);

    /* Une personne qui gagne la permission admin de l'ERP après coup passe
       admin ici aussi ; on ne rétrograde jamais automatiquement. */
    if (mfc_can(ET_PERM_ADMIN) && $row['profil'] !== 'admin') {
        $db->prepare('UPDATE utilisateurs SET profil = ? WHERE id = ?')
           ->execute(['admin', (int) $row['id']]);
        $row['profil'] = 'admin';
    }

    $row['nom']   = $nom;
    $row['email'] = $email;
    return $row;
}

/** Profil métier de la personne connectée : admin|directeur_technique|responsable_categorie|coach. */
function et_profil_courant(array $session): string
{
    return et_user_sync($session)['profil'] ?? 'coach';
}

/** Identifiants (perimetres.id) sur lesquels la personne peut valider. */
function et_perimetres_de_validation(array $session): array
{
    $u = et_user_sync($session);
    if (in_array($u['profil'], ['admin', 'directeur_technique'], true)) {
        return array_map('intval',
            et_db()->query('SELECT id FROM perimetres')->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($u['profil'] === 'responsable_categorie') {
        $st = et_db()->prepare(
            'SELECT perimetre_id FROM perimetre_responsables WHERE utilisateur_id = ?'
        );
        $st->execute([(int) $u['id']]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    return [];
}

/**
 * Permissions à injecter dans la page pour que l'interface masque ce que la
 * personne ne peut pas faire. api.php revérifie systématiquement : masquer un
 * bouton n'est pas une sécurité.
 */
function et_perms_pour_page(array $session): array
{
    $profil = et_profil_courant($session);
    return [
        'profil'        => $profil,
        'erpId'         => (string) ($session['sub'] ?? ''),
        'estAdmin'      => $profil === 'admin',
        'estDT'         => in_array($profil, ['admin', 'directeur_technique'], true),
        'peutValider'   => in_array($profil, ['admin', 'directeur_technique', 'responsable_categorie'], true),
        'perimetres'    => et_perimetres_de_validation($session),
        'iaActive'      => et_ia_active(),
        'nom'           => (string) ($session['name'] ?? ''),
        'erpUrl'        => ERP_URL,
    ];
}
