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
 * Full diagnostic sweep of the Meilisearch deployment.
 *
 * Why this exists
 * ---------------
 * When search "stops working" the visible symptom is always the same: zero
 * results and an index that reports as empty. The cause is never the same. It
 * can be the daemon being down, the daemon being up but its data directory
 * unwritable, an expired TLS certificate, an API key that lost a permission, a
 * failed indexing task that was never surfaced anywhere, a firewall change, or
 * simply drift between the posts table and the index after an outage.
 *
 * Those are indistinguishable from the ACP front page, so this class probes
 * every layer in order - PHP, DNS, TCP, TLS, HTTP, authentication, index
 * settings, a live query, and finally the phpBB side - and reports what each
 * one actually said. The result is also rendered as plain text so it can be
 * pasted into a support thread without screenshots.
 *
 * Nothing here writes. It is safe to run on a live board at any time; the
 * heaviest operation is three /health calls and two small searches.
 */
class health
{
	/** Result levels */
	const OK   = 'ok';
	const WARN = 'warn';
	const FAIL = 'fail';
	const INFO = 'info';

	/** Latency above which a connection is flagged as slow, in milliseconds */
	const SLOW_MS = 400;

	/** Index/database drift above which a warning is raised, as a fraction */
	const DRIFT_TOLERANCE = 0.02;

	/** @var client */
	protected $client;

	/** @var indexer */
	protected $indexer;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var array Collected blocks */
	protected $blocks = array();

	/** @var array Counts per level */
	protected $tally = array(self::OK => 0, self::WARN => 0, self::FAIL => 0, self::INFO => 0);

	/** @var array Remediation hints raised during the run */
	protected $hints = array();

	/**
	 * @param client                            $client
	 * @param indexer                           $indexer
	 * @param \phpbb\config\config              $config
	 * @param \phpbb\db\driver\driver_interface  $db
	 * @param \phpbb\language\language          $language
	 */
	public function __construct(client $client, indexer $indexer, \phpbb\config\config $config, \phpbb\db\driver\driver_interface $db, \phpbb\language\language $language)
	{
		$this->client   = $client;
		$this->indexer  = $indexer;
		$this->config   = $config;
		$this->db       = $db;
		$this->language = $language;
	}

	/**
	 * Run every check.
	 *
	 * @return array ['blocks' => array, 'tally' => array, 'hints' => array]
	 */
	public function run()
	{
		$this->blocks = array();
		$this->tally  = array(self::OK => 0, self::WARN => 0, self::FAIL => 0, self::INFO => 0);
		$this->hints  = array();

		$this->check_environment();
		$reachable = $this->check_connectivity();
		$this->check_instance($reachable);
		$index_ok = $this->check_index($reachable);
		$this->check_search($reachable && $index_ok);
		$this->check_phpbb_side();

		return array(
			'blocks' => $this->blocks,
			'tally'  => $this->tally,
			'hints'  => array_values(array_unique($this->hints)),
		);
	}

	/* ---------------------------------------------------------------------
	 * Individual check groups
	 * ------------------------------------------------------------------ */

