<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\meilisearch\search;

/**
 * Meilisearch search backend for phpBB 3.3.
 *
 * How phpBB discovers this class
 * ------------------------------
 * includes/acp/acp_search.php::get_search_types() runs the extension finder with
 *   ->extension_suffix('_backend')->extension_directory('/search')
 * so ANY class living in ext/<vendor>/<ext>/search/ whose file name ends in
 * "_backend.php" is offered in ACP -> General -> Board configuration -> Search
 * settings. The class is then instantiated with a fixed positional argument list
 * (see __construct) via `new $search_type(...)` in search.php and
 * includes/functions_admin.php. It is NOT a DI service, which is why heavier
 * dependencies are pulled from the container by hand.
 *
 * The two-stage query model
 * -------------------------
 * Meilisearch is used ONLY for relevance matching. It answers the question
 * "which post ids contain these words". Everything that touches security or
 * ordering is then re-applied in SQL against phpBB's own tables:
 *
 *   1. Meilisearch  -> candidate post ids (ranked, capped at meilisearch_max_results)
 *   2. SQL          -> AND p.post_id IN (candidates)
 *                      AND <$post_visibility>          <-- moderator approval rules
 *                      AND p.forum_id NOT IN (...)     <-- forum permissions
 *                      AND <author / topic / date filters>
 *                      ORDER BY <phpBB sort key>
 *
 * This costs one extra SQL round trip but makes it structurally impossible for a
 * permissions bug in the external engine to leak hidden posts: phpBB's own WHERE
 * clause is always the last word. Reimplementing post_visibility inside
 * Meilisearch filters would be faster and considerably more dangerous.
 *
 * The trade-off is that results are capped: only the top N Meilisearch hits are
 * considered. N is admin-configurable (meilisearch_max_results, default 1000).
 */
class meilisearch_backend extends \phpbb\search\base
{
	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\event\dispatcher_interface */
	protected $phpbb_dispatcher;

	/** @var \salvocortesiano\meilisearch\meili\indexer|null */
	protected $indexer = null;

	/** @var \salvocortesiano\meilisearch\meili\client|null */
	protected $client = null;

	/** @var array Words the user typed, after cleaning */
	protected $split_words = array();

	/** @var string Query string handed to Meilisearch */
	protected $search_query = '';

	/** @var array Words dropped for being too short/long */
	protected $common_words = array();

	/** @var array ['min' => int, 'max' => int] */
	protected $word_length = array();

	/** @var array Buffered post ids waiting to be pushed to Meilisearch */
	protected $index_buffer = array();

	/** @var array Buffered poster ids, used to invalidate the result cache once per flush */
	protected $index_buffer_authors = array();

	/** @var array Cached index statistics */
	protected $stats = array();

	/**
	 * Constructor.
	 *
	 * The signature is dictated by phpBB and must not be changed.
	 *
	 * @param string|bool                        $error            Set to an error message, or false on success
	 * @param string                             $phpbb_root_path
	 * @param string                             $phpEx
	 * @param \phpbb\auth\auth                   $auth
	 * @param \phpbb\config\config               $config
	 * @param \phpbb\db\driver\driver_interface   $db
	 * @param \phpbb\user                        $user
	 * @param \phpbb\event\dispatcher_interface   $phpbb_dispatcher
	 */
	public function __construct(&$error, $phpbb_root_path, $phpEx, $auth, $config, $db, $user, $phpbb_dispatcher)
	{
		global $phpbb_container;

		$this->auth             = $auth;
		$this->config           = $config;
		$this->db               = $db;
		$this->user             = $user;
		$this->phpbb_dispatcher = $phpbb_dispatcher;

		$this->word_length = array(
			'min' => max(1, (int) $config['meilisearch_min_chars']),
			'max' => max(1, (int) $config['meilisearch_max_chars']),
		);

		if (method_exists($user, 'add_lang_ext'))
		{
			$user->add_lang_ext('salvocortesiano/meilisearch', 'common');
		}

		if (!function_exists('utf8_strlen'))
		{
			include($phpbb_root_path . 'includes/utf/utf_tools.' . $phpEx);
		}

		// The extension can be disabled while still being the selected search
		// backend: phpBB's ext class loader resolves the class from disk either
		// way, but the DI services are gone. Fail loudly instead of fatalling.
		try
		{
			$this->indexer = $phpbb_container->get('salvocortesiano.meilisearch.indexer');
			$this->client  = $this->indexer->get_client();
		}
		catch (\Exception $e)
		{
			$error = isset($user->lang['MEILISEARCH_EXT_DISABLED'])
				? $user->lang['MEILISEARCH_EXT_DISABLED']
				: 'The Meilisearch extension is not enabled; its services are unavailable.';

			return;
		}

		$error = false;
	}

	/**
	 * Belt and braces: make sure a partially filled buffer is not silently lost
	 * if a caller forgets to invoke tidy().
	 */
	public function __destruct()
	{
		try
		{
			$this->flush_index_buffer();
		}
		catch (\Throwable $e)
		{
			// Shutdown ordering can leave us without a usable DB handle. There is
			// nothing sensible to do here; the retry queue covers the gap.
		}
	}

	/**
	 * Name shown in the ACP backend selector.
	 *
	 * @return string
	 */
	public function get_name()
	{
		return 'Meilisearch';
	}

