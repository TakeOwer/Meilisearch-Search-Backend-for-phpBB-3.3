<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\meilisearch\acp;

/**
 * Two ACP screens:
 *
 *   - diagnostics : connection test, live index statistics, retry queue
 *   - forums      : which forums may be written to the Meilisearch index
 *
 * Connection settings themselves live in ACP -> General -> Board configuration
 * -> Search settings, because that is where phpBB renders whatever a search
 * backend's acp() method returns. Duplicating them here would create two sources
 * of truth.
 */
class main_module
{
	/** @var string */
	public $u_action;

	/** @var string */
	public $tpl_name;

	/** @var string */
	public $page_title;

	/**
	 * @param int    $id
	 * @param string $mode
	 * @return void
	 */
	public function main($id, $mode)
	{
		global $user, $phpbb_container;

		$user->add_lang_ext('salvocortesiano/meilisearch', 'common');

		/** @var \salvocortesiano\meilisearch\meili\indexer $indexer */
		$indexer = $phpbb_container->get('salvocortesiano.meilisearch.indexer');

		switch ($mode)
		{
			case 'forums':
				$this->forums_mode($indexer);
			break;

			default:
				$this->diagnostics_mode($indexer);
			break;
		}
	}

	/* ---------------------------------------------------------------------
	 * Diagnostics
	 * ------------------------------------------------------------------ */

