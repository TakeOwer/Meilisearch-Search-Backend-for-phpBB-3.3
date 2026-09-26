<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\meilisearch\meili;

/**
 * Builds the payload sent to Meilisearch's search endpoint.
 *
 * Extracted from the backend so it can be exercised without a phpBB
 * installation: it takes plain values in and returns an array, touching no
 * database, no config object and no HTTP. The filter string it produces is the
 * part most likely to be got wrong, and the part where a mistake is expensive,
 * so it is the part that has tests.
 *
 * Nothing here is authoritative for permissions. Whatever this class filters,
 * meilisearch_backend::refine_with_sql() still re-applies phpBB's own SQL to
 * the candidate ids afterwards. The visibility filter below narrows the
 * candidate set; it does not decide what the user may read.
 */
class query_builder
{
	/** phpBB post visibility states, mirrored from includes/constants.php so
	 *  this class stays free of phpBB at test time. */
	const ITEM_APPROVED = 1;

	/**
	 * Assemble the search payload.
	 *
	 * @param string $query           Cleaned query string
	 * @param string $fields          'titleonly'|'msgonly'|'firstpost'|'all'
	 * @param array  $options         See below
	 * @return array Payload for POST /indexes/<uid>/search
	 *
	 * $options accepts:
	 *   ex_fid_ary      array  Forum ids the user may not read
	 *   topic_id        int    Restrict to one topic, 0 for none
	 *   author_ary      array  Restrict to these poster ids
	 *   author_name     bool   True when a guest name is also being matched
	 *   sort_days       int    Maximum post age in days, 0 for no limit
	 *   approved_only   bool   True when the user moderates nothing anywhere
	 *   limit           int    Candidate cap
	 *   locales         array  ISO 639 codes, empty for auto-detection
	 *   now             int    Current timestamp, injectable for tests
	 */
	public function build($query, $fields, array $options = array())
	{
		$options = array_merge(array(
			'ex_fid_ary'    => array(),
			'topic_id'      => 0,
			'author_ary'    => array(),
			'author_name'   => false,
			'sort_days'     => 0,
			'approved_only' => false,
			'limit'         => 1000,
			'locales'       => array(),
			'now'           => 0,
		), $options);

		$now = $options['now'] ? (int) $options['now'] : time();

		$filters = $this->build_filters($fields, $options, $now);

		$payload = array(
			'q'                    => (string) $query,
			'limit'                => max(100, (int) $options['limit']),
			'attributesToRetrieve' => array('post_id'),
			'attributesToSearchOn' => $this->searchable_attributes($fields),
		);

		if (!empty($filters))
		{
			$payload['filter'] = implode(' AND ', $filters);
		}

		if (!empty($options['locales']))
		{
			$payload['locales'] = array_values($options['locales']);
		}

		return $payload;
	}

	/**
	 * @param string $fields
	 * @param array  $options
	 * @param int    $now
	 * @return array Filter expressions, to be joined with AND
	 */
	protected function build_filters($fields, array $options, $now)
	{
		$filters = array();

		if (!empty($options['ex_fid_ary']))
		{
			$filters[] = 'forum_id NOT IN [' . $this->int_list($options['ex_fid_ary']) . ']';
		}

		if ((int) $options['topic_id'])
		{
			$filters[] = 'topic_id = ' . (int) $options['topic_id'];
		}

		// A guest-name search also has to match post_username, which is not
		// indexed, so the author filter has to stay in SQL for that case.
		if (!empty($options['author_ary']) && empty($options['author_name']))
		{
			$filters[] = 'poster_id IN [' . $this->int_list($options['author_ary']) . ']';
		}

		if ((int) $options['sort_days'])
		{
			$filters[] = 'post_time >= ' . (int) ($now - ((int) $options['sort_days'] * 86400));
		}

		// Pushing visibility down matters for accuracy, not for security. The
		// candidate cap is finite: without this, unapproved and soft-deleted
		// posts consume slots that SQL then discards, so a broad query reports
		// fewer results than exist. Only applied when the user moderates
		// nothing anywhere, because that is the only case where "approved" is
		// the whole of phpBB's visibility rule.
		if (!empty($options['approved_only']))
		{
			$filters[] = 'post_visibility = ' . self::ITEM_APPROVED;
		}

		if ($fields === 'titleonly' || $fields === 'firstpost')
		{
			$filters[] = 'is_first_post = 1';
		}

		return $filters;
	}

	/**
	 * @param string $fields
	 * @return array
	 */
	protected function searchable_attributes($fields)
	{
		switch ($fields)
		{
			case 'titleonly':
				return array('post_subject');

			case 'msgonly':
				return array('post_text');

			default:
				return array('post_subject', 'post_text');
		}
	}

	/**
	 * @param array $values
	 * @return string Comma separated integers, de-duplicated
	 */
	protected function int_list(array $values)
	{
		$ints = array_values(array_unique(array_map('intval', $values)));

		return implode(', ', $ints);
	}
}