	/**
	 * @return string
	 */
	public function get_search_query()
	{
		return $this->search_query;
	}

	/**
	 * @return array
	 */
	public function get_common_words()
	{
		return $this->common_words;
	}

	/**
	 * @return array
	 */
	public function get_word_length()
	{
		return $this->word_length;
	}

	/**
	 * Validate the backend before phpBB switches to it.
	 *
	 * Called by acp_search when the admin selects this backend and by
	 * create_index/delete_index. Returning a non-empty string aborts the switch
	 * and shows the string as an error.
	 *
	 * @return string|bool false on success, error message otherwise
	 */
	public function init()
	{
		if ($this->indexer === null || $this->client === null)
		{
			return $this->lang('MEILISEARCH_EXT_DISABLED');
		}

		if (!function_exists('curl_init'))
		{
			return $this->lang('MEILISEARCH_NO_CURL');
		}

		if (!$this->client->is_configured())
		{
			return $this->lang('MEILISEARCH_NO_URL');
		}

		if ($this->client->health() === false)
		{
			return $this->lang('MEILISEARCH_UNREACHABLE') . ' ' . $this->client->last_error();
		}

		if (!$this->indexer->apply_settings())
		{
			return $this->lang('MEILISEARCH_SETTINGS_FAILED') . ' ' . $this->indexer->last_error();
		}

		return false;
	}

	/* =====================================================================
	 * Query side
	 * ================================================================== */

	/**
	 * Normalise the user's query.
	 *
	 * Meilisearch has its own query syntax and does NOT understand phpBB's
	 * +word/-word/| boolean operators. We translate what maps cleanly:
	 *
	 *   "exact phrase"  -> kept verbatim, Meilisearch supports phrase search
	 *   -word           -> kept verbatim, Meilisearch supports negation
	 *   +word           -> the plus is stripped (Meilisearch is AND-by-default
	 *                      for ranking purposes, so a leading + is a no-op)
	 *   word | word     -> the pipe is dropped; Meilisearch ranks documents
	 *                      matching more terms higher, which is the practical
	 *                      equivalent of an OR search
	 *   *               -> stripped, wildcards are implicit via prefix search
	 *
	 * @param string $keywords Passed by reference; phpBB echoes it back into the form
	 * @param string $terms    'all' or 'any'
	 * @return bool false when nothing usable is left
	 */
	public function split_keywords(&$keywords, $terms)
	{
		$keywords = trim((string) $keywords);

		if ($keywords === '')
		{
			return false;
		}

		// Preserve quoted phrases while we tokenise the rest.
		$phrases = array();

		$stripped = preg_replace_callback('/"([^"]+)"/u', function ($matches) use (&$phrases) {
			$phrases[] = trim($matches[1]);
			return ' ';
		}, $keywords);

		$stripped = str_replace(array('+', '|', '*', '(', ')'), ' ', (string) $stripped);
		$stripped = preg_replace('/\s+/u', ' ', $stripped);

		$this->split_words  = array();
		$this->common_words = array();

		$negations = array();

		foreach (explode(' ', trim((string) $stripped)) as $token)
		{
			$token = trim($token);

			if ($token === '' || $token === '-')
			{
				continue;
			}

			$negated = ($token[0] === '-');
			$word    = $negated ? substr($token, 1) : $token;
			$length  = utf8_strlen($word);

			if ($length < $this->word_length['min'] || $length > $this->word_length['max'])
			{
				$this->common_words[] = $word;
				continue;
			}

			if ($negated)
			{
				$negations[] = '-' . $word;
			}
			else
			{
				$this->split_words[] = $word;
			}
		}

		foreach ($phrases as $phrase)
		{
			if ($phrase !== '')
			{
				$this->split_words[] = $phrase;
			}
		}

		if (empty($this->split_words))
		{
			$this->search_query = '';
			return false;
		}

		$max_keywords = (int) $this->config['max_num_search_keywords'];

		if ($max_keywords > 0 && count($this->split_words) > $max_keywords)
		{
			$this->split_words = array_slice($this->split_words, 0, $max_keywords);
		}

		$query_parts = array();

		foreach ($this->split_words as $word)
		{
			// Re-quote multi-word phrases so Meilisearch treats them as phrases.
			$query_parts[] = (strpos($word, ' ') !== false) ? '"' . $word . '"' : $word;
		}

		$this->search_query = trim(implode(' ', array_merge($query_parts, $negations)));

		return true;
	}

