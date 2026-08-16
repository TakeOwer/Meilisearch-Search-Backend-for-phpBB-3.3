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
 * Adds the front-end notice shown on the search pages.
 *
 * Off by default: an existing board should not suddenly grow a new banner for
 * every user just because the extension was updated.
 */
class m3_search_banner extends \phpbb\db\migration\migration
{
	/**
	 * @return bool
	 */
	public function effectively_installed()
	{
		return isset($this->config['meilisearch_banner_enable']);
	}

	/**
	 * @return array
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\meilisearch\migrations\v10x\m2_forum_exclusions');
	}

	/**
	 * @return array
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('meilisearch_banner_enable', 0)),
		);
	}

	/**
	 * @return array
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('meilisearch_banner_enable')),
		);
	}
}
