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
 * Adds the per-forum indexing exclusion list.
 *
 * On a fresh install the list is pre-populated with every forum the guest
 * account cannot read. This is a conservative starting point, not a policy: the
 * administrator picks the final list in ACP -> Extensions -> Meilisearch ->
 * Indexed forums, and the saved value is authoritative from then on.
 *
 * The exclusion is a *content* control, not a permission control. phpBB already
 * hides unreadable posts from search results; this list decides what is allowed
 * to leave the database and reach the Meilisearch instance at all.
 */
class m2_forum_exclusions extends \phpbb\db\migration\migration
{
	/**
	 * @return bool
	 */
	public function effectively_installed()
	{
		return isset($this->config['meilisearch_excluded_forums']);
	}

	/**
	 * @return array
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\meilisearch\migrations\v10x\m1_initial_schema');
	}

	/**
	 * @return array
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('meilisearch_excluded_forums', '')),
			array('custom', array(array($this, 'preselect_non_public_forums'))),
			array('module.add', array(
				'acp',
				'ACP_MEILISEARCH_TITLE',
				array(
					'module_basename' => '\salvocortesiano\meilisearch\acp\main_module',
					'modes'           => array('forums'),
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
			array('config.remove', array('meilisearch_excluded_forums')),
		);
	}

	/**
	 * Seed the exclusion list with forums that guests cannot read.
	 *
	 * Done with a direct query rather than through the indexer service, because
	 * migrations run while the container is still being rebuilt and the
	 * extension's own services may not be resolvable yet.
	 *
	 * @return void
	 */
	public function preselect_non_public_forums()
	{
		global $auth;

		if (!($auth instanceof \phpbb\auth\auth))
		{
			return;
		}

		$all_forums = array();

		$sql = 'SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_type = ' . FORUM_POST;
		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$all_forums[] = (int) $row['forum_id'];
		}

		$this->db->sql_freeresult($result);

		if (empty($all_forums))
		{
			return;
		}

		$readable = array();

		foreach ($auth->acl_get_list(ANONYMOUS, 'f_read') as $forum_id => $options)
		{
			if ((int) $forum_id > 0 && !empty($options['f_read']))
			{
				$readable[] = (int) $forum_id;
			}
		}

		$excluded = array_values(array_diff($all_forums, $readable));
		sort($excluded);

		$this->config->set('meilisearch_excluded_forums', implode(',', $excluded));
	}
}