	/**
	 * Keyword search.
	 *
	 * Argument list is dictated by phpBB, see \phpbb\search\fulltext_mysql.
	 *
	 * @param string $type            'posts' or 'topics'
	 * @param string $fields          'titleonly'|'msgonly'|'firstpost'|'all'
	 * @param string $terms           'all' or 'any'
	 * @param array  $sort_by_sql     Map of sort key => SQL column
	 * @param string $sort_key        Selected key of $sort_by_sql
	 * @param string $sort_dir        'a' or 'd'
	 * @param string $sort_days       Max post age in days, 0 for no limit
	 * @param array  $ex_fid_ary      Forum ids to exclude
	 * @param string $post_visibility Raw SQL fragment enforcing approval rules
	 * @param int    $topic_id        Restrict to a topic, or 0
	 * @param array  $author_ary      Restrict to these poster ids
	 * @param string $author_name     SQL LIKE fragment for guest post_username
	 * @param array  $id_ary          Out: ids for the requested page
	 * @param int    $start           In/out: offset
	 * @param int    $per_page
	 * @return int|bool Total result count, or false when there is nothing
	 */
	public function keyword_search($type, $fields, $terms, $sort_by_sql, $sort_key, $sort_dir, $sort_days, $ex_fid_ary, $post_visibility, $topic_id, $author_ary, $author_name, &$id_ary, &$start, $per_page)
	{
		if ($this->search_query === '' || $this->indexer === null)
		{
			return false;
		}

		$search_key_array = array(
			'meilisearch',
			implode(', ', $this->split_words),
			$type,
			$fields,
			$terms,
			$sort_days,
			$sort_key,
			$sort_dir,
			$topic_id,
			implode(',', $ex_fid_ary),
			$post_visibility,
			implode(',', $author_ary),
		);

		/**
		 * Allow changing the search key used to cache Meilisearch results.
		 *
		 * @event salvocortesiano.meilisearch.modify_search_key
		 * @var array  search_key_array Components of the cache key
		 * @var string type             'posts' or 'topics'
		 * @var string fields           Search fields selector
		 * @var array  ex_fid_ary       Excluded forum ids
		 * @since 1.0.0
		 */
		$vars = array('search_key_array', 'type', 'fields', 'ex_fid_ary');
		extract($this->phpbb_dispatcher->trigger_event('salvocortesiano.meilisearch.modify_search_key', compact($vars)));

		$search_key = md5(implode('#', $search_key_array));

		if ($start < 0)
		{
			$start = 0;
		}

		$result_count = 0;

		if ($this->obtain_ids($search_key, $result_count, $id_ary, $start, $per_page, $sort_dir) == SEARCH_RESULT_IN_CACHE)
		{
			return $result_count;
		}

		$id_ary = array();

		$ranked_post_ids = $this->query_meilisearch($fields, $ex_fid_ary, $topic_id, $author_ary, $author_name, $sort_days);

		if (empty($ranked_post_ids))
		{
			return false;
		}

		$ordered_ids = $this->refine_with_sql($ranked_post_ids, $type, $fields, $sort_by_sql, $sort_key, $sort_dir, $sort_days, $ex_fid_ary, $post_visibility, $topic_id, $author_ary, $author_name);

		$result_count = count($ordered_ids);

		if (!$result_count)
		{
			return false;
		}

		if ($start >= $result_count)
		{
			$start = (int) (floor(($result_count - 1) / $per_page) * $per_page);
		}

		$block = array_slice($ordered_ids, $start, (int) $this->config['search_block_size']);

		$this->save_ids($search_key, implode(' ', $this->split_words), $author_ary, $result_count, $block, $start, $sort_dir);

		$id_ary = array_slice($block, 0, (int) $per_page);

		return $result_count;
	}

	/**
	 * Ask Meilisearch for candidate post ids.
	 *
	 * Filters pushed down to Meilisearch are the ones that cheaply shrink the
	 * candidate set (forum, topic, author, age). post_visibility is deliberately
	 * NOT pushed down; SQL enforces it.
	 *
	 * @param string $fields
	 * @param array  $ex_fid_ary
	 * @param int    $topic_id
	 * @param array  $author_ary
	 * @param string $author_name
	 * @param string $sort_days
	 * @return array post ids in relevance order
	 */
	protected function query_meilisearch($fields, $ex_fid_ary, $topic_id, $author_ary, $author_name, $sort_days)
	{
		$filters = array();

		if (!empty($ex_fid_ary))
		{
			$filters[] = 'forum_id NOT IN [' . implode(', ', array_map('intval', $ex_fid_ary)) . ']';
		}

		if ($topic_id)
		{
			$filters[] = 'topic_id = ' . (int) $topic_id;
		}

		// When $author_name is set the search also has to match guest posts by
		// post_username, which we do not index. Leave author filtering to SQL.
		if (!empty($author_ary) && !$author_name)
		{
			$filters[] = 'poster_id IN [' . implode(', ', array_map('intval', $author_ary)) . ']';
		}

		if ($sort_days)
		{
			$filters[] = 'post_time >= ' . (int) (time() - ((int) $sort_days * 86400));
		}

		if ($fields === 'titleonly' || $fields === 'firstpost')
		{
			$filters[] = 'is_first_post = 1';
		}

		switch ($fields)
		{
			case 'titleonly':
				$search_on = array('post_subject');
			break;

			case 'msgonly':
				$search_on = array('post_text');
			break;

			default:
				$search_on = array('post_subject', 'post_text');
			break;
		}

		$limit = max(100, (int) $this->config['meilisearch_max_results']);

		$payload = array(
			'q'                    => $this->search_query,
			'limit'                => $limit,
			'attributesToRetrieve' => array('post_id'),
			'attributesToSearchOn' => $search_on,
		);

		if (!empty($filters))
		{
			$payload['filter'] = implode(' AND ', $filters);
		}

		$locales = $this->indexer->get_locales();

		if (!empty($locales))
		{
			$payload['locales'] = $locales;
		}

		$response = $this->client->search($this->indexer->get_index_uid(), $payload);

		if ($response === false)
		{
			// Retry once without the locales hint: it is rejected by Meilisearch
			// builds older than 1.10.
			if (isset($payload['locales']))
			{
				unset($payload['locales']);
				$response = $this->client->search($this->indexer->get_index_uid(), $payload);
			}

			if ($response === false)
			{
				$this->log_error('Meilisearch query failed: ' . $this->client->last_error());
				return array();
			}
		}

		$post_ids = array();

		if (!empty($response['hits']))
		{
			foreach ($response['hits'] as $hit)
			{
				if (isset($hit['post_id']))
				{
					$post_ids[] = (int) $hit['post_id'];
				}
			}
		}

		return $post_ids;
	}