	/**
	 * PHP and phpBB environment.
	 *
	 * @return void
	 */
	protected function check_environment()
	{
		$rows = array();

		$rows[] = $this->row('HEALTH_EXT_VERSION', $this->get_extension_version(), self::INFO);
		$rows[] = $this->row('HEALTH_PHPBB_VERSION', (string) $this->config['version'], self::INFO);

		$php_ok = version_compare(PHP_VERSION, '7.4.0', '>=');
		$rows[] = $this->row('HEALTH_PHP_VERSION', PHP_VERSION, $php_ok ? self::OK : self::FAIL);

		if (function_exists('curl_version'))
		{
			$curl = curl_version();
			$rows[] = $this->row('HEALTH_CURL', $curl['version'] . ' / ' . $curl['ssl_version'], self::OK);
		}
		else
		{
			$rows[] = $this->row('HEALTH_CURL', $this->language->lang('HEALTH_MISSING'), self::FAIL);
			$this->hint('HEALTH_HINT_CURL');
		}

		$rows[] = $this->row('HEALTH_MBSTRING', extension_loaded('mbstring')
			? $this->language->lang('HEALTH_PRESENT')
			: $this->language->lang('HEALTH_MISSING'),
			extension_loaded('mbstring') ? self::OK : self::FAIL);

		$rows[] = $this->row('HEALTH_MEMORY_LIMIT', (string) ini_get('memory_limit'), self::INFO);

		$max_exec = (int) ini_get('max_execution_time');
		$rows[] = $this->row('HEALTH_MAX_EXECUTION', ($max_exec === 0)
			? $this->language->lang('HEALTH_UNLIMITED')
			: $max_exec . ' s',
			($max_exec !== 0 && $max_exec < 30) ? self::WARN : self::INFO);

		if ($max_exec !== 0 && $max_exec < 30)
		{
			$this->hint('HEALTH_HINT_EXECUTION');
		}

		$rows[] = $this->row('HEALTH_SERVER_TIME', gmdate('Y-m-d H:i:s') . ' UTC', self::INFO);

		$this->block('HEALTH_BLOCK_ENV', $rows);
	}

	/**
	 * DNS, TCP, TLS and HTTP reachability.
	 *
	 * @return bool True when the instance answered /health
	 */
	protected function check_connectivity()
	{
		$rows = array();

		$url = $this->client->get_url();

		if (!$this->client->is_configured())
		{
			$rows[] = $this->row('HEALTH_URL', $this->language->lang('HEALTH_NOT_SET'), self::FAIL);
			$this->hint('HEALTH_HINT_NO_URL');
			$this->block('HEALTH_BLOCK_CONN', $rows);

			return false;
		}

		$rows[] = $this->row('HEALTH_URL', $url, self::INFO);

		$scheme = (string) parse_url($url, PHP_URL_SCHEME);
		$is_local = (strpos($url, '127.0.0.1') !== false || strpos($url, 'localhost') !== false);

		// http to a remote host means credentials and post content travel in clear
		$scheme_level = ($scheme === 'https' || $is_local) ? self::OK : self::WARN;
		$rows[] = $this->row('HEALTH_SCHEME', $scheme, $scheme_level);

		if ($scheme_level === self::WARN)
		{
			$this->hint('HEALTH_HINT_PLAINTEXT');
		}

		// --- DNS + TCP ---
		$socket = $this->client->probe_socket();

		$rows[] = $this->row('HEALTH_HOST', $socket['host'] . ':' . $socket['port']
			. ($socket['ip'] !== '' ? ' → ' . $socket['ip'] : ''),
			($socket['ip'] !== '' || $socket['ok']) ? self::INFO : self::WARN);

		if ($socket['ok'])
		{
			$rows[] = $this->row('HEALTH_TCP', $this->ms($socket['ms']), self::OK);
		}
		else
		{
			$rows[] = $this->row('HEALTH_TCP', $socket['error'], self::FAIL);
			$this->hint('HEALTH_HINT_TCP');
		}

		// --- TLS ---
		$cert = $this->client->probe_certificate();

		if ($cert !== false)
		{
			$days = (int) $cert['days_left'];
			$level = ($days < 0) ? self::FAIL : (($days < 15) ? self::WARN : self::OK);

			$rows[] = $this->row('HEALTH_TLS', $this->language->lang('HEALTH_TLS_VALUE',
				$cert['issuer'], gmdate('Y-m-d', $cert['valid_to']), $days), $level);

			if ($level !== self::OK)
			{
				$this->hint('HEALTH_HINT_TLS');
			}
		}

		// --- /health, three samples ---
		$samples = array();
		$last_error = '';

		for ($i = 0; $i < 3; $i++)
		{
			$ok = ($this->client->health() !== false);
			$samples[] = $this->client->last_duration_ms();

			if (!$ok)
			{
				$last_error = $this->client->last_error();
				break;
			}
		}

		if ($last_error !== '')
		{
			$rows[] = $this->row('HEALTH_ENDPOINT', $last_error, self::FAIL);
			$this->hint('HEALTH_HINT_UNREACHABLE');
			$this->block('HEALTH_BLOCK_CONN', $rows);

			return false;
		}

		$min = min($samples);
		$max = max($samples);
		$avg = round(array_sum($samples) / count($samples), 1);

		$level = ($avg > self::SLOW_MS) ? self::WARN : self::OK;
		$rows[] = $this->row('HEALTH_ENDPOINT', $this->language->lang('HEALTH_LATENCY_VALUE',
			$this->ms($min), $this->ms($avg), $this->ms($max)), $level);

		if ($level === self::WARN)
		{
			$this->hint('HEALTH_HINT_SLOW');
		}

		$rows[] = $this->row('HEALTH_TIMEOUT_SETTING', (int) $this->config['meilisearch_timeout'] . ' s', self::INFO);

		$this->block('HEALTH_BLOCK_CONN', $rows);

		return true;
	}

