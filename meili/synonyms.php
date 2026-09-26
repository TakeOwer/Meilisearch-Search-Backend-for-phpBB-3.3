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
 * Turns the administrator's synonym list into Meilisearch's settings format.
 *
 * Admins write one group per line:
 *
 *     sottotitoli, sub, subs
 *     ita, italiano
 *     1080p, fullhd, full hd
 *
 * Every term in a group becomes a synonym of every other term. Meilisearch
 * stores synonyms as a one-way map, so a group of three produces three entries,
 * each listing the other two. Declaring only one direction is the classic
 * mistake: searching "sub" would then find "sottotitoli" but not the reverse.
 *
 * Kept free of phpBB so it can be tested directly; the ACP module and the
 * indexer both go through it.
 */
class synonyms
{
	/** Upper bounds, to keep a pasted novel from becoming an index setting */
	const MAX_GROUPS = 500;
	const MAX_TERMS_PER_GROUP = 20;
	const MAX_TERM_LENGTH = 100;

	/**
	 * Parse the raw textarea contents into a Meilisearch synonym map.
	 *
	 * Invalid lines are skipped rather than rejected: a single typo in a long
	 * list should not throw the whole list away.
	 *
	 * @param string $raw
	 * @return array ['term' => ['other', 'other'], ...]
	 */
	public function parse($raw)
	{
		$map = array();
		$groups = 0;

		foreach (preg_split('/\R/', (string) $raw) as $line)
		{
			$line = trim($line);

			// '#' starts a comment, so admins can annotate their own list
			if ($line === '' || $line[0] === '#')
			{
				continue;
			}

			if ($groups >= self::MAX_GROUPS)
			{
				break;
			}

			$terms = $this->clean_terms(explode(',', $line));

			// A group of one says nothing: a term is not its own synonym
			if (count($terms) < 2)
			{
				continue;
			}

			$groups++;

			foreach ($terms as $term)
			{
				$others = array_values(array_diff($terms, array($term)));

				$map[$term] = isset($map[$term])
					? array_values(array_unique(array_merge($map[$term], $others)))
					: $others;
			}
		}

		return $map;
	}

	/**
	 * Report what is wrong with a list, for display in the ACP.
	 *
	 * Separate from parse() on purpose: parsing has to be forgiving at runtime,
	 * but the administrator deserves to be told which line was ignored.
	 *
	 * @param string $raw
	 * @return array Human readable problems, empty when the list is clean
	 */
	public function validate($raw)
	{
		$problems = array();
		$number = 0;
		$groups = 0;

		foreach (preg_split('/\R/', (string) $raw) as $line)
		{
			$number++;
			$line = trim($line);

			if ($line === '' || $line[0] === '#')
			{
				continue;
			}

			$groups++;

			if ($groups > self::MAX_GROUPS)
			{
				$problems[] = array('line' => $number, 'reason' => 'too_many_groups');
				break;
			}

			$raw_terms = explode(',', $line);
			$terms = $this->clean_terms($raw_terms);

			if (count($terms) < 2)
			{
				$problems[] = array('line' => $number, 'reason' => 'needs_two_terms');
				continue;
			}

			if (count($raw_terms) > self::MAX_TERMS_PER_GROUP)
			{
				$problems[] = array('line' => $number, 'reason' => 'too_many_terms');
			}
		}

		return $problems;
	}

	/**
	 * @param string $raw
	 * @return int Number of usable groups
	 */
	public function count_groups($raw)
	{
		$groups = 0;

		foreach (preg_split('/\R/', (string) $raw) as $line)
		{
			$line = trim($line);

			if ($line === '' || $line[0] === '#')
			{
				continue;
			}

			if (count($this->clean_terms(explode(',', $line))) >= 2)
			{
				$groups++;
			}
		}

		return $groups;
	}