	/**
	 * Re-apply phpBB's permission and sorting rules to the candidate ids.
	 *
	 * @param array  $ranked_post_ids Candidates in relevance order
	 * @param string $type
	 * @param string $fields
	 * @param array  $sort_by_sql
	 * @param string $sort_key
	 * @param string $sort_dir
	 * @param string $sort_days
	 * @param array  $ex_fid_ary
	 * @param string $post_visibility
	 * @param int    $topic_id
	 * @param array  $author_ary
	 * @param string $author_name
	 * @return array Final ordered list of post ids or topic ids
	 */
	protected function refine_with_sql($ranked_post_ids, $type, $fields, $sort_by_sql, $sort_key, $sort_dir, $sort_days, $ex_fid_ary, $post_visibility, $topic_id, $author_ary, $author_name)
	{
		$join_topic = ($type !== 'posts');

		$sql_sort       = $sort_by_sql[$sort_key] . (($sort_dir == 'a') ? ' ASC' : ' DESC');
		$sql_sort_table = $sql_sort_join = '';

		switch ($sql_sort[0])
		{
			case 'u':
				$sql_sort_table = USERS_TABLE . ' u, ';
				$sql_sort_join  = ($type == 'posts') ? ' AND u.user_id = p.poster_id ' : ' AND u.user_id = t.topic_poster ';
			break;

			case 't':
				$join_topic = true;
			break;

			case 'f':
				$sql_sort_table = FORUMS_TABLE . ' f, ';
				$sql_sort_join  = ' AND f.forum_id = p.forum_id ';
			break;

			case 'i':
				$join_topic = true;
			break;
		}

		$sql_match_where = '';

		if ($fields === 'titleonly' || $fields === 'firstpost')
		{
			$sql_match_where = ' AND p.post_id = t.topic_first_post_id';
			$join_topic      = true;
		}

		if (count($author_ary) && $author_name)
		{
			$sql_author = ' AND (' . $this->db->sql_in_set('p.poster_id', array_diff($author_ary, array(ANONYMOUS)), false, true) . ' OR p.post_username ' . $author_name . ')';
		}
		else if (count($author_ary))
		{
			$sql_author = ' AND ' . $this->db->sql_in_set('p.poster_id', $author_ary);
		}
		else
		{
			$sql_author = '';
		}

		$sql_where_options  = $sql_sort_join;
		$sql_where_options .= ($topic_id) ? ' AND p.topic_id = ' . (int) $topic_id : '';
		$sql_where_options .= ($join_topic) ? ' AND t.topic_id = p.topic_id' : '';
		$sql_where_options .= (count($ex_fid_ary)) ? ' AND ' . $this->db->sql_in_set('p.forum_id', $ex_fid_ary, true) : '';
		$sql_where_options .= ' AND ' . $post_visibility;
		$sql_where_options .= $sql_author;
		$sql_where_options .= ($sort_days) ? ' AND p.post_time >= ' . (int) (time() - ((int) $sort_days * 86400)) : '';
		$sql_where_options .= $sql_match_where;

		$sql_select = ($type == 'posts') ? 'DISTINCT p.post_id' : 'DISTINCT t.topic_id, p.post_id';
		$sql_select .= $sort_by_sql[$sort_key] ? ", {$sort_by_sql[$sort_key]}" : '';
		$sql_from    = ($join_topic) ? TOPICS_TABLE . ' t, ' : '';
		$field       = ($type == 'posts') ? 'post_id' : 'topic_id';

		$sql = "SELECT $sql_select
			FROM $sql_from$sql_sort_table" . POSTS_TABLE . " p
			WHERE " . $this->db->sql_in_set('p.post_id', $ranked_post_ids) . "
				$sql_where_options
			ORDER BY $sql_sort";

		/**
		 * Allow modifying the SQL refinement query.
		 *
		 * @event salvocortesiano.meilisearch.refine_query_before
		 * @var string sql              The assembled query
		 * @var array  ranked_post_ids  Candidate ids from Meilisearch
		 * @var string type             'posts' or 'topics'
		 * @since 1.0.0
		 */
		$ranked_post_ids_ref = $ranked_post_ids;
		$vars = array('sql', 'ranked_post_ids_ref', 'type');
		extract($this->phpbb_dispatcher->trigger_event('salvocortesiano.meilisearch.refine_query_before', compact($vars)));

		$result = $this->db->sql_query($sql);

		$sql_ordered = array();
		$by_post_id  = array();

		while ($row = $this->db->sql_fetchrow($result))
		{
			$sql_ordered[] = (int) $row[$field];

			if ($type != 'posts')
			{
				// Keep the best (first seen) post id per topic so relevance
				// ordering can be reconstructed below.
				$topic = (int) $row['topic_id'];

				if (!isset($by_post_id[$topic]))
				{
					$by_post_id[$topic] = (int) $row['post_id'];
				}
			}
		}

		$this->db->sql_freeresult($result);

		$sql_ordered = array_values(array_unique($sql_ordered));

		if (!$this->use_relevance_order() || empty($sql_ordered))
		{
			return $sql_ordered;
		}

		// Rebuild the list in Meilisearch relevance order, keeping only ids that
		// survived the SQL permission pass.
		$rank = array_flip($ranked_post_ids);

		if ($type == 'posts')
		{
			$allowed = array_flip($sql_ordered);
			$ordered = array();

			foreach ($ranked_post_ids as $post_id)
			{
				if (isset($allowed[$post_id]))
				{
					$ordered[] = (int) $post_id;
				}
			}

			return $ordered;
		}

		$weighted = array();

		foreach ($sql_ordered as $topic)
		{
			$best_post = isset($by_post_id[$topic]) ? $by_post_id[$topic] : 0;
			$weighted[$topic] = isset($rank[$best_post]) ? $rank[$best_post] : PHP_INT_MAX;
		}

		asort($weighted, SORT_NUMERIC);

		return array_map('intval', array_keys($weighted));
	}

