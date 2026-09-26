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
 * Adds the option that restyles phpBB's highlighting on the search pages.
 *
 * On by default, unlike meilisearch_highlight: this one costs nothing at all -
 * it is a stylesheet rule, not a request - and it fixes something that is
 * plainly wrong rather than adding a feature. prosilver's muted pink is hard to
 * pick out, and styles derived from it often soften it further until the
 * highlighting is effectively invisible.
 */
class m8_hilit_style extends \phpbb\db\migration\migration
{
	/**
	 * @return bool
	 */
	public function effectively_installed()
	{
		return isset($this->config['meilisearch_hilit_style']);
	}

	/**
	 * @return array
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\meilisearch\migrations\v10x\m7_highlight');
	}

	/**
	 * @return array
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('meilisearch_hilit_style', 1)),
		);
	}

	/**
	 * @return array
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('meilisearch_hilit_style')),
		);
	}
}
