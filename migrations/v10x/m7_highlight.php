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
 * Adds the option that extends phpBB's highlighting with the words Meilisearch
 * actually matched.
 *
 * Off by default. It costs one extra request per results page, and on a remote
 * Meilisearch instance that is a round trip on a page the visitor is waiting
 * for; an administrator should switch it on knowingly rather than inherit it.
 */
class m7_highlight extends \phpbb\db\migration\migration
{
	/**
	 * @return bool
	 */
	public function effectively_installed()
	{
		return isset($this->config['meilisearch_highlight']);
	}

	/**
	 * @return array
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\meilisearch\migrations\v10x\m6_synonyms');
	}

	/**
	 * @return array
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('meilisearch_highlight', 0)),
		);
	}

	/**
	 * @return array
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('meilisearch_highlight')),
		);
	}
}
