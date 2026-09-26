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
 * Adds the synonym list and registers its ACP page.
 *
 * The list itself goes in config_text rather than config: it is free text with
 * no useful length limit, and config is meant for short values.
 *
 * This release also adds attach_names to the indexed document. Nothing here can
 * do that for existing documents - the field is written by the indexer, so the
 * board has to be reindexed before attachment names become searchable. The
 * Indexed forums page has a reindex panel for exactly this, and reindexing
 * replaces documents in place, so search keeps working while it runs.
 */
class m6_synonyms extends \phpbb\db\migration\migration
{
	/**
	 * @return array
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\meilisearch\migrations\v10x\m5_health_module');
	}

	/**
	 * @return array
	 */
	public function update_data()
	{
		return array(
			array('module.add', array(
				'acp',
				'ACP_MEILISEARCH_TITLE',
				array(
					'module_basename' => '\salvocortesiano\meilisearch\acp\main_module',
					'modes'           => array('synonyms'),
				),
			)),
		);
	}
}
