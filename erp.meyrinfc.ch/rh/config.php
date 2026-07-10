<?php
/**
 * Config module RH — clé API Claude pour l'extraction des feuilles de primes.
 * À renseigner après déploiement. Ne jamais committer une vraie clé dans ce fichier :
 * préférer une variable d'environnement (getenv) côté hébergement.
 */
define('RH_ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');
define('RH_ANTHROPIC_MODEL', 'claude-sonnet-5');
