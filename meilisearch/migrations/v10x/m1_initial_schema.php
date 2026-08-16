<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\meilisearch\migrations\v10x;

/**
 * Initial installation.
 *
 * Creates:
 *   - phpbb_meili_queue : retry queue for index operations that failed to reach
 *                         Meilisearch. Stores post ids only, never content.
 *   - the extension's config keys
 *   - the ACP module under the "Extensions" tab
 *
 * Note on revert: the search_type config key is owned by phpBB, not by this
 * extension. If an admin removes the extension while Meilisearch is the active
 * backend, revert_data() puts the board back on the native backend so the forum
 * does not end up with a search page that fatals.
 */
class m1_initial_schema extends \phpbb\db\migration\migration
{
	/**
	 * @return bool True when the extension is already installed
	 */
	public function effectively_installed()
	{
		return isset($this->config['meilisearch_url']);
	}

	/**
	 * @return array
	 */
	public static function depends_on()
	{
		return array('\phpbb\db\migration\data\v330\v330');
	}

	/**
	 * @return array
	 */
	public function update_schema()
	{
		return array(
			'add_tables' => array(
				$this->table_prefix . 'meili_queue' => array(
					'COLUMNS' => array(
						'item_id'      => array('UINT', null, 'auto_increment'),
						'post_id'      => array('UINT', 0),
						'queue_action' => array('VCHAR:1', 'u'),
						'queue_time'   => array('TIMESTAMP', 0),
					),
					'PRIMARY_KEY' => 'item_id',
					'KEYS'        => array(
						'mq_post_id' => array('INDEX', 'post_id'),
					),
				),
			),
		);
	}

	/**
	 * @return array
	 */
	public function revert_schema()
	{
		return array(
			'drop_tables' => array(
				$this->table_prefix . 'meili_queue',
			),
		);
	}

	/**
	 * @return array
	 */
	public function update_data()
	{
		return array(
			// Connection
			array('config.add', array('meilisearch_url', 'http://127.0.0.1:7700')),
			array('config.add', array('meilisearch_api_key', '')),
			array('config.add', array('meilisearch_index', 'phpbb_posts')),
			array('config.add', array('meilisearch_timeout', 5)),

			// Indexing behaviour
			array('config.add', array('meilisearch_batch_size', 250)),
			array('config.add', array('meilisearch_queue_enable', 1)),
			array('config.add', array('meilisearch_queue_gc', 300)),
			array('config.add', array('meilisearch_queue_last_gc', 0, true)),

			// Query behaviour
			array('config.add', array('meilisearch_max_results', 1000)),
			array('config.add', array('meilisearch_relevance', 1)),
			array('config.add', array('meilisearch_typo', 1)),
			array('config.add', array('meilisearch_locales', '')),
			array('config.add', array('meilisearch_min_chars', 2)),
			array('config.add', array('meilisearch_max_chars', 100)),

			// ACP module
			array('module.add', array('acp', 'ACP_CAT_DOT_MODS', 'ACP_MEILISEARCH_TITLE')),
			array('module.add', array(
				'acp',
				'ACP_MEILISEARCH_TITLE',
				array(
					'module_basename' => '\salvocortesiano\meilisearch\acp\main_module',
					'modes'           => array('diagnostics'),
				),
			)),
		);
	}

	/**
	 * @return array
	 */
	public function revert_data()
	{
		return array(
			array('custom', array(array($this, 'restore_native_backend'))),
		);
	}

	/**
	 * Put the board back on the native search backend if we are the active one.
	 *
	 * @return void
	 */
	public function restore_native_backend()
	{
		if (strpos((string) $this->config['search_type'], 'meilisearch') !== false)
		{
			$this->config->set('search_type', '\phpbb\search\fulltext_native');
		}
	}
}
