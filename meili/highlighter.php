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
 * Works out which words to add to phpBB's highlight list.
 *
 * Why anything is needed at all
 * ----------------------------
 * search.php builds $hilit from the words the user typed and highlights those.
 * That is fine for the native backend, where a result can only contain words
 * the user typed. With Meilisearch it is not: a post is returned because of a
 * typo variant, a synonym, or a different inflection, and none of those are in
 * $hilit - so the user gets a result with nothing marked in it and no visible
 * reason why it matched.
 *
 * Why it is done this way
 * ----------------------
 * The obvious route is to rewrite the message text and wrap the matches
 * ourselves. That means parsing HTML, because the excerpt already contains
 * links and rendered bbcode, and getting it wrong means highlighting inside an
 * href. phpBB already has a tag-aware regex for this and applies it to both the
 * subject and the body, so the far smaller job is to hand it more words:
 * core.search_modify_rowset exposes $hilit and lets us append to it.
 *
 * Note that by the time that event fires, phpBB has already run preg_quote over
 * the existing pieces of $hilit, so anything added here has to arrive quoted.
 */
class highlighter
{
	/** Meilisearch is asked to wrap matches in these, chosen so they cannot
	 *  collide with anything in a post. */
	const PRE_TAG  = '[[mshl]]';
	const POST_TAG = '[[/mshl]]';

	/** Terms shorter than this are not worth marking and make the page noisy */
	const MIN_TERM_LENGTH = 2;

	/** Upper bound on added terms, to keep the highlight regex sane */
	const MAX_TERMS = 24;

	/**
	 * The payload that asks Meilisearch which words it actually matched.
	 *
	 * Restricted to the post ids on the page being rendered, so this is a
	 * lookup of a handful of documents rather than a second search.
	 *
	 * @param string $query
	 * @param array  $post_ids
	 * @return array
	 */
	public function build_probe($query, array $post_ids)
	{
		$ids = array_values(array_unique(array_map('intval', $post_ids)));

		return array(
			'q'                     => (string) $query,
			'limit'                 => count($ids),
			'filter'                => 'post_id IN [' . implode(', ', $ids) . ']',
			'attributesToRetrieve'  => array('post_id'),
			'attributesToHighlight' => array('post_subject', 'post_text'),
			'highlightPreTag'       => self::PRE_TAG,
			'highlightPostTag'      => self::POST_TAG,
		);
	}

	/**
	 * Pull the matched words out of a Meilisearch response.
	 *
	 * @param array $response Decoded response, or anything falsy
	 * @return array Distinct matched words, lower-cased
	 */
	public function extract_terms($response)
	{
		if (empty($response['hits']) || !is_array($response['hits']))
		{
			return array();
		}

		$found = array();
		$pattern = '/' . preg_quote(self::PRE_TAG, '/') . '(.*?)' . preg_quote(self::POST_TAG, '/') . '/su';

		foreach ($response['hits'] as $hit)
		{
			if (empty($hit['_formatted']) || !is_array($hit['_formatted']))
			{
				continue;
			}

			foreach ($hit['_formatted'] as $value)
			{
				if (!is_string($value))
				{
					continue;
				}

				preg_match_all($pattern, $value, $m);

				foreach ($m[1] as $word)
				{
					$word = $this->normalise($word);

					if ($word !== '')
					{
						$found[$word] = true;
					}
				}
			}
		}

		return array_keys($found);
	}

	/**
	 * Merge extra words into phpBB's highlight string.
	 *
	 * @param string $hilit Existing value, pieces already preg_quoted and
	 *                      joined with a pipe
	 * @param array  $terms Raw words to add
	 * @return string New value for $hilit
	 */
	public function merge($hilit, array $terms)
	{
		$hilit = (string) $hilit;

		// What is already there is quoted, so compare on the unquoted form to
		// avoid adding a word phpBB is highlighting anyway.
		$existing = array();

		foreach (array_filter(explode('|', $hilit), 'strlen') as $piece)
		{
			$existing[$this->normalise(stripslashes($piece))] = true;
		}

		$added = array();

		foreach ($terms as $term)
		{
			$term = $this->normalise($term);

			if ($term === '' || isset($existing[$term]) || isset($added[$term]))
			{
				continue;
			}

			$added[$term] = true;

			if (count($added) >= self::MAX_TERMS)
			{
				break;
			}
		}

		if (empty($added))
		{
			return $hilit;
		}

		$quoted = array();

		foreach (array_keys($added) as $term)
		{
			$quoted[] = preg_quote($term, '#');
		}

		$pieces = array_filter(explode('|', $hilit), 'strlen');
		$pieces = array_merge($pieces, $quoted);

		return implode('|', $pieces);
	}

	/**
	 * @param string $word
	 * @return string Trimmed, lower-cased, or '' when not worth highlighting
	 */
	protected function normalise($word)
	{
		$word = trim(preg_replace('/\s+/u', ' ', (string) $word));

		$word = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtolower($word);

		$length = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);

		if ($length < self::MIN_TERM_LENGTH)
		{
			return '';
		}

		// A match spanning several words would highlight the whitespace between
		// them as one unit; phpBB's regex works on words, so keep it to words.
		if (strpos($word, ' ') !== false)
		{
			return '';
		}

		return $word;
	}
}