	/**
	 * Should results be ordered by Meilisearch relevance instead of the SQL sort?
	 *
	 * Only when the admin enabled it AND the user has not explicitly chosen a
	 * sort order (no "sk" parameter in the request). Overriding an explicit user
	 * choice would be hostile.
	 *
	 * @return bool
	 */
	protected function use_relevance_order()
	{
		if (!$this->config['meilisearch_relevance'])
		{
			return false;
		}

		global $request;

		if ($request instanceof \phpbb\request\request_interface)
		{
			return !$request->is_set('sk');
		}

		return true;
	}

	/**
	 * Author-only search. Meilisearch is not involved: there are no keywords, so
	 * this is a plain SQL query, kept behaviourally identical to fulltext_mysql.
	 *
	 * @return int|bool
	 */
	public function author_search($type, $firstpost_only, $sort_by_sql, $sort_key, $sort_dir, $sort_days, $ex_fid_ary, $post_visibility, $topic_id, $author_ary, $author_name, &$id_ary, &$start, $per_page)
	{
		if (!count($author_ary))
		{
			return 0;
		}

		$search_key_array = array(
			'meilisearch_author',
			$type,
			($firstpost_only) ? 'firstpost' : '',
			$sort_days,
			$sort_key,
			$sort_dir,
			$topic_id,
			implode(',', $ex_fid_ary),
			$post_visibility,
			implode(',', $author_ary),
			$author_name,
		);

		$search_key = md5(implode('#', $search_key_array));

		if ($start < 0)
		{
			$start = 0;
		}

		$result_count = 0;

		if ($this->obtain_ids($search_key, $result_count, $id_ary, $start, $per_page, $sort_dir) == SEARCH_RESULT_IN_CACHE)
		{
			return $result_count;
		}

		$id_ary = array();

		if ($author_name)
		{
			$sql_author = '(' . $this->db->sql_in_set('p.poster_id', array_diff($author_ary, array(ANONYMOUS)), false, true) . ' OR p.post_username ' . $author_name . ')';
		}
		else
		{
			$sql_author = $this->db->sql_in_set('p.poster_id', $author_ary);
		}

		$sql_fora      = (count($ex_fid_ary)) ? ' AND ' . $this->db->sql_in_set('p.forum_id', $ex_fid_ary, true) : '';
		$sql_topic_id  = ($topic_id) ? ' AND p.topic_id = ' . (int) $topic_id : '';
		$sql_time      = ($sort_days) ? ' AND p.post_time >= ' . (int) (time() - ((int) $sort_days * 86400)) : '';
		$sql_firstpost = ($firstpost_only) ? ' AND p.post_id = t.topic_first_post_id' : '';

		$sql_sort       = $sort_by_sql[$sort_key] . (($sort_dir == 'a') ? ' ASC' : ' DESC');
		$sql_sort_table = $sql_sort_join = '';

		switch ($sql_sort[0])
		{
			case 'u':
				$sql_sort_table = USERS_TABLE . ' u, ';
				$sql_sort_join  = ($type == 'posts') ? ' AND u.user_id = p.poster_id ' : ' AND u.user_id = t.topic_poster ';
			break;

			case 't':
				$sql_sort_table = ($type == 'posts' && !$firstpost_only) ? TOPICS_TABLE . ' t, ' : '';
				$sql_sort_join  = ($type == 'posts' && !$firstpost_only) ? ' AND t.topic_id = p.topic_id ' : '';
			break;

			case 'f':
				$sql_sort_table = FORUMS_TABLE . ' f, ';
				$sql_sort_join  = ' AND f.forum_id = p.forum_id ';
			break;
		}

		$sql_select = ($type == 'posts') ? 'p.post_id' : 't.topic_id';
		$sql_select .= $sort_by_sql[$sort_key] ? ", {$sort_by_sql[$sort_key]}" : '';

		if ($type == 'posts')
		{
			$sql_sort .= ', p.post_id' . (($sort_dir == 'a') ? ' ASC' : ' DESC');
			$sql = "SELECT $sql_select
				FROM " . $sql_sort_table . POSTS_TABLE . ' p' . (($firstpost_only) ? ', ' . TOPICS_TABLE . ' t ' : ' ') . "
				WHERE $sql_author
					$sql_topic_id
					$sql_firstpost
					AND $post_visibility
					$sql_fora
					$sql_sort_join
					$sql_time
				ORDER BY $sql_sort";
			$field = 'post_id';
		}
		else
		{
			$sql = "SELECT $sql_select
				FROM " . $sql_sort_table . TOPICS_TABLE . ' t, ' . POSTS_TABLE . " p
				WHERE $sql_author
					$sql_topic_id
					$sql_firstpost
					AND $post_visibility
					$sql_fora
					AND t.topic_id = p.topic_id
					$sql_sort_join
					$sql_time
				GROUP BY $sql_select
				ORDER BY $sql_sort";
			$field = 'topic_id';
		}

		$result = $this->db->sql_query_limit($sql, (int) $this->config['search_block_size'], $start);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$id_ary[] = (int) $row[$field];
		}