	/**
	 * @param array $terms
	 * @return array Trimmed, lower-cased, de-duplicated, length-capped
	 */
	protected function clean_terms(array $terms)
	{
		$out = array();

		foreach ($terms as $term)
		{
			// Meilisearch matches synonyms case-insensitively; normalising here
			// keeps "ITA" and "ita" from becoming two separate entries.
			$term = $this->lower(trim(preg_replace('/\s+/u', ' ', (string) $term)));

			if ($term === '' || $this->length($term) > self::MAX_TERM_LENGTH)
			{
				continue;
			}

			$out[] = $term;
		}

		$out = array_values(array_unique($out));

		return array_slice($out, 0, self::MAX_TERMS_PER_GROUP);
	}
	/**
	 * Lower-case a term.
	 *
	 * mbstring is a hard requirement of the extension (see ext.php), but these
	 * two helpers make the fallback explicit in one place rather than guarding
	 * some calls and forgetting others - which is exactly the bug the test
	 * suite caught here.
	 *
	 * @param string $term
	 * @return string
	 */
	protected function lower($term)
	{
		return function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower($term);
	}

	/**
	 * @param string $term
	 * @return int Length in characters, not bytes, where mbstring allows it
	 */
	protected function length($term)
	{
		return function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : strlen($term);
	}
	/**
	 * Read one of the starter lists shipped with the extension.
	 *
	 * These live in data/ inside the extension, are read-only, and are replaced
	 * on every update - which is the point: corrections and additions arrive
	 * with the code. The administrator's own list is never stored here. It sits
	 * in config_text, in the database, precisely because the usual update
	 * procedure deletes the extension folder and anything in it.
	 *
	 * Takes the directory rather than building the path from a vendor name: a
	 * hard-coded 'ext/salvocortesiano/...' inside the class would break the
	 * moment the extension is renamed, and would make this method untestable
	 * outside a real phpBB tree.
	 *
	 * @param string $data_dir Directory holding the starter files, with trailing slash
	 * @param string $locale   'it' or 'en'
	 * @return string Raw contents, or '' when the file is missing
	 */
	public function read_starter($data_dir, $locale)
	{
		// Not forced to a known pair: the whole point of scanning the folder is
		// that a language this code has never heard of works too. The code is
		// sanitised instead, so it can never escape the directory.
		$locale = $this->clean_code($locale);

		if ($locale === '')
		{
			return '';
		}

		$path = rtrim((string) $data_dir, '/\\') . '/synonyms_' . $locale . '.txt';

		if (!file_exists($path))
		{
			return '';
		}

		$raw = @file_get_contents($path);

		return ($raw === false) ? '' : (string) $raw;
	}

	/**
	 * Combine two lists without losing either.
	 *
	 * Groups already present are not repeated: two lines listing the same terms
	 * in a different order are the same group, so they are compared on their
	 * sorted terms rather than as text.
	 *
	 * @param string $existing
	 * @param string $addition
	 * @return string
	 */
	public function append($existing, $addition)
	{
		$existing = rtrim((string) $existing);
		$seen = array();

		foreach (preg_split('/\R/', $existing) as $line)
		{
			$key = $this->group_key($line);

			if ($key !== '')
			{
				$seen[$key] = true;
			}
		}

		$new_lines = array();

		foreach (preg_split('/\R/', (string) $addition) as $line)
		{
			$trimmed = trim($line);

			// Comments are carried across as-is: they are what makes the
			// starter list readable once it is in the textarea.
			if ($trimmed === '' || $trimmed[0] === '#')
			{
				$new_lines[] = $trimmed;
				continue;
			}

			$key = $this->group_key($line);

			if ($key === '' || isset($seen[$key]))
			{
				continue;
			}

			$seen[$key] = true;
			$new_lines[] = $trimmed;
		}

		// Drop comment blocks that ended up with no group under them
		$new_lines = $this->strip_orphan_comments($new_lines);

		if (empty($new_lines))
		{
			return $existing;
		}

		return ($existing === '')
			? implode("\n", $new_lines)
			: $existing . "\n\n" . implode("\n", $new_lines);
	}

