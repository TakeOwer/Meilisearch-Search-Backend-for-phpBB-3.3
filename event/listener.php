<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\meilisearch\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Publishes the "search powered by Meilisearch" notice to the search pages.
 *
 * Two insertion points, never both at once
 * ----------------------------------------
 * The natural hooks are search_body_form_before and search_results_header_before,
 * which put the notice exactly where it belongs, inside the search box. They
 * only fire if the active style actually contains those template events, and
 * heavily customised styles routinely ship a rewritten search_results.html
 * without them, in which case the notice silently never appears there.
 *
 * overall_header_content_before lives in overall_header.html, which every style
 * must provide, so it always works - but it sits higher up the page.
 *
 * So the listener inspects the style's own search templates (walking the style
 * inheritance chain, exactly as phpBB does) and decides:
 *
 *   both events present  -> S_MEILISEARCH_BANNER_INLINE, nicer placement
 *   either one missing   -> S_MEILISEARCH_BANNER_HEADER, guaranteed to render
 *
 * Exactly one flag is ever true, so the notice cannot be drawn twice. Deciding
 * in PHP avoids the alternative of drawing it twice and hiding the duplicate
 * with JavaScript, which would flash on slow connections and fail outright
 * with scripting disabled.
 *
 * The notice is opt-in (meilisearch_banner_enable) and is suppressed whenever
 * Meilisearch is not the active backend: claiming otherwise while phpBB is
 * still on the native backend would be misleading, and admins do switch back
 * and forth while testing.
 */
class listener implements EventSubscriberInterface
{
	/** Template events the search templates must contain for inline placement */
	const REQUIRED_EVENTS = array(
		'search_body.html'		=> 'search_body_form_before',
		'search_results.html'	=> 'search_results_header_before',
	);

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\user */
	protected $user;

	/** @var string */
	protected $root_path;

	/** @var string|null Per-request cache of the version read from composer.json */
	protected $version = null;

	/** @var \phpbb\request\request_interface */
	protected $request;

	/** @var \salvocortesiano\meilisearch\meili\indexer */
	protected $indexer;

	/** @var bool|null Per-request cache of the placement decision */
	protected $supports_inline = null;

