<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\meilisearch\cron\task;

/**
 * Retries index operations that could not be delivered to Meilisearch.
 *
 * The queue only ever fills when Meilisearch was unreachable at posting time.
 * On a healthy board this task finds nothing to do and costs one COUNT query.
 */
class flush_queue extends \phpbb\cron\task\base
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \salvocortesiano\meilisearch\meili\indexer */
	protected $indexer;

	/**
	 * @param \phpbb\config\config             $config
	 * @param \salvocortesiano\meilisearch\meili\indexer  $indexer
	 */
	public function __construct(\phpbb\config\config $config, \salvocortesiano\meilisearch\meili\indexer $indexer)
	{
		$this->config  = $config;
		$this->indexer = $indexer;
	}

	/**
	 * Only meaningful while Meilisearch is the active backend and the retry queue
	 * is switched on.
	 *
	 * @return bool
	 */
	public function is_runnable()
	{
		return (bool) $this->config['meilisearch_queue_enable']
			&& strpos((string) $this->config['search_type'], 'meilisearch') !== false;
	}

	/**
	 * @return bool
	 */
	public function should_run()
	{
		$interval = max(60, (int) $this->config['meilisearch_queue_gc']);

		return ($this->config['meilisearch_queue_last_gc'] + $interval) < time();
	}

	/**
	 * @return void
	 */
	public function run()
	{
		$batch = max(50, (int) $this->config['meilisearch_batch_size'] * 4);

		$this->indexer->flush_queue($batch);

		$this->config->set('meilisearch_queue_last_gc', time(), false);
	}
}