	/**
	 * @param \salvocortesiano\meilisearch\meili\indexer $indexer
	 * @return void
	 */
	protected function diagnostics_mode($indexer)
	{
		global $config, $request, $template, $user, $phpbb_log, $phpEx;

		$this->tpl_name   = 'acp_meilisearch';
		$this->page_title = 'ACP_MEILISEARCH_DIAGNOSTICS';

		add_form_key('salvocortesiano_meilisearch');

		$client  = $indexer->get_client();
		$action  = $request->variable('action', '');
		$notices = array();
		$errors  = array();

		if ($action !== '')
		{
			if (!check_form_key('salvocortesiano_meilisearch'))
			{
				trigger_error($user->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			switch ($action)
			{
				case 'apply_settings':
					if ($indexer->apply_settings())
					{
						$notices[] = $user->lang('MEILISEARCH_SETTINGS_APPLIED');
						$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_MEILISEARCH_SETTINGS_APPLIED');
					}
					else
					{
						$errors[] = $indexer->last_error();
					}
				break;

				case 'generate_key':
					// The master key is read from the request and used for a single
					// API call. It is never written to phpbb_config.
					$master_key = $request->variable('master_key', '', true);

					if (trim($master_key) === '')
					{
						$errors[] = $user->lang('MEILISEARCH_KEY_MASTER_REQUIRED');
						break;
					}

					$generated = $indexer->generate_api_key($master_key);

					unset($master_key);

					if ($generated === false)
					{
						$errors[] = $indexer->last_error();
					}
					else
					{
						$notices[] = $user->lang('MEILISEARCH_KEY_GENERATED');
						$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_MEILISEARCH_KEY_GENERATED');
					}
				break;

				case 'test_connection':
					$started = microtime(true);
					$health  = $client->health();
					$total   = round((microtime(true) - $started) * 1000, 1);

					if ($health === false)
					{
						$errors[] = $user->lang('MEILISEARCH_TEST_CONN_FAIL', $client->last_error());
					}
					else
					{
						$notices[] = $user->lang('MEILISEARCH_TEST_CONN_OK', $client->last_duration_ms(), $total);
					}
				break;

				case 'test_index':
					// A real query, not just a stats read: this is the only check
					// that exercises the whole path the search page depends on.
					$started = microtime(true);
					$probe   = $client->search($indexer->get_index_uid(), array(
						'q'                    => '',
						'limit'                => 1,
						'attributesToRetrieve' => array('post_id'),
					));
					$total = round((microtime(true) - $started) * 1000, 1);

					if ($probe === false)
					{
						$errors[] = $user->lang('MEILISEARCH_TEST_INDEX_FAIL', $client->last_error());
					}
					else
					{
						$hits = isset($probe['estimatedTotalHits'])
							? (int) $probe['estimatedTotalHits']
							: (isset($probe['totalHits']) ? (int) $probe['totalHits'] : 0);

						$notices[] = $user->lang('MEILISEARCH_TEST_INDEX_OK', $hits, $total);
					}
				break;

				case 'test_key':
					// Probe each capability the extension actually relies on, so a
					// key that is merely present but under-privileged is caught
					// here instead of failing silently during a reindex.
					$index  = $indexer->get_index_uid();
					$checks = array();

					$checks['search'] = $client->search($index, array('q' => '', 'limit' => 1)) !== false;
					$checks['stats.get'] = $client->index_stats($index) !== false;
					$checks['settings.get'] = $client->get_settings($index) !== false;
					$checks['indexes.get'] = $client->get_index($index) !== false;

					$ok_list   = array();
					$fail_list = array();

					foreach ($checks as $action => $passed)
					{
						if ($passed)
						{
							$ok_list[] = $action;
						}
						else
						{
							$fail_list[] = $action;
						}
					}

					if (empty($fail_list))
					{
						$notices[] = $user->lang('MEILISEARCH_TEST_KEY_OK', implode(', ', $ok_list));
					}
					else
					{
						$errors[] = $user->lang('MEILISEARCH_TEST_KEY_FAIL', implode(', ', $fail_list), $client->last_error());
					}
				break;

				case 'flush_queue':
					$processed = $indexer->flush_queue(2000);
					$notices[] = $user->lang('MEILISEARCH_QUEUE_FLUSHED', (int) $processed);
				break;

				case 'clear_queue':
					$indexer->clear_queue();
					$notices[] = $user->lang('MEILISEARCH_QUEUE_CLEARED');
					$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_MEILISEARCH_QUEUE_CLEARED');
				break;
			}
		}

		$health_ok   = false;
		$version     = '';
		$documents   = 0;
		$is_indexing = false;
		$index_ok    = false;
		$probe_error = '';

		if (!$client->is_configured())
		{
			$probe_error = $user->lang('MEILISEARCH_NO_URL');
		}
		else if ($client->health() === false)
		{
			$probe_error = $client->last_error();
		}
		else
		{
			$health_ok = true;

			// /version is a global endpoint. A key scoped to one index cannot call
			// it, so a failure here is expected and must not be surfaced as an
			// error: it would look like a broken connection when it is not.
			$version_info = $client->version();

			if ($version_info !== false && isset($version_info['pkgVersion']))
			{
				$version = $version_info['pkgVersion'];
			}

			$stats = $client->index_stats($indexer->get_index_uid());

			if ($stats === false)
			{
				// index_not_found simply means the index has not been created yet,
				// which the Index panel already reports. Only surface real errors.
				$probe_error = (strpos($client->last_error(), 'index_not_found') === false)
					? $client->last_error()
					: '';
			}
			else
			{
				$index_ok    = true;
				$documents   = isset($stats['numberOfDocuments']) ? (int) $stats['numberOfDocuments'] : 0;
				$is_indexing = !empty($stats['isIndexing']);
			}
		}

		$excluded = $indexer->get_excluded_forum_ids();

		$template->assign_vars(array(
			'U_ACTION'				=> $this->u_action,
			'U_SEARCH_SETTINGS'		=> append_sid('index.' . $phpEx, 'i=acp_search&amp;mode=settings'),
			'U_SEARCH_INDEX'		=> append_sid('index.' . $phpEx, 'i=acp_search&amp;mode=index'),

			'MEILI_URL'				=> $client->get_url(),
			'MEILI_INDEX'			=> $indexer->get_index_uid(),
			'MEILI_LOCALES'			=> implode(', ', $indexer->get_locales()),
			'MEILI_VERSION'			=> $version,
			'MEILI_DOCUMENTS'		=> $documents,
			'MEILI_QUEUE_SIZE'		=> $indexer->queue_size(),
			'MEILI_EXCLUDED_COUNT'	=> count($excluded),
			'MEILI_KEY_ACTIONS'		=> implode(', ', $indexer->get_required_key_actions()),

			'S_HEALTH_OK'			=> $health_ok,
			'S_INDEX_OK'			=> $index_ok,
			'S_IS_INDEXING'			=> $is_indexing,
			'S_BACKEND_ACTIVE'		=> strpos((string) $config['search_type'], 'meilisearch') !== false,
			'S_QUEUE_ENABLED'		=> (bool) $config['meilisearch_queue_enable'],
			'S_CURL_AVAILABLE'		=> function_exists('curl_init'),
			'S_HAS_API_KEY'			=> trim((string) $config['meilisearch_api_key']) !== '',

			'PROBE_ERROR'			=> $probe_error,
			'NOTICES'				=> implode('<br />', $notices),
			'ERRORS'				=> implode('<br />', $errors),
		));
	}

	/* ---------------------------------------------------------------------
	 * Indexed forums
	 * ------------------------------------------------------------------ */

	/**
	 * @param \salvocortesiano\meilisearch\meili\indexer $indexer
	 * @return void
	 */
	protected function forums_mode($indexer)
	{
		global $db, $request, $template, $user, $phpbb_log;

		$this->tpl_name   = 'acp_meilisearch_forums';
		$this->page_title = 'ACP_MEILISEARCH_FORUMS';

		add_form_key('salvocortesiano_meilisearch_forums');

		$notices = array();
		$errors  = array();

		$action    = $request->variable('action', '');
		$preselect = false;

		if ($action !== '')
		{
			if (!check_form_key('salvocortesiano_meilisearch_forums'))
			{
				trigger_error($user->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			switch ($action)
			{
				case 'save':
					// The checkbox marks a forum as EXCLUDED, so the posted array is
					// the exclusion list itself.
					$posted = $request->variable('exclude', array(0));

					$indexer->set_excluded_forum_ids($posted);

					$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_MEILISEARCH_FORUMS_SAVED');
					$notices[] = $user->lang('MEILISEARCH_FORUMS_SAVED');
				break;

				case 'preselect':
					// Only fills the form; nothing is saved until the admin submits.
					$preselect = true;
					$notices[] = $user->lang('MEILISEARCH_FORUMS_PRESELECTED');
				break;

				case 'purge_excluded':
					if ($indexer->purge_excluded_forums())
					{
						$notices[] = $user->lang('MEILISEARCH_FORUMS_PURGED');
						$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_MEILISEARCH_FORUMS_PURGED');
					}
					else
					{
						$errors[] = $indexer->last_error();
					}
				break;
			}
		}

		$excluded = $preselect
			? $indexer->get_guest_unreadable_forum_ids()
			: $indexer->get_excluded_forum_ids();

		$excluded_lookup = array_flip($excluded);

		$sql = 'SELECT forum_id, parent_id, forum_name, forum_type, enable_indexing
			FROM ' . FORUMS_TABLE . '
			ORDER BY left_id ASC';
		$result = $db->sql_query($sql);

		$excluded_count = 0;
		$included_count = 0;

		while ($row = $db->sql_fetchrow($result))
		{
			$forum_id  = (int) $row['forum_id'];
			$is_forum  = ((int) $row['forum_type'] === FORUM_POST);
			$is_hidden = isset($excluded_lookup[$forum_id]);

			if ($is_forum)
			{
				if ($is_hidden)
				{
					$excluded_count++;
				}
				else
				{
					$included_count++;
				}
			}

			$template->assign_block_vars('forums', array(
				'FORUM_ID'			=> $forum_id,
				'FORUM_NAME'		=> $row['forum_name'],
				'S_IS_FORUM'		=> $is_forum,
				'S_IS_CATEGORY'		=> ((int) $row['forum_type'] === FORUM_CAT),
				'S_EXCLUDED'		=> $is_hidden,
				'S_NO_INDEXING'		=> !((int) $row['enable_indexing']),
			));
		}

		$db->sql_freeresult($result);

		$template->assign_vars(array(
			'U_ACTION'			=> $this->u_action,
			'EXCLUDED_COUNT'	=> $excluded_count,
			'INCLUDED_COUNT'	=> $included_count,
			'S_PRESELECTED'		=> $preselect,
			'NOTICES'			=> implode('<br />', $notices),
			'ERRORS'			=> implode('<br />', $errors),
		));
	}
}