	/**
	 * @param \phpbb\config\config     $config
	 * @param \phpbb\template\template $template
	 * @param \phpbb\language\language $language
	 * @param \phpbb\user              $user
	 * @param string                   $root_path
	 */
	public function __construct(\phpbb\config\config $config, \phpbb\template\template $template, \phpbb\language\language $language, \phpbb\user $user, \phpbb\request\request_interface $request, \salvocortesiano\meilisearch\meili\indexer $indexer, $root_path)
	{
		$this->config    = $config;
		$this->template  = $template;
		$this->language  = $language;
		$this->user      = $user;
		$this->request   = $request;
		$this->indexer   = $indexer;
		$this->root_path = (string) $root_path;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			'core.page_header'	=> 'assign_banner',
			'core.user_setup'	=> 'load_log_language',
		);
	}

	/**
	 * Make the admin log strings available everywhere.
	 *
	 * The log viewer is a core ACP module and never loads an extension's own
	 * language files, so a key defined only in common.php is written to the log
	 * table and then rendered as {LOG_SOMETHING}. Loading it here, from
	 * core.user_setup, is the only point early enough to cover that page.
	 *
	 * Only logs.php is loaded globally, never common.php: phpBB explicitly asks
	 * that this event carry nothing beyond what is strictly needed everywhere,
	 * and the several hundred keys in common.php are of no use on a board page.
	 *
	 * @param \phpbb\event\data $event
	 * @return void
	 */
	public function load_log_language($event)
	{
		$lang_set_ext = $event['lang_set_ext'];

		$lang_set_ext[] = array(
			'ext_name' => 'salvocortesiano/meilisearch',
			'lang_set' => 'logs',
		);

		$event['lang_set_ext'] = $lang_set_ext;
	}

	/**
	 * @return void
	 */
	public function assign_banner()
	{
		$this->template->assign_vars(array(
			'S_MEILISEARCH_BANNER_INLINE'	=> false,
			'S_MEILISEARCH_BANNER_HEADER'	=> false,
			'S_MEILISEARCH_HILIT_CSS'		=> false,
		));

		if (!$this->is_search_page())
		{
			return;
		}

		// Independent of the banner: the highlight restyling is useful even
		// with the notice switched off, since phpBB highlights the typed words
		// on its own.
		$this->template->assign_var(
			'S_MEILISEARCH_HILIT_CSS',
			(bool) $this->config['meilisearch_hilit_style'] && $this->backend_is_active()
		);

		$active = strpos((string) $this->config['search_type'], 'meilisearch') !== false;

		if (!$active || !$this->config['meilisearch_banner_enable'])
		{
			return;
		}

		$this->language->add_lang('common', 'salvocortesiano/meilisearch');

		$inline = $this->style_supports_inline();

		$this->template->assign_vars(array(
			'S_MEILISEARCH_BANNER_INLINE'	=> $inline,
			'S_MEILISEARCH_BANNER_HEADER'	=> !$inline,
			'MEILISEARCH_BANNER_TEXT'		=> $this->language->lang('MEILISEARCH_BANNER_TEXT'),
			'MEILISEARCH_BANNER_VERSION'	=> $this->get_version(),
			'MEILISEARCH_BANNER_VERSION_TITLE' => $this->language->lang('MEILISEARCH_BANNER_VERSION_TITLE', $this->get_version()),
		));
	}

	/**
	 * Add the words Meilisearch actually matched to phpBB's highlight list.
	 *
	 * phpBB highlights the words the user typed. Meilisearch also returns posts
	 * that matched through a typo, a synonym or a different inflection, and none
	 * of those appear in $hilit - so the result shows up with nothing marked and
	 * no visible reason why. This asks Meilisearch which words it matched in the
	 * posts on this page and appends them.
	 *
	 * One extra request per results page, filtered to the ten or so post ids
	 * being rendered. Off by default, because on a remote instance that request
	 * costs a round trip on a page the user is waiting for.
	 *
	 * @param \phpbb\event\data $event
	 * @return void
	 */
	public function extend_highlight($event)
	{
		if (!$this->config['meilisearch_highlight'] || !$this->backend_is_active())
		{
			return;
		}

		$query = trim($this->request->variable('keywords', '', true));

		if ($query === '' || empty($event['rowset']) || !is_array($event['rowset']))
		{
			return;
		}

		$post_ids = array();

		foreach ($event['rowset'] as $row)
		{
			if (!empty($row['post_id']))
			{
				$post_ids[] = (int) $row['post_id'];
			}
		}

		if (empty($post_ids))
		{
			return;
		}

		$highlighter = new \salvocortesiano\meilisearch\meili\highlighter();

		$response = $this->indexer->get_client()->search(
			$this->indexer->get_index_uid(),
			$highlighter->build_probe($query, $post_ids)
		);

		if ($response === false)
		{
			// Highlighting is decoration: a failure here must not disturb a
			// results page that is otherwise perfectly good.
			return;
		}

		$terms = $highlighter->extract_terms($response);

		if (!empty($terms))
		{
			$event['hilit'] = $highlighter->merge($event['hilit'], $terms);
		}
	}

	/**
	 * @return bool
	 */
	protected function backend_is_active()
	{
		return strpos((string) $this->config['search_type'], 'meilisearch') !== false;
	}

	/**
	 * Extension version, read from composer.json.
	 *
	 * Taken from the manifest rather than held in a constant so the number shown
	 * to visitors cannot drift from what was actually shipped. Read once per
	 * request; the banner renders on two pages at most.
	 *
	 * @return string Version, or an empty string when it cannot be determined
	 */
	protected function get_version()
	{
		if ($this->version !== null)
		{
			return $this->version;
		}

		$this->version = '';

		$path = $this->root_path . 'ext/salvocortesiano/meilisearch/composer.json';

		if (file_exists($path))
		{
			$json = @json_decode((string) @file_get_contents($path), true);

			if (!empty($json['version']))
			{
				$this->version = (string) $json['version'];
			}
		}

		return $this->version;
	}

	/**
	 * Is the current request the search form or the search results?
	 *
	 * Both are served by search.php, so one check covers the pair. Falls back to
	 * the script name because $user->page is not populated in every context.
	 *
	 * @return bool
	 */
	protected function is_search_page()
	{
		if (!empty($this->user->page['page_name']))
		{
			return strpos($this->user->page['page_name'], 'search.') === 0;
		}

		$script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';

		return strpos($script, 'search.') === 0;
	}

	/**
	 * Does the active style provide both search template events?
	 *
	 * @return bool
	 */
	protected function style_supports_inline()
	{
		if ($this->supports_inline !== null)
		{
			return $this->supports_inline;
		}

		$this->supports_inline = true;

		foreach (self::REQUIRED_EVENTS as $file => $event)
		{
			$path = $this->locate_template($file);

			if ($path === false)
			{
				// Template not found anywhere in the chain: play it safe.
				$this->supports_inline = false;
				break;
			}

			$contents = @file_get_contents($path);

			if ($contents === false || strpos($contents, $event) === false)
			{
				$this->supports_inline = false;
				break;
			}
		}

		return $this->supports_inline;
	}

	/**
	 * Resolve a template file across the style inheritance chain.
	 *
	 * phpBB looks in the active style first, then walks style_parent_tree from
	 * the nearest ancestor outwards; we do the same so the answer matches what
	 * the template engine will actually render.
	 *
	 * @param string $file Template file name
	 * @return string|false Absolute path, or false when not found
	 */
	protected function locate_template($file)
	{
		$candidates = array();

		if (!empty($this->user->style['style_path']))
		{
			$candidates[] = $this->user->style['style_path'];
		}

		if (!empty($this->user->style['style_parent_tree']))
		{
			foreach (array_reverse(explode('/', $this->user->style['style_parent_tree'])) as $parent)
			{
				if ($parent !== '')
				{
					$candidates[] = $parent;
				}
			}
		}

		$candidates[] = 'prosilver';

		foreach (array_unique($candidates) as $style)
		{
			$path = $this->root_path . 'styles/' . $style . '/template/' . $file;

			if (file_exists($path))
			{
				return $path;
			}
		}

		return false;
	}
}