	/**
	 * Instance-level state, including the task queue.
	 *
	 * @param bool $reachable
	 * @return void
	 */
	protected function check_instance($reachable)
	{
		$rows = array();

		if (!$reachable)
		{
			$rows[] = $this->row('HEALTH_SKIPPED', $this->language->lang('HEALTH_SKIPPED_UNREACHABLE'), self::INFO);
			$this->block('HEALTH_BLOCK_INSTANCE', $rows);

			return;
		}

		// Both endpoints below are global; an index-scoped key cannot reach them
		// and that is expected, not a fault.
		$version = $this->client->version();
		$rows[] = ($version !== false && isset($version['pkgVersion']))
			? $this->row('HEALTH_MEILI_VERSION', $version['pkgVersion'], self::OK)
			: $this->row('HEALTH_MEILI_VERSION', $this->language->lang('HEALTH_SCOPED_KEY'), self::INFO);

		$stats = $this->client->global_stats();

		if ($stats !== false)
		{
			if (isset($stats['databaseSize']))
			{
				$rows[] = $this->row('HEALTH_DB_SIZE', $this->bytes((int) $stats['databaseSize']), self::INFO);
			}

			if (isset($stats['usedDatabaseSize']))
			{
				$rows[] = $this->row('HEALTH_DB_USED', $this->bytes((int) $stats['usedDatabaseSize']), self::INFO);
			}

			if (isset($stats['lastUpdate']))
			{
				$rows[] = $this->row('HEALTH_LAST_UPDATE', (string) $stats['lastUpdate'], self::INFO);
			}

			if (!empty($stats['indexes']) && is_array($stats['indexes']))
			{
				$rows[] = $this->row('HEALTH_INDEX_COUNT', (string) count($stats['indexes']), self::INFO);
			}
		}
		else
		{
			$rows[] = $this->row('HEALTH_DB_SIZE', $this->language->lang('HEALTH_SCOPED_KEY'), self::INFO);
		}

		// --- failed tasks: the most valuable single line in this report ---
		$failed = $this->client->tasks(5, 'failed', $this->indexer->get_index_uid());

		if ($failed !== false && isset($failed['results']))
		{
			$count = count($failed['results']);

			if ($count === 0)
			{
				$rows[] = $this->row('HEALTH_FAILED_TASKS', $this->language->lang('HEALTH_NONE'), self::OK);
			}
			else
			{
				foreach ($failed['results'] as $task)
				{
					$when = isset($task['finishedAt']) ? substr((string) $task['finishedAt'], 0, 19) : '?';
					$type = isset($task['type']) ? $task['type'] : '?';
					$msg  = isset($task['error']['message']) ? $task['error']['message'] : '?';

					$rows[] = $this->row('HEALTH_FAILED_TASK', $when . ' · ' . $type . ' · ' . $msg, self::FAIL);
				}

				$this->hint('HEALTH_HINT_FAILED_TASKS');
			}
		}

		$pending = $this->client->tasks(1, 'enqueued,processing', $this->indexer->get_index_uid());

		if ($pending !== false && isset($pending['total']))
		{
			$total = (int) $pending['total'];
			$rows[] = $this->row('HEALTH_PENDING_TASKS', (string) $total, ($total > 100) ? self::WARN : self::OK);
		}

		$this->block('HEALTH_BLOCK_INSTANCE', $rows);
	}