	/**
	 * @param array $lines
	 * @return array
	 */
	protected function strip_orphan_comments(array $lines)
	{
		$out = array();
		$pending = array();

		foreach ($lines as $line)
		{
			if ($line === '' || $line[0] === '#')
			{
				$pending[] = $line;
				continue;
			}

			$out = array_merge($out, $pending, array($line));
			$pending = array();
		}

		// trailing comments with nothing after them are dropped
		return $out;
	}

	/**
	 * A stable identity for a group, independent of term order.
	 *
	 * @param string $line
	 * @return string
	 */
	protected function group_key($line)
	{
		$line = trim($line);

		if ($line === '' || $line[0] === '#')
		{
			return '';
		}

		$terms = $this->clean_terms(explode(',', $line));

		if (count($terms) < 2)
		{
			return '';
		}

		sort($terms);

		return implode('|', $terms);
	}
	/**
	 * Every starter list present in the data directory.
	 *
	 * Scanned rather than listed in code, exactly as phpBB scans language/
	 * instead of holding an array of locales: dropping synonyms_fr.txt into the
	 * folder is enough for it to appear in the ACP, with no PHP to edit.
	 *
	 * The display name comes from an optional "# name: ..." header inside the
	 * file, so a language this extension has never heard of still gets a
	 * readable button instead of a bare code.
	 *
	 * @param string $data_dir Directory holding the starter files
	 * @return array List of ['code' => 'it', 'name' => 'Italiano'], sorted by name
	 */
	public function list_starters($data_dir)
	{
		$dir = rtrim((string) $data_dir, '/\\');

		if ($dir === '' || !is_dir($dir))
		{
			return array();
		}

		$found = array();

		foreach ((array) @glob($dir . '/synonyms_*.txt') as $path)
		{
			if (!is_file($path) || !is_readable($path))
			{
				continue;
			}

			$code = $this->code_from_filename(basename($path));

			if ($code === '')
			{
				continue;
			}

			$found[$code] = array(
				'code' => $code,
				'name' => $this->name_from_file($path, $code),
			);
		}

		uasort($found, function ($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});

		return array_values($found);
	}

	/**
	 * Is this a starter list the extension actually has?
	 *
	 * Used to validate a code arriving from a request before it is turned into
	 * a file name.
	 *
	 * @param string $data_dir
	 * @param string $code
	 * @return bool
	 */
	public function starter_exists($data_dir, $code)
	{
		foreach ($this->list_starters($data_dir) as $starter)
		{
			if ($starter['code'] === $this->clean_code($code))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $filename
	 * @return string Language code, or '' when the name does not fit the pattern
	 */
	protected function code_from_filename($filename)
	{
		if (!preg_match('/^synonyms_([A-Za-z0-9_-]{2,16})\.txt$/', $filename, $m))
		{
			return '';
		}

		return $this->clean_code($m[1]);
	}

	/**
	 * Read the display name out of the file header.
	 *
	 * @param string $path
	 * @param string $code Fallback when no header is present
	 * @return string
	 */
	protected function name_from_file($path, $code)
	{
		$handle = @fopen($path, 'r');

		if ($handle === false)
		{
			return strtoupper($code);
		}

		$name = '';
		$lines = 0;

		// The header is expected at the top; reading the whole file to find it
		// would be wasteful on a long list.
		while ($lines < 15 && ($line = fgets($handle)) !== false)
		{
			$lines++;

			if (preg_match('/^#\s*name:\s*(.+)$/i', trim($line), $m))
			{
				$name = trim($m[1]);
				break;
			}
		}

		fclose($handle);

		// Strip anything that could break out of the button label
		$name = trim(preg_replace('/[<>"\']/', '', $name));

		return ($name !== '') ? $name : strtoupper($code);
	}

	/**
	 * @param string $code
	 * @return string
	 */
	public function clean_code($code)
	{
		$code = strtolower(trim((string) $code));

		// Rejected outright rather than stripped. Stripping turns
		// '../../etc/passwd' into 'etcpasswd', which is harmless but is a value
		// the caller never asked for; refusing it makes the intent explicit and
		// keeps a malformed code from silently becoming a different one.
		return preg_match('/^[a-z0-9_-]{2,16}$/', $code) ? $code : '';
	}
}
