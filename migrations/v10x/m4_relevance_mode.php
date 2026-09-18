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
 * Replaces the boolean meilisearch_relevance with a three-way mode.
 *
 * Background: the old setting turned relevance ordering off as soon as the
 * request carried an "sk" parameter. phpBB's advanced search form pre-selects
 * "Sort results by: Post time", so submitting it always sends sk=t even when
 * the user never touched the control. In practice relevance therefore only
 * applied to the header quick-search, and a two-word query returned
 * newest-first instead of best-match-first.
 *
 * Boards that had relevance enabled are moved to RELEVANCE_DEFAULT, which is
 * what they were asking for in the first place. Boards that had it disabled
 * stay disabled.
 */
class m4_relevance_mode extends \phpbb\db\migration\migration
{
	/**
	 * @return bool
	 */
	public function effectively_installed()
	{
		return isset($this->config['meilisearch_relevance_mode']);
	}

	/**
	 * @return array
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\meilisearch\migrations\v10x\m3_search_banner');
	}

	/**
	 * @return array
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('meilisearch_relevance_mode', 2)),
			array('custom', array(array($this, 'carry_over_old_setting'))),
			array('config.remove', array('meilisearch_relevance')),
		);
	}

	/**
	 * @return array
	 */
	public function revert_data()
	{
		return array(
			array('config.add', array('meilisearch_relevance', 1)),
			array('config.remove', array('meilisearch_relevance_mode')),
		);
	}

	/**
	 * Preserve an explicit opt-out from the previous version.
	 *
	 * @return void
	 */
	public function carry_over_old_setting()
	{
		if (isset($this->config['meilisearch_relevance']) && !$this->config['meilisearch_relevance'])
		{
			$this->config->set('meilisearch_relevance_mode', 0);
		}
	}
}