	/**
	 * Index existence and settings, compared against what the extension needs.
	 *
	 * @param bool $reachable
	 * @return bool True when the index exists
	 */
	protected function check_index($reachable)
	{
		$rows = array();
		$uid  = $this->indexer->get_index_uid();

		$rows[] = $this->row('HEALTH_INDEX_UID', $uid, self::INFO);

		if (!$reachable)
		{
			$rows[] = $this->row('HEALTH_SKIPPED', $this->language->lang('HEALTH_SKIPPED_UNREACHABLE'), self::INFO);
			$this->block('HEALTH_BLOCK_INDEX', $rows);

			return false;
		}

		$stats = $this->client->index_stats($uid);

		if ($stats === false)
		{
			$rows[] = $this->row('HEALTH_INDEX_EXISTS', $this->client->last_error(), self::FAIL);
			$this->hint('HEALTH_HINT_NO_INDEX');
			$this->block('HEALTH_BLOCK_INDEX', $rows);

			return false;
		}

		$documents = isset($stats['numberOfDocuments']) ? (int) $stats['numberOfDocuments'] : 0;

		$rows[] = $this->row('HEALTH_DOCUMENTS', number_format($documents),
			($documents > 0) ? self::OK : self::FAIL);

		if ($documents === 0)
		{
			$this->hint('HEALTH_HINT_EMPTY_INDEX');
		}

		$rows[] = $this->row('HEALTH_IS_INDEXING', !empty($stats['isIndexing'])
			? $this->language->lang('YES')
			: $this->language->lang('NO'), self::INFO);

		// --- settings drift ---
		$settings = $this->client->get_settings($uid);

		if ($settings === false)
		{
			$rows[] = $this->row('HEALTH_SETTINGS', $this->client->last_error(), self::WARN);
			$this->block('HEALTH_BLOCK_INDEX', $rows);

			return true;
		}

		$expected = $this->indexer->get_settings_payload();

		$attribute_labels = array(
			'filterableAttributes'  => 'HEALTH_ATTR_FILT',
			'sortableAttributes'    => 'HEALTH_ATTR_SORT',
			'searchableAttributes'  => 'HEALTH_ATTR_SEAR',
		);

		foreach ($attribute_labels as $key => $label)
		{
			$want = isset($expected[$key]) ? (array) $expected[$key] : array();
			$have = isset($settings[$key]) ? (array) $settings[$key] : array();

			// searchableAttributes defaults to ['*'], which covers everything
			if ($key === 'searchableAttributes' && in_array('*', $have, true))
			{
				$have = $want;
			}

			$missing = array_diff($want, $have);

			$rows[] = empty($missing)
				? $this->row($label, $this->language->lang('HEALTH_COMPLETE'), self::OK)
				: $this->row($label, $this->language->lang('HEALTH_MISSING_LIST', implode(', ', $missing)), self::FAIL);

			if (!empty($missing))
			{
				$this->hint('HEALTH_HINT_SETTINGS');
			}
		}

		$typo_want = !empty($expected['typoTolerance']['enabled']);
		$typo_have = !isset($settings['typoTolerance']['enabled']) || !empty($settings['typoTolerance']['enabled']);

		$rows[] = $this->row('HEALTH_TYPO', $typo_have
			? $this->language->lang('YES')
			: $this->language->lang('NO'),
			($typo_want === $typo_have) ? self::OK : self::WARN);

		if ($typo_want !== $typo_have)
		{
			$this->hint('HEALTH_HINT_SETTINGS');
		}

		$want_hits = max(100, (int) $this->config['meilisearch_max_results']);
		$have_hits = isset($settings['pagination']['maxTotalHits']) ? (int) $settings['pagination']['maxTotalHits'] : 1000;

		$rows[] = $this->row('HEALTH_MAX_HITS', $have_hits . ' / ' . $want_hits,
			($have_hits >= $want_hits) ? self::OK : self::WARN);

		if ($have_hits < $want_hits)
		{
			$this->hint('HEALTH_HINT_SETTINGS');
		}

		$locales = $this->indexer->get_locales();
		$rows[] = $this->row('HEALTH_LOCALES', empty($locales)
			? $this->language->lang('MEILISEARCH_LOCALES_AUTO')
			: implode(', ', $locales), self::INFO);

		$this->block('HEALTH_BLOCK_INDEX', $rows);

		return true;
	}

