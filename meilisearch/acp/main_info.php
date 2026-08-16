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

class main_info
{
	/**
	 * @return array
	 */
	public function module()
	{
		return array(
			'filename'	=> '\salvocortesiano\meilisearch\acp\main_module',
			'title'		=> 'ACP_MEILISEARCH_TITLE',
			'modes'		=> array(
				'diagnostics'	=> array(
					'title'	=> 'ACP_MEILISEARCH_DIAGNOSTICS',
					'auth'	=> 'ext_salvocortesiano/meilisearch && acl_a_search',
					'cat'	=> array('ACP_MEILISEARCH_TITLE'),
				),
				'forums'		=> array(
					'title'	=> 'ACP_MEILISEARCH_FORUMS',
					'auth'	=> 'ext_salvocortesiano/meilisearch && acl_a_search',
					'cat'	=> array('ACP_MEILISEARCH_TITLE'),
				),
			),
		);
	}
}
