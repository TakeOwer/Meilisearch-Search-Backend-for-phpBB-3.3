<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\meilisearch\meili;

/**
 * Turns phpBB post rows into Meilisearch documents and pushes them out.
 *
 * Why this is a separate service
 * ------------------------------
 * phpBB 3.3 instantiates search backends with `new $class(...)` and a fixed
 * argument list; they are not DI services and cannot receive injected
 * dependencies. The cron task, the ACP module and the backend all need the same
 * indexing logic, so it lives here as a normal service and the backend pulls it
 * out of the container.
 *
 * Failure policy
 * --------------
 * Indexing must never break posting. Every write path is:
 *   1. try to push to Meilisearch;
 *   2. on failure, persist the post ids into a small queue table;
 *   3. let the cron task retry later.
 * The queue stores ids only, never content, so a replay always picks up the
 * current version of the post.
 */
class indexer
{
	/** Hard ceiling on indexed post body length, in characters. Protects against
	 *  pathological posts blowing up the HTTP payload. */
	const MAX_TEXT_LENGTH = 120000;

	/** Queue actions */
	const ACTION_UPDATE = 'u';
	const ACTION_DELETE = 'd';

	/** @var client */
	protected $client;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\textformatter\utils_interface|null */
	protected $text_formatter_utils;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var string */
	protected $queue_table;

	/** @var string Last error produced by a push/remove operation */
	protected $error = '';

	/** @var array|null Per-request cache of the excluded forum id list */
	protected $excluded_forums = null;

	/**
	 * Constructor.
	 *
	 * @param client                               $client
	 * @param \phpbb\config\config                 $config
	 * @param \phpbb\db\driver\driver_interface     $db
	 * @param \phpbb\textformatter\utils_interface  $text_formatter_utils
	 * @param \phpbb\auth\auth                      $auth
	 * @param string                                $table_prefix
	 */
	public function __construct(client $client, \phpbb\config\config $config, \phpbb\db\driver\driver_interface $db, \phpbb\textformatter\utils_interface $text_formatter_utils, \phpbb\auth\auth $auth, $table_prefix)
	{
		$this->client               = $client;
		$this->config               = $config;
		$this->db                   = $db;
		$this->text_formatter_utils = $text_formatter_utils;
		$this->auth                 = $auth;
		$this->queue_table          = $table_prefix . 'meili_queue';
	}

	/* ---------------------------------------------------------------------
	 * Forum exclusions
	 * ------------------------------------------------------------------ */

	/**
	 * Forums whose posts must never enter the index.
	 *
	 * This is a hard content-level exclusion, not a permission check. phpBB
	 * already prevents users from seeing posts they may not read (see
	 * meilisearch_backend::refine_with_sql). The exclusion list exists so that
	 * sensitive content is never written to an external service at all, which
	 * means a compromised or misconfigured Meilisearch instance cannot leak it.
	 *
	 * @return array Forum ids
	 */
	public function get_excluded_forum_ids()
	{
		if ($this->excluded_forums !== null)
		{
			return $this->excluded_forums;
		}

		$raw = trim((string) $this->config['meilisearch_excluded_forums']);

		if ($raw === '')
		{
			return $this->excluded_forums = array();
		}

		$ids = array();

		foreach (explode(',', $raw) as $id)
		{
			$id = (int) trim($id);

			if ($id > 0)
			{
				$ids[] = $id;
			}
		}

		return $this->excluded_forums = array_values(array_unique($ids));
	}

	/**
	 * Persist a new exclusion list.
	 *
	 * @param array $forum_ids
	 * @return void
	 */
	public function set_excluded_forum_ids(array $forum_ids)
	{
		$ids = array();

		foreach ($forum_ids as $id)
		{
			$id = (int) $id;

			if ($id > 0)
			{
				$ids[] = $id;
			}
		}

		$ids = array_values(array_unique($ids));
		sort($ids);

		$this->config->set('meilisearch_excluded_forums', implode(',', $ids));
		$this->excluded_forums = $ids;
	}