	/**
	 * End-to-end query tests.
	 *
	 * @param bool $possible
	 * @return void
	 */
	protected function check_search($possible)
	{
		$rows = array();

		if (!$possible)
		{
			$rows[] = $this->row('HEALTH_SKIPPED', $this->language->lang('HEALTH_SKIPPED_NO_INDEX'), self::INFO);
			$this->block('HEALTH_BLOCK_SEARCH', $rows);

			return;
		}

		$uid = $this->indexer->get_index_uid();

		// 1. empty query: exercises the search permission and returns the total
		$probe = $this->client->search($uid, array('q' => '', 'limit' => 1, 'attributesToRetrieve' => array('post_id')));

		if ($probe === false)
		{
			$rows[] = $this->row('HEALTH_QUERY_EMPTY', $this->client->last_error(), self::FAIL);
			$this->hint('HEALTH_HINT_QUERY');
			$this->block('HEALTH_BLOCK_SEARCH', $rows);

			return;
		}

		$total = isset($probe['estimatedTotalHits']) ? (int) $probe['estimatedTotalHits'] : 0;
		$rows[] = $this->row('HEALTH_QUERY_EMPTY', $this->language->lang('HEALTH_QUERY_VALUE',
			number_format($total), $this->ms($this->client->last_duration_ms())), self::OK);

		// 2. a real term taken from a real post: proves the text actually made it
		//    into the index, which an empty query does not
		$term = $this->sample_term();

		if ($term !== '')
		{
			$probe = $this->client->search($uid, array('q' => $term, 'limit' => 1, 'attributesToRetrieve' => array('post_id')));

			if ($probe === false)
			{
				$rows[] = $this->row('HEALTH_QUERY_TERM', $this->client->last_error(), self::FAIL);
				$this->hint('HEALTH_HINT_QUERY');
			}
			else
			{
				$hits = isset($probe['estimatedTotalHits']) ? (int) $probe['estimatedTotalHits'] : 0;

				$rows[] = $this->row('HEALTH_QUERY_TERM', $this->language->lang('HEALTH_QUERY_TERM_VALUE',
					$term, number_format($hits), $this->ms($this->client->last_duration_ms())),
					($hits > 0) ? self::OK : self::WARN);

				if ($hits === 0)
				{
					$this->hint('HEALTH_HINT_TERM_MISS');
				}
			}
		}

		// 3. a filtered query: the only check that proves filterableAttributes
		//    are live, which is what forum permissions depend on
		$probe = $this->client->search($uid, array(
			'q'                    => '',
			'limit'                => 1,
			'filter'               => 'forum_id > 0',
			'attributesToRetrieve' => array('post_id'),
		));

		$rows[] = ($probe === false)
			? $this->row('HEALTH_QUERY_FILTER', $this->client->last_error(), self::FAIL)
			: $this->row('HEALTH_QUERY_FILTER', $this->language->lang('HEALTH_QUERY_VALUE',
				number_format(isset($probe['estimatedTotalHits']) ? (int) $probe['estimatedTotalHits'] : 0),
				$this->ms($this->client->last_duration_ms())), self::OK);

		if ($probe === false)
		{
			$this->hint('HEALTH_HINT_SETTINGS');
		}

		$this->block('HEALTH_BLOCK_SEARCH', $rows);
	}

