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

	/** @var bool|null Per-request cache of the placement decision */
	protected $supports_inline = null;

	/**
	 * @param \phpbb\config\config     $config
	 * @param \phpbb\template\template $template
	 * @param \phpbb\language\language $language
	 * @param \phpbb\user              $user
	 * @param string                   $root_path
	 */
	public function __construct(\phpbb\config\config $config, \phpbb\template\template $template, \phpbb\language\language $language, \phpbb\user $user, $root_path)
	{
		$this->config    = $config;
		$this->template  = $template;
		$this->language  = $language;
		$this->user      = $user;
		$this->root_path = $root_path;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			'core.page_header'	=> 'assign_banner',
		);
	}

	/**
	 * @return void
	 */
	public function assign_banner()
	{
		$this->template->assign_vars(array(
			'S_MEILISEARCH_BANNER_INLINE'	=> false,
			'S_MEILISEARCH_BANNER_HEADER'	=> false,
		));

		if (!$this->is_search_page())
		{
			return;
		}

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
		));
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
