<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

/**
 * Admin log entries.
 *
 * These live apart from common.php and are loaded globally through
 * core.user_setup, because the log viewer is a core ACP module: it never loads
 * an extension's own language files, so a log key defined only in common.php
 * renders as {LOG_SOMETHING} once the entry is written. Keeping the file small
 * honours phpBB's advice to load globally only what is strictly necessary.
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'LOG_MEILISEARCH_ERROR'				=> '<strong>Errore Meilisearch</strong><br />&raquo; %s',
	'LOG_MEILISEARCH_SETTINGS_APPLIED'	=> '<strong>Impostazioni dell&rsquo;indice Meilisearch applicate</strong>',
	'LOG_MEILISEARCH_QUEUE_CLEARED'		=> '<strong>Coda di ripetizione Meilisearch scartata</strong>',
	'LOG_MEILISEARCH_FORUMS_SAVED'		=> '<strong>Lista di esclusione forum Meilisearch aggiornata</strong>',
	'LOG_MEILISEARCH_FORUMS_PURGED'		=> '<strong>Forum esclusi rimossi dall&rsquo;indice Meilisearch</strong>',
	'LOG_MEILISEARCH_KEY_GENERATED'		=> '<strong>Chiave API Meilisearch generata</strong>',
	'LOG_MEILISEARCH_REINDEXED'			=> '<strong>Reindicizzazione Meilisearch avviata dalla pagina dei forum indicizzati</strong>',
	'LOG_MEILISEARCH_SYNONYMS_SAVED'	=> '<strong>Lista dei sinonimi Meilisearch aggiornata</strong>',
));
