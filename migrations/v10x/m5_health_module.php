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
 * Registers the health report module.
 *
 * No configuration is added: the report is read-only and has nothing to tune.
 */
class m5_health_module extends \phpbb\db\migration\migration
{
	/**
	 * @return array
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\meilisearch\migrations\v10x\m4_relevance_mode');
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
					'modes'           => array('health'),
				),
			)),
		);
	}
}
