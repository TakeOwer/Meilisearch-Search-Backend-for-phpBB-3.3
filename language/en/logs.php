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
	'LOG_MEILISEARCH_ERROR'				=> '<strong>Meilisearch error</strong><br />» %s',
	'LOG_MEILISEARCH_SETTINGS_APPLIED'	=> '<strong>Meilisearch index settings applied</strong>',
	'LOG_MEILISEARCH_QUEUE_CLEARED'		=> '<strong>Meilisearch retry queue discarded</strong>',
	'LOG_MEILISEARCH_FORUMS_SAVED'		=> '<strong>Meilisearch forum exclusion list updated</strong>',
	'LOG_MEILISEARCH_FORUMS_PURGED'		=> '<strong>Meilisearch excluded forums evicted from index</strong>',
	'LOG_MEILISEARCH_KEY_GENERATED'		=> '<strong>Meilisearch API key generated</strong>',
	'LOG_MEILISEARCH_REINDEXED'			=> '<strong>Meilisearch reindex run from the indexed forums page</strong>',
	'LOG_MEILISEARCH_SYNONYMS_SAVED'	=> '<strong>Meilisearch synonym list updated</strong>',
));
