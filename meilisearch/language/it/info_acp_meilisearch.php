<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
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
	'ACP_MEILISEARCH_TITLE'			=> 'Meilisearch',
	'ACP_MEILISEARCH_DIAGNOSTICS'	=> 'Diagnostica Meilisearch',
	'ACP_MEILISEARCH_FORUMS'		=> 'Forum indicizzati',
));