	/**
	 * The phpBB half: is the backend live, and does the index match the board?
	 *
	 * @return void
	 */
	protected function check_phpbb_side()
	{
		$rows = array();

		$active = strpos((string) $this->config['search_type'], 'meilisearch') !== false;

		$rows[] = $this->row('HEALTH_BACKEND', (string) $this->config['search_type'],
			$active ? self::OK : self::WARN);

		if (!$active)
		{
			$this->hint('HEALTH_HINT_NOT_ACTIVE');
		}

		// --- drift between the board and the index ---
		$eligible = $this->count_eligible_posts();
		$rows[] = $this->row('HEALTH_ELIGIBLE_POSTS', number_format($eligible), self::INFO);

		$stats = $this->client->index_stats($this->indexer->get_index_uid());
		$documents = ($stats !== false && isset($stats['numberOfDocuments'])) ? (int) $stats['numberOfDocuments'] : -1;

		if ($documents >= 0 && $eligible > 0)
		{
			$delta = $documents - $eligible;
			$ratio = abs($delta) / $eligible;

			$level = ($ratio <= self::DRIFT_TOLERANCE) ? self::OK : (($ratio <= 0.10) ? self::WARN : self::FAIL);

			$rows[] = $this->row('HEALTH_DRIFT', $this->language->lang('HEALTH_DRIFT_VALUE',
				($delta >= 0 ? '+' : '') . number_format($delta), round($ratio * 100, 1)), $level);

			if ($level !== self::OK)
			{
				$this->hint('HEALTH_HINT_DRIFT');
			}
		}

		// --- retry queue ---
		$queue = $this->indexer->queue_size();
		$rows[] = $this->row('HEALTH_QUEUE', number_format($queue), ($queue > 0) ? self::WARN : self::OK);

		if ($queue > 0)
		{
			$oldest = $this->oldest_queue_time();

			if ($oldest > 0)
			{
				$age_h = round((time() - $oldest) / 3600, 1);
				$rows[] = $this->row('HEALTH_QUEUE_AGE', $this->language->lang('HEALTH_HOURS', $age_h),
					($age_h > 1) ? self::WARN : self::INFO);
			}

			$this->hint('HEALTH_HINT_QUEUE');
		}

		// --- cron ---
		$last_gc = (int) $this->config['meilisearch_queue_last_gc'];

		if ($last_gc === 0)
		{
			$rows[] = $this->row('HEALTH_CRON', $this->language->lang('HEALTH_NEVER'), self::WARN);
			$this->hint('HEALTH_HINT_CRON');
		}
		else
		{
			$age_h = round((time() - $last_gc) / 3600, 1);
			$level = ($age_h > 24) ? self::WARN : self::OK;

			$rows[] = $this->row('HEALTH_CRON', $this->language->lang('HEALTH_HOURS_AGO', $age_h), $level);

			if ($level === self::WARN)
			{
				$this->hint('HEALTH_HINT_CRON');
			}
		}

		$rows[] = $this->row('HEALTH_EXCLUDED_FORUMS', (string) count($this->indexer->get_excluded_forum_ids()), self::INFO);
		$rows[] = $this->row('HEALTH_RELEVANCE_MODE', (string) (int) $this->config['meilisearch_relevance_mode'], self::INFO);
		$rows[] = $this->row('HEALTH_BATCH_SIZE', (string) (int) $this->config['meilisearch_batch_size'], self::INFO);
		$rows[] = $this->row('HEALTH_MAX_RESULTS', (string) (int) $this->config['meilisearch_max_results'], self::INFO);

		$this->block('HEALTH_BLOCK_PHPBB', $rows);
	}

	/* ---------------------------------------------------------------------
	 * Data helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Posts that ought to be in the index right now.
	 *
	 * Mirrors what the ACP indexer walks: every post in a forum that has search
	 * indexing enabled and is not on the exclusion list.
	 *
	 * @return int
	 */
	protected function count_eligible_posts()
	{
		$excluded = $this->indexer->get_excluded_forum_ids();

		$sql = 'SELECT COUNT(p.post_id) AS total
			FROM ' . POSTS_TABLE . ' p
			LEFT JOIN ' . FORUMS_TABLE . ' f ON (f.forum_id = p.forum_id)
			WHERE (f.enable_indexing = 1 OR f.forum_id IS NULL)';

		if (!empty($excluded))
		{
			$sql .= ' AND ' . $this->db->sql_in_set('p.forum_id', $excluded, true);
		}

		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		return $total;
	}