		$this->db->sql_freeresult($result);

		if (!$result_count)
		{
			$sql_found_rows = str_replace("SELECT $sql_select", "SELECT COUNT(*) as result_count", $sql);
			$result = $this->db->sql_query($sql_found_rows);
			$result_count = ($type == 'posts') ? (int) $this->db->sql_fetchfield('result_count') : count($this->db->sql_fetchrowset($result));
			$this->db->sql_freeresult($result);

			if (!$result_count)
			{
				return false;
			}
		}

		if ($start >= $result_count)
		{
			$start = (int) (floor(($result_count - 1) / $per_page) * $per_page);

			$result = $this->db->sql_query_limit($sql, (int) $this->config['search_block_size'], $start);

			while ($row = $this->db->sql_fetchrow($result))
			{
				$id_ary[] = (int) $row[$field];
			}

			$this->db->sql_freeresult($result);
		}

		$id_ary = array_unique($id_ary);

		if (count($id_ary))
		{
			$this->save_ids($search_key, '', $author_ary, $result_count, $id_ary, $start, $sort_dir);
			$id_ary = array_slice($id_ary, 0, $per_page);

			return $result_count;
		}

		return false;
	}

	/* =====================================================================
	 * Write side
	 * ================================================================== */

	/**
	 * Called by phpBB whenever a post is created or edited, and once per post by
	 * the ACP "Create index" loop.
	 *
	 * We only record the id; the document is assembled at flush time from the
	 * committed database row (see indexer::build_documents). During a bulk ACP
	 * reindex we accumulate ids and flush in batches, which turns hundreds of
	 * thousands of HTTP round trips into a few thousand.
	 *
	 * @param string $mode      'post', 'reply', 'edit', 'quote'
	 * @param int    $post_id
	 * @param string $message
	 * @param string $subject
	 * @param int    $poster_id
	 * @param int    $forum_id
	 * @return void
	 */
	public function index($mode, $post_id, &$message, &$subject, $poster_id, $forum_id)
	{
		if ($this->indexer === null)
		{
			return;
		}

		$this->index_buffer[] = (int) $post_id;
		$this->index_buffer_authors[(int) $poster_id] = (int) $poster_id;

		$batch_size = max(1, (int) $this->config['meilisearch_batch_size']);
		$bulk_mode  = defined('ADMIN_START') || php_sapi_name() === 'cli';

		if (!$bulk_mode || count($this->index_buffer) >= $batch_size)
		{
			$this->flush_index_buffer();
		}
	}

	/**
	 * Push whatever is buffered and invalidate the affected result cache.
	 *
	 * @return void
	 */
	protected function flush_index_buffer()
	{
		if (empty($this->index_buffer) || $this->indexer === null)
		{
			return;
		}

		$post_ids = $this->index_buffer;
		$authors  = array_values($this->index_buffer_authors);

		$this->index_buffer         = array();
		$this->index_buffer_authors = array();

		$this->indexer->push($post_ids, (bool) $this->config['meilisearch_queue_enable']);

		// One cache invalidation per batch rather than per post: destroy_cache()
		// issues DELETEs against the search results table and doing that once per
		// post during a full reindex is ruinous.
		$this->destroy_cache(array(), $authors);
	}

	/**
	 * Called when posts are deleted, and once per batch by the ACP
	 * "Delete index" loop when delete_index() is not implemented.
	 *
	 * @param array $post_ids
	 * @param array $author_ids
	 * @param array $forum_ids
	 * @return void
	 */
	public function index_remove($post_ids, $author_ids, $forum_ids)
	{
		if ($this->indexer !== null && !empty($post_ids))
		{
			$this->indexer->remove($post_ids, (bool) $this->config['meilisearch_queue_enable']);
		}

		$this->destroy_cache(array(), array_unique($author_ids));
	}

	/**
	 * Periodic maintenance. phpBB calls this from cron and after each ACP
	 * indexing batch, which makes it our natural flush point.
	 *
	 * @return void
	 */
	public function tidy()
	{
		$this->flush_index_buffer();

		$this->destroy_cache(array());

		$this->config->set('search_last_gc', time(), false);
	}

	/**
	 * Drop the whole index in one API call.
	 *
	 * Implementing this method makes acp_search skip its per-post deletion loop,
	 * which for Meilisearch would mean one HTTP request per batch of posts for no
	 * reason: the engine can truncate an index instantly.
	 *
	 * @param object $acp_module Reference to the acp_search module (unused)
	 * @param string $u_action   Continuation URL (unused)
	 * @return string|bool false on success, error message otherwise
	 */
	public function delete_index($acp_module, $u_action)
	{
		if ($error = $this->init())
		{
			return $error;
		}

		if (!$this->indexer->purge_all())
		{
			return $this->lang('MEILISEARCH_PURGE_FAILED') . ' ' . $this->indexer->last_error();
		}

		$this->stats = array();

		switch ($this->db->get_sql_layer())
		{
			case 'sqlite3':
				$this->db->sql_query('DELETE FROM ' . SEARCH_RESULTS_TABLE);
			break;

			default:
				$this->db->sql_query('TRUNCATE TABLE ' . SEARCH_RESULTS_TABLE);
			break;
		}

		return false;
	}

	/**
	 * @return bool True when the index holds at least one document
	 */
	public function index_created()
	{
		if (empty($this->stats))
		{
			$this->get_stats();
		}

		return !empty($this->stats['documents']);
	}

	/**
	 * Statistics rendered on the ACP search index page.
	 *
	 * @return array
	 */
	public function index_stats()
	{
		if (empty($this->stats))
		{
			$this->get_stats();
		}

		$stats = array(
			$this->lang('MEILISEARCH_STAT_DOCUMENTS') => (int) $this->stats['documents'],
			$this->lang('MEILISEARCH_STAT_INDEXING')  => !empty($this->stats['is_indexing']) ? $this->lang('YES') : $this->lang('NO'),
			$this->lang('MEILISEARCH_STAT_QUEUE')     => (int) $this->stats['queue'],
			$this->lang('MEILISEARCH_STAT_EXCLUDED')  => (int) $this->stats['excluded_forums'],
		);

		if (!empty($this->stats['error']))
		{
			$stats[$this->lang('MEILISEARCH_STAT_ERROR')] = $this->stats['error'];
		}

		return $stats;
	}

	/**
	 * Fetch and cache index statistics for the current request.
	 *
	 * @return void
	 */
	protected function get_stats()
	{
		$this->stats = array(
			'documents'   => 0,
			'is_indexing' => false,
			'queue'       => 0,
			'excluded_forums' => 0,
			'error'       => '',
		);

		if ($this->indexer === null)
		{
			$this->stats['error'] = $this->lang('MEILISEARCH_EXT_DISABLED');
			return;
		}

		$this->stats['queue']           = $this->indexer->queue_size();
		$this->stats['excluded_forums'] = count($this->indexer->get_excluded_forum_ids());

		$remote = $this->client->index_stats($this->indexer->get_index_uid());

		if ($remote === false)
		{
			$this->stats['error'] = $this->client->last_error();
			return;
		}

		$this->stats['documents']   = isset($remote['numberOfDocuments']) ? (int) $remote['numberOfDocuments'] : 0;
		$this->stats['is_indexing'] = !empty($remote['isIndexing']);
	}

	/* =====================================================================
	 * ACP integration
	 * ================================================================== */

	/**
	 * Options rendered inside ACP -> General -> Search settings.
	 *
	 * The returned 'config' map tells acp_search which request variables to
	 * accept and how to cast them. Format is "type[:min[:max]]" and the cast is
	 * done with settype(), so 'string', 'integer', 'bool' and 'float' are valid.
	 *
	 * @return array ['tpl' => string, 'config' => array]
	 */
	public function acp()
	{
		$tpl = '
		<dl>
			<dt><label for="meilisearch_url">' . $this->lang('MEILISEARCH_URL') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_URL_EXPLAIN') . '</span></dt>
			<dd><input id="meilisearch_url" type="text" size="45" maxlength="255" name="config[meilisearch_url]" value="' . $this->esc($this->config['meilisearch_url']) . '" /></dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_api_key">' . $this->lang('MEILISEARCH_API_KEY') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_API_KEY_EXPLAIN') . '</span></dt>
			<dd><input id="meilisearch_api_key" type="text" size="45" maxlength="255" name="config[meilisearch_api_key]" value="' . $this->esc($this->config['meilisearch_api_key']) . '" autocomplete="off" /></dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_index">' . $this->lang('MEILISEARCH_INDEX') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_INDEX_EXPLAIN') . '</span></dt>
			<dd><input id="meilisearch_index" type="text" size="25" maxlength="64" name="config[meilisearch_index]" value="' . $this->esc($this->config['meilisearch_index']) . '" /></dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_locales">' . $this->lang('MEILISEARCH_LOCALES') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_LOCALES_EXPLAIN') . '</span></dt>
			<dd><input id="meilisearch_locales" type="text" size="25" maxlength="64" name="config[meilisearch_locales]" value="' . $this->esc($this->config['meilisearch_locales']) . '" /></dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_timeout">' . $this->lang('MEILISEARCH_TIMEOUT') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_TIMEOUT_EXPLAIN') . '</span></dt>
			<dd><input id="meilisearch_timeout" type="number" min="1" max="60" name="config[meilisearch_timeout]" value="' . (int) $this->config['meilisearch_timeout'] . '" /></dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_max_results">' . $this->lang('MEILISEARCH_MAX_RESULTS') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_MAX_RESULTS_EXPLAIN') . '</span></dt>
			<dd><input id="meilisearch_max_results" type="number" min="100" max="10000" name="config[meilisearch_max_results]" value="' . (int) $this->config['meilisearch_max_results'] . '" /></dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_batch_size">' . $this->lang('MEILISEARCH_BATCH_SIZE') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_BATCH_SIZE_EXPLAIN') . '</span></dt>
			<dd><input id="meilisearch_batch_size" type="number" min="10" max="2000" name="config[meilisearch_batch_size]" value="' . (int) $this->config['meilisearch_batch_size'] . '" /></dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_min_chars">' . $this->lang('MEILISEARCH_MIN_CHARS') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_MIN_CHARS_EXPLAIN') . '</span></dt>
			<dd><input id="meilisearch_min_chars" type="number" min="1" max="255" name="config[meilisearch_min_chars]" value="' . (int) $this->config['meilisearch_min_chars'] . '" /></dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_max_chars">' . $this->lang('MEILISEARCH_MAX_CHARS') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_MAX_CHARS_EXPLAIN') . '</span></dt>
			<dd><input id="meilisearch_max_chars" type="number" min="1" max="255" name="config[meilisearch_max_chars]" value="' . (int) $this->config['meilisearch_max_chars'] . '" /></dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_typo">' . $this->lang('MEILISEARCH_TYPO') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_TYPO_EXPLAIN') . '</span></dt>
			<dd>' . $this->radio('meilisearch_typo') . '</dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_relevance">' . $this->lang('MEILISEARCH_RELEVANCE') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_RELEVANCE_EXPLAIN') . '</span></dt>
			<dd>' . $this->radio('meilisearch_relevance') . '</dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_queue_enable">' . $this->lang('MEILISEARCH_QUEUE') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_QUEUE_EXPLAIN') . '</span></dt>
			<dd>' . $this->radio('meilisearch_queue_enable') . '</dd>
		</dl>
		<dl>
			<dt><label for="meilisearch_banner_enable">' . $this->lang('MEILISEARCH_BANNER') . $this->lang('COLON') . '</label><br /><span>' . $this->lang('MEILISEARCH_BANNER_EXPLAIN') . '</span></dt>
			<dd>' . $this->radio('meilisearch_banner_enable') . '</dd>
		</dl>
		';

		return array(
			'tpl'    => $tpl,
			'config' => array(
				'meilisearch_url'          => 'string',
				'meilisearch_api_key'      => 'string',
				'meilisearch_index'        => 'string',
				'meilisearch_locales'      => 'string',
				'meilisearch_timeout'      => 'integer:1:60',
				'meilisearch_max_results'  => 'integer:100:10000',
				'meilisearch_batch_size'   => 'integer:10:2000',
				'meilisearch_min_chars'    => 'integer:1:255',
				'meilisearch_max_chars'    => 'integer:1:255',
				'meilisearch_typo'         => 'bool',
				'meilisearch_relevance'    => 'bool',
				'meilisearch_queue_enable' => 'bool',
				'meilisearch_banner_enable' => 'bool',
			),
		);
	}

	/* =====================================================================
	 * Small helpers
	 * ================================================================== */

	/**
	 * Render a yes/no radio pair for the ACP template.
	 *
	 * @param string $key Config key
	 * @return string
	 */
	protected function radio($key)
	{
		$on = (bool) $this->config[$key];

		return '<label><input type="radio" id="' . $key . '" name="config[' . $key . ']" value="1"' . ($on ? ' checked="checked"' : '') . ' class="radio" /> ' . $this->lang('YES') . '</label>'
			. '<label><input type="radio" name="config[' . $key . ']" value="0"' . (!$on ? ' checked="checked"' : '') . ' class="radio" /> ' . $this->lang('NO') . '</label>';
	}

	/**
	 * @param string $value
	 * @return string
	 */
	protected function esc($value)
	{
		return htmlspecialchars((string) $value, ENT_COMPAT, 'UTF-8');
	}

	/**
	 * Fetch a language string with a readable fallback.
	 *
	 * The backend is instantiated in contexts where our language file may not be
	 * loaded yet (for example the extension being disabled), so never assume the
	 * key exists.
	 *
	 * @param string $key
	 * @return string
	 */
	protected function lang($key)
	{
		if (isset($this->user->lang[$key]))
		{
			return is_array($this->user->lang[$key]) ? $key : $this->user->lang[$key];
		}

		return $key;
	}

	/**
	 * Write a message to the phpBB error log without interrupting the request.
	 *
	 * @param string $message
	 * @return void
	 */
	protected function log_error($message)
	{
		if (function_exists('add_log'))
		{
			add_log('critical', 'LOG_MEILISEARCH_ERROR', $message);
		}
	}
}
