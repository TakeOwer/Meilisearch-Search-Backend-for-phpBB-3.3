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
 * Thin cURL wrapper around the Meilisearch HTTP API.
 *
 * Design notes
 * ------------
 * - No Composer dependency. The official meilisearch-php SDK would pull in
 *   Guzzle/PSR-18, which is a nightmare to ship inside a phpBB extension because
 *   phpBB has its own vendor/ tree and version conflicts are common. The API
 *   surface we need is six endpoints, so we call them directly.
 * - Every method returns a normalised array and NEVER throws. Search must degrade
 *   gracefully: if Meilisearch is down, the board must keep working (posting,
 *   viewing) even though search returns nothing. Callers check ->last_error().
 * - Meilisearch is asynchronous for writes: POST /documents returns a taskUid
 *   immediately and indexes in the background. We do not block on task completion
 *   during normal posting; we only poll in the ACP "test connection" tool.
 */
class client
{
	/** @var string Base URL without trailing slash, e.g. http://127.0.0.1:7700 */
	protected $url;

	/** @var string Master key or an API key with the required actions */
	protected $api_key;

	/** @var int Request timeout in seconds */
	protected $timeout;

	/** @var string Last transport or API error, empty when the last call succeeded */
	protected $error = '';

	/** @var int HTTP status of the last response, 0 on transport failure */
	protected $status = 0;

	/** @var float Wall-clock duration of the last request, in milliseconds */
	protected $last_duration_ms = 0.0;

	/**
	 * Constructor.
	 *
	 * @param \phpbb\config\config $config phpBB config object
	 */
	public function __construct(\phpbb\config\config $config)
	{
		$this->url     = rtrim(trim((string) $config['meilisearch_url']), '/');
		$this->api_key = (string) $config['meilisearch_api_key'];
		$this->timeout = max(1, (int) $config['meilisearch_timeout']);
	}

	/**
	 * Allow overriding the connection at runtime (used by the ACP test tool so an
	 * admin can validate credentials before saving them).
	 *
	 * @param string $url
	 * @param string $api_key
	 * @param int    $timeout
	 * @return void
	 */
	public function override_connection($url, $api_key, $timeout = 0)
	{
		$this->url     = rtrim(trim((string) $url), '/');
		$this->api_key = (string) $api_key;

		if ($timeout > 0)
		{
			$this->timeout = max(1, (int) $timeout);
		}
	}

	/**
	 * @return string Last error message ('' when the last call succeeded)
	 */
	public function last_error()
	{
		return $this->error;
	}

	/**
	 * @return int HTTP status code of the last response
	 */
	public function last_status()
	{
		return $this->status;
	}

	/**
	 * Round-trip time of the most recent request.
	 *
	 * Measured around curl_exec only, so it reflects network plus Meilisearch
	 * processing and excludes our own JSON handling.
	 *
	 * @return float Milliseconds
	 */
	public function last_duration_ms()
	{
		return $this->last_duration_ms;
	}

	/**
	 * @return bool True when a base URL has been configured
	 */
	public function is_configured()
	{
		return $this->url !== '';
	}

	/**
	 * @return string Configured base URL
	 */
	public function get_url()
	{
		return $this->url;
	}

	/* ---------------------------------------------------------------------
	 * Low level transport
	 * ------------------------------------------------------------------ */

