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
	// Settings, rendered by meilisearch_backend::acp() inside ACP -> Search settings
	'MEILISEARCH_URL'					=> 'Meilisearch URL',
	'MEILISEARCH_URL_EXPLAIN'			=> 'Base URL of the Meilisearch instance, without a trailing slash. Example: <samp>http://127.0.0.1:7700</samp>. Keep the instance on a private interface or behind a firewall: it has no per-user access control of its own.',

	'MEILISEARCH_API_KEY'				=> 'API key',
	'MEILISEARCH_API_KEY_EXPLAIN'		=> 'Master key, or an API key holding the actions <samp>search</samp>, <samp>documents.*</samp>, <samp>indexes.*</samp>, <samp>settings.*</samp>, <samp>stats.get</samp> and <samp>tasks.get</samp>. Leave empty only if the instance runs without a master key. The value is stored in the config table in plain text and is readable by any founder.',

	'MEILISEARCH_INDEX'					=> 'Index name',
	'MEILISEARCH_INDEX_EXPLAIN'			=> 'The Meilisearch index this board writes to. Use a distinct name per board if several boards share one Meilisearch instance.',

	'MEILISEARCH_LOCALES'				=> 'Content languages',
	'MEILISEARCH_LOCALES_EXPLAIN'		=> 'Comma separated ISO 639 codes for the languages used on the board, for example <samp>ita,eng</samp>. This pins tokenisation instead of relying on per-document language detection, which is more accurate on short posts. Leave empty for automatic detection. Requires Meilisearch 1.10 or newer; on older versions the setting is silently ignored.',

	'MEILISEARCH_TIMEOUT'				=> 'Request timeout',
	'MEILISEARCH_TIMEOUT_EXPLAIN'		=> 'Seconds to wait for a Meilisearch response before giving up. Keep this low: a slow search engine must not hold up page rendering.',

	'MEILISEARCH_MAX_RESULTS'			=> 'Candidate limit',
	'MEILISEARCH_MAX_RESULTS_EXPLAIN'	=> 'Maximum number of post ids Meilisearch returns for one query, before phpBB re-applies permissions and sorting in SQL. Higher values give more complete result counts on broad queries at the cost of a larger SQL <samp>IN()</samp> clause. 1000 suits most boards.',

	'MEILISEARCH_BATCH_SIZE'			=> 'Indexing batch size',
	'MEILISEARCH_BATCH_SIZE_EXPLAIN'	=> 'Number of posts sent per HTTP request during a full reindex. Larger batches are faster but use more memory on both sides.',

	'MEILISEARCH_MIN_CHARS'				=> 'Minimum search characters',
	'MEILISEARCH_MIN_CHARS_EXPLAIN'		=> 'Search terms shorter than this are dropped. Unlike MySQL fulltext there is no engine-imposed floor, so 2 is a safe value and lets users find terms such as model numbers.',

	'MEILISEARCH_MAX_CHARS'				=> 'Maximum search characters',
	'MEILISEARCH_MAX_CHARS_EXPLAIN'		=> 'Search terms longer than this are dropped.',

	'MEILISEARCH_TYPO'					=> 'Typo tolerance',
	'MEILISEARCH_TYPO_EXPLAIN'			=> 'Allow approximate matching, so that <samp>fourm</samp> still finds <samp>forum</samp>. Disable if your board relies on exact part numbers or codes where a near miss is misleading. Changing this requires re-applying settings from the diagnostics page.',

	'MEILISEARCH_RELEVANCE'				=> 'Relevance ordering',
	'MEILISEARCH_RELEVANCE_EXPLAIN'		=> 'How keyword results are ordered. Meilisearch ranks documents matching more of the terms higher, so a search for two words puts posts containing both first; phpBB\'s own sort would otherwise discard that ranking. Note that the advanced search form pre-selects &ldquo;Post time&rdquo;, so it always submits a sort order even when the user never touched the control &mdash; which is why the recommended mode treats that default as &ldquo;no choice made&rdquo;.',
	'MEILISEARCH_RELEVANCE_DEFAULT'		=> 'Relevance unless the user picks a different sort (recommended)',
	'MEILISEARCH_RELEVANCE_IF_UNSET'	=> 'Relevance only when no sort order is submitted at all',
	'MEILISEARCH_RELEVANCE_NEVER'		=> 'Never &mdash; always use phpBB\'s sort order',

	'MEILISEARCH_QUEUE'					=> 'Retry queue',
	'MEILISEARCH_QUEUE_EXPLAIN'			=> 'When Meilisearch is unreachable, record the affected post ids and retry them from cron instead of losing the update. Strongly recommended: without it, posts made during an outage never enter the index.',

	// Errors
	'MEILISEARCH_EXT_DISABLED'			=> 'The Meilisearch extension is selected as the search backend but is not enabled. Enable it, or switch the board back to another backend.',
	'MEILISEARCH_NO_CURL'				=> 'The PHP cURL extension is required but is not available on this server.',
	'MEILISEARCH_NO_URL'				=> 'No Meilisearch URL has been configured.',
	'MEILISEARCH_UNREACHABLE'			=> 'The Meilisearch instance could not be reached.',
	'MEILISEARCH_SETTINGS_FAILED'		=> 'The index settings could not be applied.',
	'MEILISEARCH_PURGE_FAILED'			=> 'The index could not be emptied.',
	'MEILISEARCH_NOT_ACTIVE'			=> 'Meilisearch is installed but is not the active search backend, so nothing on this board is being indexed yet.',

	// Index statistics, shown on ACP -> Maintenance -> Search index
	'MEILISEARCH_STAT_DOCUMENTS'		=> 'Indexed posts',
	'MEILISEARCH_STAT_INDEXING'			=> 'Currently processing',
	'MEILISEARCH_STAT_QUEUE'			=> 'Pending retries',
	'MEILISEARCH_STAT_ERROR'			=> 'Last error',

	// Diagnostics module
	'ACP_MEILISEARCH_DIAGNOSTICS_EXPLAIN'	=> 'Live status of the Meilisearch connection and index. Connection settings themselves live under General &rarr; Board configuration &rarr; Search settings, together with the other search backends.',
	'MEILISEARCH_CONNECTION'			=> 'Connection',
	'MEILISEARCH_STATUS'				=> 'Status',
	'MEILISEARCH_REACHABLE'				=> 'Reachable',
	'MEILISEARCH_INDEX_STATE'			=> 'Index',
	'MEILISEARCH_INDEX_MISSING'			=> 'not created yet',
	'MEILISEARCH_LOCALES_AUTO'			=> 'automatic detection',
	'MEILISEARCH_QUEUE_PENDING'			=> 'Pending operations',
	'MEILISEARCH_GO_TO_SETTINGS'		=> 'Go to search settings',
	'MEILISEARCH_GO_TO_INDEX'			=> 'Go to the search index page to build or drop the index',
	'MEILISEARCH_APPLY_SETTINGS'		=> 'Create index and apply settings',
	'MEILISEARCH_FLUSH_QUEUE'			=> 'Flush retry queue now',
	'MEILISEARCH_CLEAR_QUEUE'			=> 'Discard retry queue',
	'MEILISEARCH_SETTINGS_APPLIED'		=> 'The index exists and its settings have been applied.',
	'MEILISEARCH_QUEUE_FLUSHED'			=> array(
		0	=> 'No pending operations to process.',
		1	=> '%d pending operation processed.',
		2	=> '%d pending operations processed.',
	),
	'MEILISEARCH_QUEUE_CLEARED'			=> 'The retry queue has been discarded. Posts affected by the discarded entries may now be missing from the index; run a full reindex if in doubt.',

	// Log entries
	'LOG_MEILISEARCH_ERROR'				=> '<strong>Meilisearch error</strong><br />» %s',
	'LOG_MEILISEARCH_SETTINGS_APPLIED'	=> '<strong>Meilisearch index settings applied</strong>',
	'LOG_MEILISEARCH_QUEUE_CLEARED'		=> '<strong>Meilisearch retry queue discarded</strong>',
	// Indexed forums
	'ACP_MEILISEARCH_FORUMS_EXPLAIN'	=> 'Choose which forums may be written to the Meilisearch index. On installation this list was pre-filled with every forum that guests cannot read; adjust it to match your board.',
	'MEILISEARCH_FORUMS_WARNING_TITLE'	=> 'What this does, and what it does not do',
	'MEILISEARCH_FORUMS_WARNING'		=> 'This is a <strong>content</strong> control, not a permission control. Search results already respect forum permissions and moderation visibility for every user, because phpBB re-applies its own SQL conditions after Meilisearch has matched the keywords &mdash; nobody has ever been able to find posts they may not read. Excluding a forum here goes one step further: its posts never leave the database at all, so even direct access to the Meilisearch API cannot reveal them. <strong>The cost is that members who legitimately have access to an excluded forum will not be able to find its posts through search either.</strong>',
	'MEILISEARCH_FORUMS_LIST'			=> 'Forums',
	'MEILISEARCH_FORUMS_COL_NAME'		=> 'Forum',
	'MEILISEARCH_FORUMS_EXCLUDE'		=> 'Exclude from index',
	'MEILISEARCH_FORUMS_INDEXED'		=> 'Indexed',
	'MEILISEARCH_FORUMS_EXCLUDED'		=> 'Excluded',
	'MEILISEARCH_FORUMS_NONE'			=> 'No forums found.',
	'MEILISEARCH_FORUMS_NO_INDEXING'	=> 'search indexing disabled in forum settings',
	'MEILISEARCH_FORUMS_PRESELECT'		=> 'Preselect forums guests cannot read',
	'MEILISEARCH_FORUMS_PRESELECT_NOTE'	=> 'The list below now shows the suggested selection. <strong>Nothing has been saved yet</strong> &mdash; review it and press Submit to apply.',
	'MEILISEARCH_FORUMS_PRESELECTED'	=> 'Suggested selection loaded into the form.',
	'MEILISEARCH_FORUMS_PURGE'			=> 'Remove excluded forums from the index',
	'MEILISEARCH_FORUMS_SAVED'			=> 'The exclusion list has been saved. Posts already indexed from a newly excluded forum are still in the index: use &ldquo;Remove excluded forums from the index&rdquo; to evict them.',
	'MEILISEARCH_FORUMS_PURGED'			=> 'Documents belonging to excluded forums have been removed from the index.',
	'MEILISEARCH_STAT_EXCLUDED'			=> 'Excluded forums',

	'LOG_MEILISEARCH_FORUMS_SAVED'		=> '<strong>Meilisearch forum exclusion list updated</strong>',
	'LOG_MEILISEARCH_FORUMS_PURGED'		=> '<strong>Meilisearch excluded forums evicted from index</strong>',
	// API key generation
	'MEILISEARCH_KEY_SECTION'			=> 'API key',
	'MEILISEARCH_KEY_EXPLAIN'			=> 'A key is only needed when Meilisearch is reachable over the network. If it runs on this server bound to <samp>127.0.0.1</samp> and was started without a master key, leave the key field empty &mdash; the loopback binding is the security boundary. Otherwise, create the key here rather than obtaining one from anyone. The generated key is scoped to this board&rsquo;s index and to the actions this extension actually performs, so a leaked key cannot be used to drop other indexes or read other boards.',
	'MEILISEARCH_KEY_CURRENT'			=> 'Stored key',
	'MEILISEARCH_KEY_PRESENT'			=> 'A key is configured.',
	'MEILISEARCH_KEY_ABSENT'			=> 'No key configured. This is correct if Meilisearch runs on this same server bound to 127.0.0.1 without a master key &mdash; leave it empty and ignore the generator below. A key is only required when the instance is reachable over the network.',
	'MEILISEARCH_KEY_PERMISSIONS'		=> 'Permissions requested',
	'MEILISEARCH_KEY_GENERATE'			=> 'Generate a new API key',
	'MEILISEARCH_KEY_MASTER'			=> 'Master key',
	'MEILISEARCH_KEY_MASTER_EXPLAIN'	=> 'The value you passed as <samp>MEILI_MASTER_KEY</samp> when starting Meilisearch. If you no longer have it, inspect the running container&rsquo;s environment or restart it with a new one.',
	'MEILISEARCH_KEY_MASTER_WARNING'	=> 'The master key is used for this single request and is <strong>never saved</strong>. Only the generated key is written to the board configuration. Do not put the master key in the Search settings field.',
	'MEILISEARCH_KEY_GENERATE_BUTTON'	=> 'Generate and save API key',
	'MEILISEARCH_KEY_MASTER_REQUIRED'	=> 'Enter the Meilisearch master key to generate an API key.',
	'MEILISEARCH_KEY_GENERATED'			=> 'A new API key has been created and saved. Any key generated previously is still valid on the Meilisearch instance; revoke it there if it is no longer needed.',

	'LOG_MEILISEARCH_KEY_GENERATED'		=> '<strong>Meilisearch API key generated</strong>',
	// Front-end search notice
	'MEILISEARCH_BANNER'				=> 'Show a notice on the search pages',
	'MEILISEARCH_BANNER_EXPLAIN'		=> 'Displays a short line at the top of the advanced search form and the results page telling users which search engine is in use. Visible to all users. The notice is hidden automatically whenever Meilisearch is not the active backend, so it can never claim something untrue.',
	'MEILISEARCH_BANNER_TEXT'			=> 'Search on this board is powered by Meilisearch: typos are tolerated and short words such as abbreviations and product codes can be found.',
	// Diagnostic test buttons
	'MEILISEARCH_TEST_CONN'				=> 'Test the connection',
	'MEILISEARCH_TEST_CONN_EXPLAIN'		=> 'Calls the health endpoint and reports the round-trip time. Useful for spotting a link that works but is slow enough to hurt page rendering.',
	'MEILISEARCH_TEST_CONN_BUTTON'		=> 'Run connection test',
	'MEILISEARCH_TEST_CONN_OK'			=> 'Connection OK. Meilisearch answered in %1$s ms (%2$s ms including overhead).',
	'MEILISEARCH_TEST_CONN_FAIL'		=> 'Connection test failed. %s',

	'MEILISEARCH_TEST_INDEX'			=> 'Test the index',
	'MEILISEARCH_TEST_INDEX_EXPLAIN'	=> 'Runs a real query against the index rather than just reading statistics. This is the only check that exercises the same path the search page uses.',
	'MEILISEARCH_TEST_INDEX_BUTTON'		=> 'Run index test',
	'MEILISEARCH_TEST_INDEX_OK'			=> 'Index OK. The query matched %1$d documents in %2$s ms.',
	'MEILISEARCH_TEST_INDEX_FAIL'		=> 'Index test failed. %s',

	'MEILISEARCH_TEST_KEY'				=> 'Test the API key',
	'MEILISEARCH_TEST_KEY_EXPLAIN'		=> 'Tries each operation the extension actually performs. Catches a key that exists but lacks a permission, which would otherwise only surface halfway through a reindex.',
	'MEILISEARCH_TEST_KEY_BUTTON'		=> 'Run key test',
	'MEILISEARCH_TEST_KEY_OK'			=> 'API key OK. All required operations succeeded: %s.',
	'MEILISEARCH_TEST_KEY_FAIL'			=> 'The API key was rejected for: %1$s. Generate a new key below. Last error: %2$s',
));
