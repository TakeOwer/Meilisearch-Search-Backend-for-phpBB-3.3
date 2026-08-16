<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\meilisearch;

/**
 * Extension bootstrap.
 *
 * The only hard requirements are PHP 7.4 (typed properties / arrow functions are
 * NOT used, but 7.4 is the phpBB 3.3 floor for a sane json_encode) and the cURL
 * extension, which is how we talk to Meilisearch. We deliberately do NOT require
 * a reachable Meilisearch instance at enable time: an admin may legitimately want
 * to install the extension first and configure the connection afterwards. The
 * connection is validated in search\meilisearch_backend::init(), which phpBB calls
 * when the backend is actually selected in the ACP.
 */
class ext extends \phpbb\extension\base
{
	/**
	 * {@inheritdoc}
	 */
	public function is_enableable()
	{
		$errors = array();

		if (version_compare(PHP_VERSION, '7.4.0', '<'))
		{
			$errors[] = 'Meilisearch Search Backend requires PHP 7.4.0 or newer (running ' . PHP_VERSION . ').';
		}

		if (!extension_loaded('curl') || !function_exists('curl_init'))
		{
			$errors[] = 'Meilisearch Search Backend requires the PHP cURL extension to be installed and enabled.';
		}

		if (!function_exists('json_encode') || !function_exists('json_decode'))
		{
			$errors[] = 'Meilisearch Search Backend requires the PHP JSON extension.';
		}

		return empty($errors) ? true : $errors;
	}
}