	/**
	 * Pull a plausible search term out of a real recent post subject.
	 *
	 * Searching for a word we know exists in the board is the only way to prove
	 * the post text reached the index; an empty query would succeed even on an
	 * index full of blank documents.
	 *
	 * @return string
	 */
	protected function sample_term()
	{
		$sql = 'SELECT post_subject FROM ' . POSTS_TABLE . ' WHERE post_subject <> \'\' ORDER BY post_id DESC';
		$result = $this->db->sql_query_limit($sql, 20);

		$term = '';

		while ($row = $this->db->sql_fetchrow($result))
		{
			foreach (preg_split('/[^\p{L}\p{N}]+/u', (string) $row['post_subject']) as $word)
			{
				if (utf8_strlen($word) >= 5)
				{
					$term = $word;
					break 2;
				}
			}
		}

		$this->db->sql_freeresult($result);

		return $term;
	}

	/**
	 * @return int Unix timestamp of the oldest queued operation, 0 when empty
	 */
	protected function oldest_queue_time()
	{
		$sql = 'SELECT MIN(queue_time) AS oldest FROM ' . $this->get_queue_table();
		$result = $this->db->sql_query($sql);
		$oldest = (int) $this->db->sql_fetchfield('oldest');
		$this->db->sql_freeresult($result);

		return $oldest;
	}

	/**
	 * @return string
	 */
	protected function get_queue_table()
	{
		global $table_prefix;

		return $table_prefix . 'meili_queue';
	}

	/**
	 * @return string
	 */
	protected function get_extension_version()
	{
		global $phpbb_root_path;

		$path = $phpbb_root_path . 'ext/salvocortesiano/meilisearch/composer.json';

		if (file_exists($path))
		{
			$json = @json_decode((string) @file_get_contents($path), true);

			if (!empty($json['version']))
			{
				return (string) $json['version'];
			}
		}

		return '?';
	}

	/* ---------------------------------------------------------------------
	 * Formatting helpers
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $lang_key
	 * @param array  $rows
	 * @return void
	 */
	protected function block($lang_key, array $rows)
	{
		$this->blocks[] = array(
			'title' => $this->language->lang($lang_key),
			'rows'  => $rows,
		);
	}

	/**
	 * @param string $lang_key
	 * @param string $value
	 * @param string $level
	 * @return array
	 */
	protected function row($lang_key, $value, $level)
	{
		$this->tally[$level]++;

		return array(
			'label' => $this->language->lang($lang_key),
			'value' => (string) $value,
			'level' => $level,
		);
	}

	/**
	 * @param string $lang_key
	 * @return void
	 */
	protected function hint($lang_key)
	{
		$this->hints[] = $this->language->lang($lang_key);
	}

	/**
	 * @param float $ms
	 * @return string
	 */
	protected function ms($ms)
	{
		return number_format((float) $ms, 1) . ' ms';
	}

	/**
	 * @param int $bytes
	 * @return string
	 */
	protected function bytes($bytes)
	{
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$i = 0;

		while ($bytes >= 1024 && $i < count($units) - 1)
		{
			$bytes /= 1024;
			$i++;
		}

		return round($bytes, 1) . ' ' . $units[$i];
	}

	/**
	 * Render the whole report as plain text, for pasting into a support thread.
	 *
	 * @param array $report Output of run()
	 * @return string
	 */
	public function as_text(array $report)
	{
		$out = array();
		$out[] = '=== Meilisearch health report — ' . gmdate('Y-m-d H:i:s') . ' UTC ===';
		$out[] = '';

		foreach ($report['blocks'] as $block)
		{
			$out[] = '## ' . $block['title'];

			foreach ($block['rows'] as $row)
			{
				$out[] = sprintf('[%-4s] %-28s %s', strtoupper($row['level']), $row['label'], $row['value']);
			}

			$out[] = '';
		}

		$out[] = sprintf('SUMMARY: %d ok, %d warnings, %d failures',
			$report['tally'][self::OK], $report['tally'][self::WARN], $report['tally'][self::FAIL]);

		if (!empty($report['hints']))
		{
			$out[] = '';
			$out[] = '## Suggestions';

			foreach ($report['hints'] as $hint)
			{
				$out[] = '- ' . strip_tags(html_entity_decode($hint, ENT_QUOTES, 'UTF-8'));
			}
		}

		return implode("\n", $out);
	}
}