	/**
	 * Perform an HTTP request against the Meilisearch API.
	 *
	 * @param string     $method HTTP verb
	 * @param string     $path   Path beginning with a slash, e.g. /indexes/foo
	 * @param array|null $body   Payload to be JSON encoded, or null for no body
	 * @return array|false Decoded response body on success (2xx), false on failure
	 */
	public function request($method, $path, $body = null)
	{
		$this->error            = '';
		$this->status           = 0;
		$this->last_duration_ms = 0.0;

		$started = microtime(true);

		if (!$this->is_configured())
		{
			$this->error = 'Meilisearch URL is not configured.';
			return false;
		}

		if (!function_exists('curl_init'))
		{
			$this->error = 'The PHP cURL extension is not available.';
			return false;
		}

		$headers = array(
			'Content-Type: application/json',
			'Accept: application/json',
		);

		if ($this->api_key !== '')
		{
			$headers[] = 'Authorization: Bearer ' . $this->api_key;
		}

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $this->url . $path);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $this->timeout));
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_USERAGENT, 'phpBB-meilisearch-ext/1.0');

		if ($body !== null)
		{
			$encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

			if ($encoded === false)
			{
				curl_close($ch);
				$this->error = 'Failed to JSON encode the request payload: ' . json_last_error_msg();
				return false;
			}

			curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
		}

		$raw = curl_exec($ch);

		$this->last_duration_ms = round((microtime(true) - $started) * 1000, 1);

		if ($raw === false)
		{
			$this->error = 'cURL error: ' . curl_error($ch);
			curl_close($ch);
			return false;
		}

		$this->status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$decoded = ($raw === '') ? array() : json_decode($raw, true);

		if ($decoded === null && $raw !== '')
		{
			$this->error = 'Meilisearch returned a non-JSON response (HTTP ' . $this->status . '): ' . substr($raw, 0, 200);
			return false;
		}

		if ($this->status < 200 || $this->status >= 300)
		{
			$message = isset($decoded['message']) ? $decoded['message'] : 'HTTP ' . $this->status;
			$code    = isset($decoded['code']) ? ' [' . $decoded['code'] . ']' : '';

			$this->error = 'Meilisearch API error' . $code . ': ' . $message;
			return false;
		}

		return is_array($decoded) ? $decoded : array();
	}

	/* ---------------------------------------------------------------------
	 * Instance level endpoints
	 * ------------------------------------------------------------------ */

	/**
	 * @return array|false ['status' => 'available'] on success
	 */
	public function health()
	{
		return $this->request('GET', '/health');
	}

	/**
	 * @return array|false ['pkgVersion' => '1.x.y', ...]
	 */
	public function version()
	{
		return $this->request('GET', '/version');
	}

	/**
	 * Poll a task until it leaves the enqueued/processing state.
	 *
	 * Only used by the ACP diagnostics page. Never call this on the posting path.
	 *
	 * @param int $task_uid
	 * @param int $max_wait_seconds
	 * @return array|false Final task object
	 */
	public function wait_for_task($task_uid, $max_wait_seconds = 10)
	{
		$deadline = time() + max(1, (int) $max_wait_seconds);

		do
		{
			$task = $this->request('GET', '/tasks/' . (int) $task_uid);

			if ($task === false)
			{
				return false;
			}

			$status = isset($task['status']) ? $task['status'] : '';

			if ($status !== 'enqueued' && $status !== 'processing')
			{
				return $task;
			}

			usleep(250000);
		}
		while (time() < $deadline);

		$this->error = 'Timed out waiting for Meilisearch task ' . (int) $task_uid . '.';
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Index level endpoints
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $index_uid
	 * @return array|false Index object, false when it does not exist
	 */
	public function get_index($index_uid)
	{
		return $this->request('GET', '/indexes/' . rawurlencode($index_uid));
	}

	/**
	 * Create the index if it is missing. Succeeds silently when it already exists.
	 *
	 * @param string $index_uid
	 * @param string $primary_key
	 * @return bool
	 */
	public function ensure_index($index_uid, $primary_key = 'post_id')
	{
		if ($this->get_index($index_uid) !== false)
		{
			$this->error = '';
			return true;
		}

		// 404 means "not there yet", anything else is a real problem worth surfacing.
		if ($this->status !== 404)
		{
			return false;
		}

		$result = $this->request('POST', '/indexes', array(
			'uid'        => $index_uid,
			'primaryKey' => $primary_key,
		));

		return $result !== false;
	}

	/**
	 * @param string $index_uid
	 * @param array  $settings Meilisearch settings payload
	 * @return array|false Task object
	 */
	public function update_settings($index_uid, array $settings)
	{
		return $this->request('PATCH', '/indexes/' . rawurlencode($index_uid) . '/settings', $settings);
	}

	/**
	 * @param string $index_uid
	 * @return array|false ['numberOfDocuments' => int, 'isIndexing' => bool, ...]
	 */
	public function index_stats($index_uid)
	{
		return $this->request('GET', '/indexes/' . rawurlencode($index_uid) . '/stats');
	}

	/**
	 * Add or replace documents. Meilisearch upserts on the primary key.
	 *
	 * @param string $index_uid
	 * @param array  $documents List of associative arrays
	 * @return array|false Task object
	 */
	public function add_documents($index_uid, array $documents)
	{
		if (empty($documents))
		{
			return array();
		}

		return $this->request('POST', '/indexes/' . rawurlencode($index_uid) . '/documents', array_values($documents));
	}

	/**
	 * @param string $index_uid
	 * @param array  $ids Primary keys to delete
	 * @return array|false Task object
	 */
	public function delete_documents($index_uid, array $ids)
	{
		if (empty($ids))
		{
			return array();
		}

		return $this->request('POST', '/indexes/' . rawurlencode($index_uid) . '/documents/delete-batch', array_values(array_map('intval', $ids)));
	}

	/**
	 * @param string $index_uid
	 * @return array|false Task object
	 */
	public function delete_all_documents($index_uid)
	{
		return $this->request('DELETE', '/indexes/' . rawurlencode($index_uid) . '/documents');
	}

	/**
	 * Delete every document matching a filter expression.
	 *
	 * Requires Meilisearch 1.2 or newer. Used to evict forums that have just been
	 * added to the exclusion list without walking the whole posts table.
	 *
	 * @param string $index_uid
	 * @param string $filter Meilisearch filter expression
	 * @return array|false Task object
	 */
	public function delete_documents_by_filter($index_uid, $filter)
	{
		if (trim((string) $filter) === '')
		{
			return array();
		}

		return $this->request('POST', '/indexes/' . rawurlencode($index_uid) . '/documents/delete', array(
			'filter' => $filter,
		));
	}

	/**
	 * @param string $index_uid
	 * @return array|false Current index settings
	 */
	public function get_settings($index_uid)
	{
		return $this->request('GET', '/indexes/' . rawurlencode($index_uid) . '/settings');
	}

	/**
	 * Instance-wide statistics: database size, per-index document counts.
	 *
	 * Global endpoint. An index-scoped key cannot call it, so a failure here is
	 * expected rather than alarming.
	 *
	 * @return array|false
	 */
	public function global_stats()
	{
		return $this->request('GET', '/stats');
	}

	/**
	 * Recent tasks, newest first.
	 *
	 * The single most useful endpoint when something has gone wrong: a failed
	 * indexing task records its own error message, which never reaches the phpBB
	 * log because the write is asynchronous.
	 *
	 * @param int    $limit
	 * @param string $statuses Comma separated: enqueued, processing, succeeded, failed, canceled
	 * @param string $index_uid Restrict to one index, or '' for all
	 * @return array|false
	 */
	public function tasks($limit = 10, $statuses = '', $index_uid = '')
	{
		$query = array('limit' => (int) $limit);

		if ($statuses !== '')
		{
			$query['statuses'] = $statuses;
		}

		if ($index_uid !== '')
		{
			$query['indexUids'] = $index_uid;
		}

		return $this->request('GET', '/tasks?' . http_build_query($query));
	}

	/**
	 * Raw TCP connect timing, bypassing the HTTP layer.
	 *
	 * Separates "the network is slow" from "Meilisearch is slow": if the socket
	 * opens quickly but /health is slow, the daemon is the bottleneck.
	 *
	 * @return array ['ok' => bool, 'ms' => float, 'error' => string, 'host' => string, 'port' => int, 'ip' => string]
	 */
	public function probe_socket()
	{
		$parts = parse_url($this->url);

		$host = isset($parts['host']) ? $parts['host'] : '';
		$scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
		$port = isset($parts['port']) ? (int) $parts['port'] : (($scheme === 'https') ? 443 : 80);

		$result = array(
			'ok'    => false,
			'ms'    => 0.0,
			'error' => '',
			'host'  => $host,
			'port'  => $port,
			'ip'    => '',
		);

		if ($host === '')
		{
			$result['error'] = 'Could not parse a host out of the configured URL.';
			return $result;
		}

		$ip = @gethostbyname($host);
		$result['ip'] = ($ip !== $host || filter_var($host, FILTER_VALIDATE_IP)) ? $ip : '';

		$errno = 0;
		$errstr = '';
		$started = microtime(true);

		$socket = @fsockopen(($scheme === 'https' ? 'tls://' : '') . $host, $port, $errno, $errstr, min(5, $this->timeout));

		$result['ms'] = round((microtime(true) - $started) * 1000, 1);

		if ($socket === false)
		{
			$result['error'] = ($errstr !== '') ? $errstr . ' (' . (int) $errno . ')' : 'Connection refused.';
			return $result;
		}

		fclose($socket);
		$result['ok'] = true;

		return $result;
	}

	/**
	 * Inspect the TLS certificate of an https endpoint.
	 *
	 * A certificate that expired is a textbook cause of "search suddenly stopped
	 * working" on a setup that had been fine for 90 days.
	 *
	 * @return array|false ['issuer' => string, 'valid_to' => int, 'days_left' => int] or false when not applicable
	 */
	public function probe_certificate()
	{
		$parts = parse_url($this->url);

		if (!isset($parts['scheme']) || $parts['scheme'] !== 'https' || empty($parts['host']))
		{
			return false;
		}

		$port = isset($parts['port']) ? (int) $parts['port'] : 443;

		$context = stream_context_create(array('ssl' => array(
			'capture_peer_cert' => true,
			'verify_peer'       => false,
			'verify_peer_name'  => false,
		)));

		$socket = @stream_socket_client(
			'ssl://' . $parts['host'] . ':' . $port,
			$errno, $errstr, min(5, $this->timeout),
			STREAM_CLIENT_CONNECT, $context
		);

		if ($socket === false)
		{
			return false;
		}

		$params = stream_context_get_params($socket);
		fclose($socket);

		if (empty($params['options']['ssl']['peer_certificate']) || !function_exists('openssl_x509_parse'))
		{
			return false;
		}

		$cert = @openssl_x509_parse($params['options']['ssl']['peer_certificate']);

		if (!$cert || empty($cert['validTo_time_t']))
		{
			return false;
		}

		return array(
			'issuer'    => isset($cert['issuer']['O']) ? $cert['issuer']['O'] : (isset($cert['issuer']['CN']) ? $cert['issuer']['CN'] : '?'),
			'valid_to'  => (int) $cert['validTo_time_t'],
			'days_left' => (int) floor(($cert['validTo_time_t'] - time()) / 86400),
		);
	}

	/**
	 * Create an API key.
	 *
	 * Requires the master key, which is why callers override_connection() with it
	 * for this single call and restore the normal credentials straight afterwards.
	 * The master key must never be persisted to phpbb_config.
	 *
	 * @param array $payload Key definition (name, actions, indexes, expiresAt)
	 * @return array|false Key object, including the 'key' value to store
	 */
	public function create_key(array $payload)
	{
		return $this->request('POST', '/keys', $payload);
	}

	/**
	 * Run a search.
	 *
	 * @param string $index_uid
	 * @param array  $payload Meilisearch search payload (q, filter, limit, ...)
	 * @return array|false ['hits' => [...], 'estimatedTotalHits' => int]
	 */
	public function search($index_uid, array $payload)
	{
		return $this->request('POST', '/indexes/' . rawurlencode($index_uid) . '/search', $payload);
	}
}
