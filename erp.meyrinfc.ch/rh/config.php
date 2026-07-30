<?php
/**
 * Config module RH — clé API Claude pour l'extraction des feuilles de primes.
 * À renseigner après déploiement. Ne jamais committer une vraie clé dans ce fichier :
 * préférer une variable d'environnement (getenv) côté hébergement.
 */
define('RH_ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');
define('RH_ANTHROPIC_MODEL', 'claude-sonnet-5');

/** Expéditeur des e-mails envoyés depuis l'onglet Paiements (fiches de paie).
 * mail() est désactivé sur cet hébergement : l'envoi passe par un vrai compte e-mail via SMTP authentifié.
 * RH_MAIL_FROM_ADDRESS doit être la même boîte que RH_SMTP_USER (la plupart des serveurs SMTP l'exigent).
 * Identifiants à renseigner en variables d'environnement côté hébergement (jamais en dur ici) :
 *   RH_SMTP_HOST (ex: mail.infomaniak.com), RH_SMTP_PORT (587 ou 465), RH_SMTP_USER, RH_SMTP_PASS. */
define('RH_MAIL_FROM_NAME', 'Meyrin FC');
define('RH_MAIL_FROM_ADDRESS', getenv('RH_SMTP_USER') ?: 'info@meyrinfc.ch');

define('RH_SMTP_HOST', getenv('RH_SMTP_HOST') ?: 'mail.infomaniak.com');
define('RH_SMTP_PORT', (int)(getenv('RH_SMTP_PORT') ?: 465));
define('RH_SMTP_USER', trim(getenv('RH_SMTP_USER') ?: 'info@meyrinfc.ch'));

/* AUCUNE valeur de repli pour le mot de passe : ce fichier est versionne, une
   valeur en dur ici finirait dans l'historique du depot, de facon permanente et
   irrecuperable meme apres correction. La variable d'environnement RH_SMTP_PASS
   doit etre definie cote hebergement (Infomaniak > Sites > Variables d'env.).
   Sans elle, l'envoi echoue avec un message explicite plutot que silencieusement. */
define('RH_SMTP_PASS', trim((string)getenv('RH_SMTP_PASS')));