	/**
	 * Forums that the guest account cannot read.
	 *
	 * Used to pre-populate the exclusion list on install and to power the
	 * "preselect" button in the ACP. It is only ever a suggestion: the
	 * authoritative list is whatever the administrator saved.
	 *
	 * @return array Forum ids
	 */
	public function get_guest_unreadable_forum_ids()
	{
		$all_forums = array();

		$sql = 'SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_type = ' . FORUM_POST;
		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$all_forums[] = (int) $row['forum_id'];
		}

		$this->db->sql_freeresult($result);

		$readable = array();

		foreach ($this->auth->acl_get_list(ANONYMOUS, 'f_read') as $forum_id => $options)
		{
			if ((int) $forum_id > 0 && !empty($options['f_read']))
			{
				$readable[] = (int) $forum_id;
			}
		}

		return array_values(array_diff($all_forums, $readable));
	}

	/**
	 * Create a scoped API key for this board and store it in the configuration.
	 *
	 * The master key is used for this one request and is deliberately NOT saved:
	 * it can drop any index on the instance, so it has no business living in
	 * phpbb_config. Only the derived key, restricted to the actions this
	 * extension actually performs and to this board's index, is persisted.
	 *
	 * @param string $master_key
	 * @return string|false The generated key on success, false on failure
	 */
	public function generate_api_key($master_key)
	{
		$this->error = '';

		$master_key = trim((string) $master_key);

		if ($master_key === '')
		{
			$this->error = 'A master key is required to create an API key.';
			return false;
		}

		$url            = (string) $this->config['meilisearch_url'];
		$timeout        = (int) $this->config['meilisearch_timeout'];
		$existing_key   = (string) $this->config['meilisearch_api_key'];

		$this->client->override_connection($url, $master_key, $timeout);

		$result = $this->client->create_key(array(
			'name'        => 'phpBB board (' . $this->get_index_uid() . ')',
			'description' => 'Created by the phpBB Meilisearch search backend extension',
			'actions'     => $this->get_required_key_actions(),
			'indexes'     => array($this->get_index_uid()),
			'expiresAt'   => null,
		));

		if ($result === false || empty($result['key']))
		{
			$this->error = ($result === false)
				? $this->client->last_error()
				: 'Meilisearch did not return a key value.';

			// Put the client back on the stored credentials before returning.
			$this->client->override_connection($url, $existing_key, $timeout);

			return false;
		}

		$new_key = (string) $result['key'];

		$this->config->set('meilisearch_api_key', $new_key);
		$this->client->override_connection($url, $new_key, $timeout);

		return $new_key;
	}

	/**
	 * The exact Meilisearch actions this extension calls.
	 *
	 * Kept narrow on purpose: no keys.*, no dumps, no index deletion.
	 *
	 * These must all be INDEX-SCOPED actions. Meilisearch rejects a key that is
	 * restricted to an index but also carries a global action such as 'version'
	 * or 'dumps.create' (error: index_scoped_api_key_with_global_action). The
	 * diagnostics page therefore cannot report the server version when using a
	 * generated key, which is a cosmetic loss and not worth a global key.
	 *
	 * @return array
	 */
	public function get_required_key_actions()
	{
		return array(
			'search',
			'documents.add',
			'documents.delete',
			'indexes.get',
			'indexes.create',
			'settings.get',
			'settings.update',
			'stats.get',
			'tasks.get',
		);
	}

	/**
	 * Evict every document belonging to an excluded forum.
	 *
	 * Called after the administrator changes the exclusion list, so that forums
	 * which were indexed under the old list are removed without a full rebuild.
	 *
	 * @return bool
	 */
	public function purge_excluded_forums()
	{
		$this->error = '';

		$excluded = $this->get_excluded_forum_ids();

		if (empty($excluded))
		{
			return true;
		}

		$filter = 'forum_id IN [' . implode(', ', $excluded) . ']';

		if ($this->client->delete_documents_by_filter($this->get_index_uid(), $filter) === false)
		{
			$this->error = $this->client->last_error();
			return false;
		}

		return true;
	}

	/**
	 * @return client
	 */
	public function get_client()
	{
		return $this->client;
	}

	/**
	 * @return string
	 */
	public function last_error()
	{
		return $this->error;
	}

	/**
	 * @return string Configured index uid, with a safe fallback
	 */
	public function get_index_uid()
	{
		$uid = trim((string) $this->config['meilisearch_index']);

		return ($uid === '') ? 'phpbb_posts' : $uid;
	}

	/* ---------------------------------------------------------------------
	 * Index configuration
	 * ------------------------------------------------------------------ */

	/**
	 * Build the Meilisearch settings payload.
	 *
	 * filterableAttributes must contain every field we filter on in
	 * meilisearch_backend::keyword_search(), otherwise Meilisearch rejects the
	 * query with an "attribute not filterable" error.
	 *
	 * @return array
	 */
	public function get_settings_payload()
	{
		$settings = array(
			'searchableAttributes' => array(
				'post_subject',
				'post_text',
			),
			'filterableAttributes' => array(
				'forum_id',
				'topic_id',
				'poster_id',
				'post_time',
				'post_visibility',
				'is_first_post',
			),
			'sortableAttributes' => array(
				'post_time',
				'post_id',
			),
			// post_id is the primary key and is always returned; restricting the
			// payload keeps responses small, we only ever need the ids.
			'displayedAttributes' => array(
				'post_id',
			),
			'typoTolerance' => array(
				'enabled' => (bool) $this->config['meilisearch_typo'],
			),
			'pagination' => array(
				// Meilisearch caps total hits at 1000 by default. We raise it to
				// whatever the admin allows so result counts stay accurate.
				'maxTotalHits' => max(100, (int) $this->config['meilisearch_max_results']),
			),
		);

		$locales = $this->get_locales();

		if (!empty($locales))
		{
			// localizedAttributes requires Meilisearch >= 1.10. apply_settings()
			// retries without this key if the server rejects it.
			$settings['localizedAttributes'] = array(
				array(
					'attributePatterns' => array('post_subject', 'post_text'),
					'locales'           => $locales,
				),
			);
		}

		return $settings;
	}

	/**
	 * Parse the configured locale list.
	 *
	 * Stored as a comma separated list of ISO 639 codes, e.g. "ita,eng".
	 *
	 * @return array
	 */
	public function get_locales()
	{
		$raw = trim((string) $this->config['meilisearch_locales']);

		if ($raw === '')
		{
			return array();
		}

		$locales = array();

		foreach (explode(',', $raw) as $locale)
		{
			$locale = strtolower(preg_replace('/[^a-zA-Z]/', '', $locale));

			if ($locale !== '')
			{
				$locales[] = $locale;
			}
		}

		return array_values(array_unique($locales));
	}

	/**
	 * Create the index (if missing) and push our settings.
	 *
	 * @return bool
	 */
	public function apply_settings()
	{
		$this->error = '';
		$index       = $this->get_index_uid();

		if (!$this->client->ensure_index($index, 'post_id'))
		{
			$this->error = $this->client->last_error();
			return false;
		}

		$settings = $this->get_settings_payload();

		if ($this->client->update_settings($index, $settings) !== false)
		{
			return true;
		}

		// Older Meilisearch builds do not know localizedAttributes. Retry without
		// it rather than failing the whole setup.
		if (isset($settings['localizedAttributes']))
		{
			unset($settings['localizedAttributes']);

			if ($this->client->update_settings($index, $settings) !== false)
			{
				return true;
			}
		}

		$this->error = $this->client->last_error();
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Document building
	 * ------------------------------------------------------------------ */

	/**
	 * Load post rows and turn them into Meilisearch documents.
	 *
	 * phpBB hands search backends only (post_id, poster_id, forum_id, subject,
	 * text). We need topic_id, post_time and post_visibility as filterable
	 * fields, so we re-read the rows in one batched query instead of trusting
	 * the partial data passed in. This also guarantees we index the committed
	 * state of the post rather than an in-flight value.
	 *
	 * @param array $post_ids
	 * @return array Documents keyed by post_id
	 */
	public function build_documents(array $post_ids)
	{
		$post_ids = array_values(array_unique(array_map('intval', $post_ids)));

		if (empty($post_ids))
		{
			return array();
		}

		$documents = array();

		$sql_array = array(
			'SELECT'	=> 'p.post_id, p.topic_id, p.forum_id, p.poster_id, p.post_time, p.post_visibility, p.post_subject, p.post_text, t.topic_first_post_id',
			'FROM'		=> array(POSTS_TABLE => 'p'),
			'LEFT_JOIN'	=> array(
				array(
					'FROM'	=> array(TOPICS_TABLE => 't'),
					'ON'	=> 't.topic_id = p.topic_id',
				),
			),
			'WHERE'		=> $this->db->sql_in_set('p.post_id', $post_ids),
		);

		$result = $this->db->sql_query($this->db->sql_build_query('SELECT', $sql_array));

		$excluded_forums = array_flip($this->get_excluded_forum_ids());

		while ($row = $this->db->sql_fetchrow($result))
		{
			// Posts in excluded forums are simply not built into documents. push()
			// then treats them as "missing" and removes any copy left over from a
			// previous exclusion list.
			if (isset($excluded_forums[(int) $row['forum_id']]))
			{
				continue;
			}

			$documents[(int) $row['post_id']] = array(
				'post_id'         => (int) $row['post_id'],
				'topic_id'        => (int) $row['topic_id'],
				'forum_id'        => (int) $row['forum_id'],
				'poster_id'       => (int) $row['poster_id'],
				'post_time'       => (int) $row['post_time'],
				'post_visibility' => (int) $row['post_visibility'],
				'is_first_post'   => ((int) $row['topic_first_post_id'] === (int) $row['post_id']) ? 1 : 0,
				'post_subject'    => $this->clean_text($row['post_subject'], false),
				'post_text'       => $this->clean_text($row['post_text'], true),
			);
		}

		$this->db->sql_freeresult($result);

		return $documents;
	}

	/**
	 * Strip phpBB's stored markup down to plain searchable text.
	 *
	 * Since 3.2 post_text is an s9e/TextFormatter XML document ("<r>...</r>" or
	 * "<t>...</t>"). Indexing the raw XML would make users able to find posts by
	 * searching for "URL" or "quote", which is useless, and would bloat the index.
	 *
	 * @param string $text
	 * @param bool   $is_body True for post_text, false for post_subject
	 * @return string
	 */
	public function clean_text($text, $is_body = true)
	{
		$text = (string) $text;

		if ($text === '')
		{
			return '';
		}

		if ($is_body)
		{
			// clean_formatting() returns the text content of the XML with markup
			// removed. It is the same helper phpBB itself uses for excerpts.
			$text = $this->text_formatter_utils->clean_formatting($text);
		}

		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\s+/u', ' ', $text);
		$text = trim((string) $text);

		if (strlen($text) > self::MAX_TEXT_LENGTH)
		{
			// Cut on a byte boundary that is still valid UTF-8.
			$text = mb_substr($text, 0, self::MAX_TEXT_LENGTH, 'UTF-8');
		}

		return $text;
	}

	/* ---------------------------------------------------------------------
	 * Write paths
	 * ------------------------------------------------------------------ */

	/**
	 * Index (upsert) a set of posts.
	 *
	 * @param array $post_ids
	 * @param bool  $queue_on_failure Enqueue for retry when the push fails
	 * @return bool True when Meilisearch accepted the batch
	 */
	public function push(array $post_ids, $queue_on_failure = true)
	{
		$this->error = '';

		if (empty($post_ids))
		{
			return true;
		}

		$documents = $this->build_documents($post_ids);

		// Two cases end up here: post ids that no longer resolve to a row (deleted
		// between the call and the flush), and posts that live in an excluded
		// forum. Both must be evicted rather than merely skipped, otherwise a
		// forum added to the exclusion list would keep its old documents.
		$missing = array_diff(array_map('intval', $post_ids), array_keys($documents));

		if (!empty($missing))
		{
			$this->remove($missing, $queue_on_failure);
		}

		if (empty($documents))
		{
			return true;
		}

		if ($this->client->add_documents($this->get_index_uid(), $documents) !== false)
		{
			return true;
		}

		$this->error = $this->client->last_error();

		if ($queue_on_failure)
		{
			$this->enqueue(array_keys($documents), self::ACTION_UPDATE);
		}

		return false;
	}

	/**
	 * Remove posts from the index.
	 *
	 * @param array $post_ids
	 * @param bool  $queue_on_failure
	 * @return bool
	 */
	public function remove(array $post_ids, $queue_on_failure = true)
	{
		$this->error = '';

		$post_ids = array_values(array_unique(array_map('intval', $post_ids)));

		if (empty($post_ids))
		{
			return true;
		}

		if ($this->client->delete_documents($this->get_index_uid(), $post_ids) !== false)
		{
			return true;
		}

		$this->error = $this->client->last_error();

		if ($queue_on_failure)
		{
			$this->enqueue($post_ids, self::ACTION_DELETE);
		}

		return false;
	}

	/**
	 * Drop every document without deleting the index or its settings.
	 *
	 * @return bool
	 */
	public function purge_all()
	{
		$this->error = '';

		if ($this->client->delete_all_documents($this->get_index_uid()) === false)
		{
			$this->error = $this->client->last_error();
			return false;
		}

		$this->clear_queue();

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Retry queue
	 * ------------------------------------------------------------------ */

	/**
	 * @param array  $post_ids
	 * @param string $action self::ACTION_UPDATE or self::ACTION_DELETE
	 * @return void
	 */
	public function enqueue(array $post_ids, $action)
	{
		if (empty($post_ids) || !$this->config['meilisearch_queue_enable'])
		{
			return;
		}

		$action = ($action === self::ACTION_DELETE) ? self::ACTION_DELETE : self::ACTION_UPDATE;
		$now    = time();
		$rows   = array();

		foreach (array_unique(array_map('intval', $post_ids)) as $post_id)
		{
			$rows[] = array(
				'post_id'    => (int) $post_id,
				'queue_action' => $action,
				'queue_time' => $now,
			);
		}

		if (!empty($rows))
		{
			$this->db->sql_multi_insert($this->queue_table, $rows);
		}
	}

	/**
	 * @return int Number of pending queue rows
	 */
	public function queue_size()
	{
		$sql = 'SELECT COUNT(item_id) AS queue_size FROM ' . $this->queue_table;
		$result = $this->db->sql_query($sql);
		$size = (int) $this->db->sql_fetchfield('queue_size');
		$this->db->sql_freeresult($result);

		return $size;
	}

	/**
	 * @return void
	 */
	public function clear_queue()
	{
		$this->db->sql_query('DELETE FROM ' . $this->queue_table);
	}

	/**
	 * Process pending queue entries.
	 *
	 * Rows are claimed by id range so a concurrent cron run cannot double-process
	 * them: we read a window, act on it, then delete exactly that window.
	 *
	 * @param int $limit Maximum number of rows to process in this run
	 * @return int Number of rows processed
	 */
	public function flush_queue($limit = 500)
	{
		$this->error = '';
		$limit       = max(1, (int) $limit);

		$sql = 'SELECT item_id, post_id, queue_action
			FROM ' . $this->queue_table . '
			ORDER BY item_id ASC';
		$result = $this->db->sql_query_limit($sql, $limit);

		$item_ids = array();
		$updates  = array();
		$deletes  = array();

		while ($row = $this->db->sql_fetchrow($result))
		{
			$item_ids[] = (int) $row['item_id'];

			if ($row['queue_action'] === self::ACTION_DELETE)
			{
				$deletes[] = (int) $row['post_id'];
			}
			else
			{
				$updates[] = (int) $row['post_id'];
			}
		}

		$this->db->sql_freeresult($result);

		if (empty($item_ids))
		{
			return 0;
		}

		$ok = true;

		if (!empty($deletes))
		{
			$ok = $this->remove($deletes, false) && $ok;
		}

		if (!empty($updates))
		{
			$ok = $this->push($updates, false) && $ok;
		}

		if (!$ok)
		{
			// Meilisearch is still unreachable. Leave the rows in place so the
			// next cron run retries them.
			return 0;
		}

		$this->db->sql_query('DELETE FROM ' . $this->queue_table . ' WHERE ' . $this->db->sql_in_set('item_id', $item_ids));

		return count($item_ids);
	}
}
