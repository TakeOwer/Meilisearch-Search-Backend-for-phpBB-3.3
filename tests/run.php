<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * Run from the extension root:   php tests/run.php
 *
 * No phpBB installation is required. Two kinds of check live here:
 *
 *   1. query_builder, which is a pure function and can be exercised directly.
 *      The filter string it produces is the part most likely to be got wrong.
 *
 *   2. static consistency of the package: language keys used but never defined,
 *      strings carrying sprintf placeholders rendered as {L_...} in a template,
 *      template variables no one assigns, English and Italian drifting apart,
 *      class names not matching their path. Every one of those has actually
 *      shipped as a bug at some point, and every one is mechanically detectable.
 */

if (PHP_SAPI !== 'cli')
{
	exit("Run this from the command line.\n");
}

define('IN_PHPBB', true);

$root = dirname(__DIR__);

require $root . '/meili/query_builder.php';
require $root . '/meili/synonyms.php';
require $root . '/meili/highlighter.php';

use salvocortesiano\meilisearch\meili\query_builder;
use salvocortesiano\meilisearch\meili\synonyms;
use salvocortesiano\meilisearch\meili\highlighter;

/**
 * Bare assertion harness: no dependencies, readable output, non-zero exit on
 * failure so it can sit in a CI step.
 */
class tester
{
	public $passed = 0;
	public $failed = array();

	public function same($expected, $actual, $what)
	{
		if ($expected === $actual)
		{
			$this->passed++;
			echo "  OK   $what\n";
			return;
		}

		$this->failed[] = $what . ' (atteso ' . $this->show($expected) . ', ottenuto ' . $this->show($actual) . ')';
		echo "  FAIL $what\n";
	}

	public function true_($actual, $what)
	{
		$this->same(true, (bool) $actual, $what);
	}

	public function empty_(array $actual, $what)
	{
		if (empty($actual))
		{
			$this->passed++;
			echo "  OK   $what\n";
			return;
		}

		$this->failed[] = $what . ' -> ' . implode(', ', array_map('strval', $actual));
		echo "  FAIL $what\n";
		foreach ($actual as $line)
		{
			echo "         $line\n";
		}
	}

	protected function show($v)
	{
		if (is_array($v))
		{
			return 'array(' . implode(', ', array_map(array($this, 'show'), $v)) . ')';
		}

		return is_bool($v) ? ($v ? 'true' : 'false') : var_export($v, true);
	}
}

$t = new tester();

/* =====================================================================
 * query_builder
 * ================================================================== */

echo "\n== query_builder ==\n";

$b = new query_builder();

$p = $b->build('carburatore', 'all');
$t->same('carburatore', $p['q'], 'la query finisce nel payload');
$t->same(array('post_subject', 'post_text'), $p['attributesToSearchOn'], 'per impostazione predefinita cerca in titolo e testo');
$t->same(array('post_id'), $p['attributesToRetrieve'], 'restituisce solo i post id');
$t->true_(!isset($p['filter']), 'senza opzioni non produce alcun filtro');

$t->same(array('post_subject'), $b->build('x', 'titleonly')['attributesToSearchOn'], 'titleonly cerca solo nel titolo');
$t->same(array('post_text'), $b->build('x', 'msgonly')['attributesToSearchOn'], 'msgonly cerca solo nel testo');
$t->true_(strpos($b->build('x', 'titleonly')['filter'], 'is_first_post = 1') !== false, 'titleonly si limita al primo messaggio');
$t->true_(strpos($b->build('x', 'firstpost')['filter'], 'is_first_post = 1') !== false, 'firstpost si limita al primo messaggio');

// --- il filtro che protegge i forum non leggibili ---
$p = $b->build('x', 'all', array('ex_fid_ary' => array(3, 7, 7, '9')));
$t->same('forum_id NOT IN [3, 7, 9]', $p['filter'], 'i forum esclusi diventano un NOT IN senza duplicati');

// (int) '5; DROP TABLE' vale 5: il cast tronca al primo carattere non numerico,
// quindi nel filtro finisce un intero e nient'altro. E la ragione per cui i
// valori sono forzati a intero invece che virgolettati.
$p = $b->build('x', 'all', array('ex_fid_ary' => array('5; DROP TABLE', 8)));
$t->same('forum_id NOT IN [5, 8]', $p['filter'], 'un valore malevolo nei forum esclusi viene troncato a intero');
$p = $b->build('x', 'all', array('ex_fid_ary' => array('abc', 8)));
$t->same('forum_id NOT IN [0, 8]', $p['filter'], 'un valore non numerico nei forum esclusi diventa 0');

$p = $b->build('x', 'all', array('author_ary' => array('2x', 4)));
$t->same('poster_id IN [2, 4]', $p['filter'], 'gli autori sono forzati a interi');

$p = $b->build('x', 'all', array('topic_id' => '42abc'));
$t->same('topic_id = 42', $p['filter'], 'il topic id e forzato a intero');

// --- autore ospite: il filtro deve restare all'SQL ---
$p = $b->build('x', 'all', array('author_ary' => array(4), 'author_name' => true));
$t->true_(!isset($p['filter']), 'con un nome ospite l\'autore non viene passato a Meilisearch');

// --- eta dei messaggi ---
$p = $b->build('x', 'all', array('sort_days' => 7, 'now' => 1000000));
$t->same('post_time >= ' . (1000000 - 7 * 86400), $p['filter'], 'sort_days diventa una soglia su post_time');

// --- il nuovo filtro di visibilita ---
$p = $b->build('x', 'all', array('approved_only' => true));
$t->same('post_visibility = 1', $p['filter'], 'chi non modera nulla vede solo i messaggi approvati');

$p = $b->build('x', 'all', array('approved_only' => false));
$t->true_(!isset($p['filter']), 'chi modera da qualche parte non riceve il filtro di visibilita');

// --- combinazione: l'ordine e l'AND devono restare stabili ---
$p = $b->build('vela', 'all', array(
	'ex_fid_ary'    => array(2),
	'topic_id'      => 9,
	'author_ary'    => array(11),
	'sort_days'     => 1,
	'approved_only' => true,
	'now'           => 2000000,
));
$t->same(
	'forum_id NOT IN [2] AND topic_id = 9 AND poster_id IN [11] AND post_time >= ' . (2000000 - 86400) . ' AND post_visibility = 1',
	$p['filter'],
	'i filtri si combinano con AND nell\'ordine atteso'
);

// --- limiti e lingue ---
$t->same(1000, $b->build('x', 'all')['limit'], 'il limite predefinito e 1000');
$t->same(100, $b->build('x', 'all', array('limit' => 5))['limit'], 'un limite troppo basso viene riportato a 100');
$t->same(4000, $b->build('x', 'all', array('limit' => 4000))['limit'], 'un limite piu alto viene rispettato');
$t->same(array('ita', 'eng'), $b->build('x', 'all', array('locales' => array('ita', 'eng')))['locales'], 'le lingue finiscono nel payload');
$t->true_(!isset($b->build('x', 'all')['locales']), 'senza lingue la chiave non compare');

/* =====================================================================
 * synonyms
 * ================================================================== */

echo "\n== synonyms ==\n";

$syn = new synonyms();

$t->same(array(), $syn->parse(''), 'una lista vuota non produce sinonimi');
$t->same(array(), $syn->parse("# solo un commento\n"), 'i commenti vengono ignorati');
$t->same(array(), $syn->parse("unaparola\n"), 'un gruppo con un solo termine viene ignorato');

// Il punto che si sbaglia piu' spesso: Meilisearch memorizza i sinonimi in una
// sola direzione, quindi ogni termine deve elencare tutti gli altri.
$m = $syn->parse('sottotitoli, sub');
$t->same(array('sub'), $m['sottotitoli'], 'il primo termine trova il secondo');
$t->same(array('sottotitoli'), $m['sub'], 'il secondo termine trova il primo');

$m = $syn->parse('a, b, c');
$t->same(array('b', 'c'), $m['a'], 'in un gruppo di tre, ogni termine elenca gli altri due');
$t->same(array('a', 'c'), $m['b'], 'anche per il termine centrale');
$t->same(array('a', 'b'), $m['c'], 'anche per l\'ultimo');

$m = $syn->parse('ITA, Italiano');
$t->same(array('italiano'), $m['ita'], 'i termini sono normalizzati in minuscolo');
$t->true_(!isset($m['ITA']), 'la variante maiuscola non crea una voce separata');

$m = $syn->parse('  1080p ,   full   hd  ');
$t->same(array('full hd'), $m['1080p'], 'spazi e spaziature multiple vengono normalizzati');

$m = $syn->parse("a, b\nb, c");
$t->same(array('a', 'c'), $m['b'], 'un termine che compare in due gruppi li unisce');

$m = $syn->parse('x, x, y');
$t->same(array('y'), $m['x'], 'i duplicati dentro un gruppo vengono rimossi');

$t->same(2, $syn->count_groups("a, b\n# nota\nc, d\nsolo"), 'i gruppi validi vengono contati correttamente');

// validate() deve segnalare le righe scartate, con il numero di riga
$problems = $syn->validate("a, b\nsolo\nc, d");
$t->same(1, count($problems), 'una sola riga problematica viene segnalata');
$t->same(2, $problems[0]['line'], 'il numero di riga e quello giusto');
$t->same('needs_two_terms', $problems[0]['reason'], 'il motivo e il gruppo di un solo termine');
$t->same(array(), $syn->validate("a, b\nc, d"), 'una lista pulita non produce segnalazioni');

// --- liste di partenza e fusione ---
$t->same('', $syn->read_starter('/percorso/inesistente/', 'it'), 'un file di partenza assente restituisce stringa vuota');

$starter_it = $syn->read_starter($root . '/data/', 'it');
$starter_en = $syn->read_starter($root . '/data/', 'en');
$t->true_($starter_it !== '', 'la lista italiana e presente nel pacchetto');
$t->true_($starter_en !== '', 'la lista inglese e presente nel pacchetto');
$t->true_($syn->count_groups($starter_it) > 20, 'la lista italiana contiene un numero utile di gruppi');
$t->true_($syn->count_groups($starter_en) > 20, 'la lista inglese contiene un numero utile di gruppi');
$t->same(array(), $syn->validate($starter_it), 'la lista italiana non contiene righe da scartare');
$t->same(array(), $syn->validate($starter_en), 'la lista inglese non contiene righe da scartare');

$t->same("a, b", $syn->append('', 'a, b'), 'aggiungere a una lista vuota da la lista nuova');
$t->true_(strpos($syn->append('x, y', 'a, b'), 'x, y') !== false, 'aggiungere conserva i gruppi esistenti');
$t->true_(strpos($syn->append('x, y', 'a, b'), 'a, b') !== false, 'aggiungere inserisce i gruppi nuovi');

// un gruppo gia presente non viene ripetuto, anche scritto in ordine diverso
$merged = $syn->append('sub, sottotitoli', 'sottotitoli, sub');
$t->same(1, $syn->count_groups($merged), 'un gruppo gia presente non viene duplicato');
$merged = $syn->append('a, b', 'B, A');
$t->same(1, $syn->count_groups($merged), 'il confronto ignora ordine e maiuscole');

$merged = $syn->append('a, b', "# nota\nc, d");
$t->true_(strpos($merged, '# nota') !== false, 'i commenti della lista di partenza vengono conservati');
$merged = $syn->append('a, b', "# commento orfano\n");
$t->same('a, b', $merged, 'un commento senza gruppi sotto non viene aggiunto');

// --- scansione della cartella: una lingua nuova deve comparire da sola ---
$starters = $syn->list_starters($root . '/data');
$codes = array();
foreach ($starters as $st) { $codes[] = $st['code']; }
sort($codes);
$t->same(array('en', 'it'), $codes, 'la scansione trova le due liste fornite');

$names = array();
foreach ($starters as $st) { $names[$st['code']] = $st['name']; }
$t->same('Italiano', $names['it'], 'il nome leggibile viene letto dall\'intestazione del file');
$t->same('English', $names['en'], 'anche per la lista inglese');

$t->true_($syn->starter_exists($root . '/data', 'it'), 'una lista presente viene riconosciuta');
$t->true_(!$syn->starter_exists($root . '/data', 'zz'), 'una lista assente non viene riconosciuta');
$t->same(array(), $syn->list_starters('/cartella/inesistente'), 'una cartella assente non produce liste');

// Un file inventato deve comparire senza toccare il codice: e il punto di
// tutta questa scansione.
$tmp = sys_get_temp_dir() . '/meili_syn_' . getmypid();
@mkdir($tmp);
file_put_contents($tmp . '/synonyms_fr.txt', "# name: Francais\nvoiture, auto\nfilm, pellicule\n");
file_put_contents($tmp . '/synonyms_xx.txt', "sans, entete\n");
file_put_contents($tmp . '/note.txt', "non deve essere raccolto\n");

$found = $syn->list_starters($tmp);
$codes = array();
foreach ($found as $st) { $codes[$st['code']] = $st['name']; }
$t->same(2, count($found), 'solo i file synonyms_*.txt vengono raccolti');
$t->same('Francais', $codes['fr'], 'una lingua mai vista prende il nome dalla sua intestazione');
$t->same('XX', $codes['xx'], 'senza intestazione il nome ripiega sul codice');
$t->same(2, $syn->count_groups($syn->read_starter($tmp, 'fr')), 'la lista inventata e leggibile');

// un codice malevolo non deve poter raggiungere il filesystem
$t->same('', $syn->clean_code('../../etc/passwd'), 'un codice con caratteri di percorso viene svuotato');
$t->true_(!$syn->starter_exists($tmp, '../../etc/passwd'), 'un codice malevolo non risulta esistente');

@unlink($tmp . '/synonyms_fr.txt');
@unlink($tmp . '/synonyms_xx.txt');
@unlink($tmp . '/note.txt');
@rmdir($tmp);

/* =====================================================================
 * highlighter
 * ================================================================== */

echo "\n== highlighter ==\n";

$h = new highlighter();

$probe = $h->build_probe('carburatre', array(5, 5, '9'));
$t->same('post_id IN [5, 9]', $probe['filter'], 'la sonda si limita ai post della pagina, senza duplicati');
$t->same(2, $probe['limit'], 'il limite corrisponde al numero di post');
$t->same(array('post_subject', 'post_text'), $probe['attributesToHighlight'], 'chiede l\'evidenziazione su titolo e testo');

$t->same(array(), $h->extract_terms(false), 'una risposta fallita non produce termini');
$t->same(array(), $h->extract_terms(array('hits' => array())), 'nessun risultato, nessun termine');

$pre = highlighter::PRE_TAG;
$post = highlighter::POST_TAG;

$response = array('hits' => array(
	array('_formatted' => array(
		'post_subject' => 'Revisione ' . $pre . 'carburatore' . $post,
		'post_text'    => 'il ' . $pre . 'Carburatore' . $post . ' e i ' . $pre . 'getti' . $post,
	)),
));
$terms = $h->extract_terms($response);
sort($terms);
$t->same(array('carburatore', 'getti'), $terms, 'estrae i termini evidenziati, normalizzati e senza duplicati');

$response = array('hits' => array(array('_formatted' => array('post_text' => 'a ' . $pre . 'e' . $post . ' b'))));
$t->same(array(), $h->extract_terms($response), 'i termini di un solo carattere vengono scartati');

$response = array('hits' => array(array('_formatted' => array('post_text' => $pre . 'full hd' . $post))));
$t->same(array(), $h->extract_terms($response), 'una corrispondenza su piu parole viene scartata');

// merge() non deve rompere la regex che phpBB costruisce
$t->same('carburatore', $h->merge('', array('carburatore')), 'su una lista vuota aggiunge il termine');
$t->same('gia|nuovo', $h->merge('gia', array('nuovo')), 'aggiunge in coda a quelli esistenti');
$t->same('gia', $h->merge('gia', array('GIA')), 'non duplica un termine gia presente, a parita di maiuscole');
$t->same('a', $h->merge('a', array('x')), 'i termini troppo corti non vengono aggiunti');

$merged = $h->merge('', array('c++', 'r-7'));
$t->true_(strpos($merged, '\\+') !== false, 'i caratteri speciali vengono protetti per la regex');
$t->true_(@preg_match('#(' . $merged . ')#iu', 'r-7') === 1, 'la stringa prodotta resta una regex valida');

$many = array();
for ($i = 0; $i < 40; $i++) { $many[] = 'parola' . $i; }
$t->same(highlighter::MAX_TERMS, count(explode('|', $h->merge('', $many))), 'il numero di termini aggiunti e limitato');

/* =====================================================================
 * Coerenza statica del pacchetto
 * ================================================================== */

echo "\n== coerenza del pacchetto ==\n";

/**
 * @param string $dir
 * @param string $ext
 * @return array
 */
function collect($dir, $ext)
{
	if (!is_dir($dir))
	{
		return array();
	}

	$out = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

	foreach ($it as $file)
	{
		if ($file->isFile() && strtolower($file->getExtension()) === $ext)
		{
			$out[] = $file->getPathname();
		}
	}

	sort($out);

	return $out;
}

/**
 * @param string $file
 * @return array
 */
function lang_keys($file)
{
	preg_match_all("/'([A-Z][A-Z0-9_]+)'\s*=>/", (string) file_get_contents($file), $m);

	return $m[1];
}

$lang_en = collect($root . '/language/en', 'php');
$lang_it = collect($root . '/language/it', 'php');

$defined_en = array();
$defined_values = array();

foreach ($lang_en as $file)
{
	$content = (string) file_get_contents($file);

	foreach (lang_keys($file) as $k)
	{
		$defined_en[$k] = $file;
	}

	preg_match_all("/'([A-Z][A-Z0-9_]+)'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'/", $content, $m, PREG_SET_ORDER);

	foreach ($m as $pair)
	{
		$defined_values[$pair[1]] = $pair[2];
	}
}

$defined_it = array();

foreach ($lang_it as $file)
{
	foreach (lang_keys($file) as $k)
	{
		$defined_it[$k] = $file;
	}
}

// --- 1. chiavi usate ma mai definite ---
$php_files = array_filter(collect($root, 'php'), function ($f) use ($root) {
	return strpos($f, $root . '/language/') !== 0 && strpos($f, $root . '/tests/') !== 0;
});
$tpl_files = array_merge(collect($root . '/adm', 'html'), collect($root . '/styles', 'html'));

$used = array();
$patterns = array(
	"/\\\$this->lang\('([A-Z0-9_]+)'/",
	"/\\\$this->language->lang\('([A-Z0-9_]+)'/",
	"/\\\$user->lang\('([A-Z0-9_]+)'/",
	"/\\\$user->lang\['([A-Z0-9_]+)'\]/",
	"/->add\('[a-z]+',[^)]*?'([A-Z][A-Z0-9_]+)'/s",
	"/add_log\('[a-z]+',\s*'([A-Z][A-Z0-9_]+)'/",
	"/'title'\s*=>\s*'([A-Z][A-Z0-9_]+)'/",
);

foreach ($php_files as $file)
{
	$content = (string) file_get_contents($file);

	foreach ($patterns as $p)
	{
		preg_match_all($p, $content, $m);

		foreach ($m[1] as $k)
		{
			$used[$k] = basename($file);
		}
	}
}

$tpl_lang = array();
$tpl_vars = array();

foreach ($tpl_files as $file)
{
	$content = (string) file_get_contents($file);

	preg_match_all('/\{L[A]?_([A-Z0-9_]+)\}/', $content, $m);

	foreach ($m[1] as $k)
	{
		$used[$k] = basename($file);
		$tpl_lang[$k] = basename($file);
	}

	preg_match_all('/\{(S_[A-Z0-9_]+|U_[A-Z0-9_]+|MEILI[A-Z0-9_]*|HEALTH_[A-Z0-9_]+|NOTICES|ERRORS|PROBE_ERROR|EXCLUDED_COUNT|INCLUDED_COUNT)\}/', $content, $m);

	foreach ($m[1] as $v)
	{
		$tpl_vars[$v] = basename($file);
	}
}

// keys phpBB provides itself
$core_keys = array('YES', 'NO', 'COLON', 'WARNING', 'INFORMATION', 'SUBMIT', 'FORM_INVALID', 'ACP_CAT_DOT_MODS', 'LOG_SEARCH_INDEX_CREATED');

$missing = array();

foreach ($used as $k => $where)
{
	if (!isset($defined_en[$k]) && !in_array($k, $core_keys, true))
	{
		$missing[] = "$k (usata in $where)";
	}
}

$t->empty_($missing, 'ogni chiave di lingua usata e definita in inglese');

// --- 2. inglese e italiano allineati ---
$only_en = array_diff(array_keys($defined_en), array_keys($defined_it));
$only_it = array_diff(array_keys($defined_it), array_keys($defined_en));
$t->empty_(array_values($only_en), 'nessuna chiave presente solo in inglese');
$t->empty_(array_values($only_it), 'nessuna chiave presente solo in italiano');

// --- 3. segnaposto sprintf resi come {L_...} ---
// Una stringa con %1$d usata come {L_CHIAVE} stampa il segnaposto grezzo:
// va composta in PHP e passata come variabile di template.
$raw_placeholders = array();

foreach ($tpl_lang as $k => $where)
{
	if (isset($defined_values[$k]) && preg_match('/%\d?\$?[ds]/', $defined_values[$k]))
	{
		$raw_placeholders[] = "$k (in $where)";
	}
}

$t->empty_($raw_placeholders, 'nessuna stringa con segnaposto viene resa come {L_...}');

// --- 4. variabili di template assegnate da qualche parte ---
$assigners = '';

foreach ($php_files as $file)
{
	$assigners .= (string) file_get_contents($file);
}

$unassigned = array();

foreach ($tpl_vars as $v => $where)
{
	if ($v !== 'S_FORM_TOKEN' && strpos($assigners, "'" . $v . "'") === false)
	{
		$unassigned[] = "$v (in $where)";
	}
}

$t->empty_($unassigned, 'ogni variabile di template viene assegnata dal PHP');

// --- 5. namespace coerente con il percorso ---
$path_errors = array();

foreach ($php_files as $file)
{
	$content = (string) file_get_contents($file);

	if (!preg_match('/^namespace\s+([^;]+);/m', $content, $ns) || !preg_match('/^class\s+(\w+)/m', $content, $cl))
	{
		continue;
	}

	$expected_dir = str_replace('\\', '/', trim(str_replace('salvocortesiano\meilisearch', '', $ns[1]), '\\'));
	$actual_dir = trim(str_replace($root, '', dirname($file)), '/');

	if ($actual_dir !== $expected_dir || basename($file) !== $cl[1] . '.php')
	{
		$path_errors[] = basename($file) . " (namespace {$ns[1]}, classe {$cl[1]})";
	}
}

$t->empty_($path_errors, 'ogni classe sta nel percorso che il suo namespace richiede');

// --- 6. le stringhe di log sono caricate globalmente ---
// Il visualizzatore dei log e un modulo del core e non carica common.php:
// una chiave LOG_ definita li verrebbe mostrata come {LOG_QUALCOSA}.
$log_keys_outside = array();

foreach ($defined_en as $k => $file)
{
	if (strpos($k, 'LOG_') === 0 && basename($file) !== 'logs.php')
	{
		$log_keys_outside[] = "$k (in " . basename($file) . ')';
	}
}

$t->empty_($log_keys_outside, 'le chiavi LOG_ stanno in logs.php, caricato via core.user_setup');

$listener = (string) @file_get_contents($root . '/event/listener.php');
$t->true_(strpos($listener, 'core.user_setup') !== false, 'il listener si aggancia a core.user_setup');

// --- 7. le migration formano una catena ---
$migrations = collect($root . '/migrations', 'php');
$chain_errors = array();

foreach ($migrations as $file)
{
	$content = (string) file_get_contents($file);

	if (!preg_match('/function depends_on/', $content))
	{
		$chain_errors[] = basename($file) . ' non dichiara depends_on()';
	}
}

$t->empty_($chain_errors, 'ogni migration dichiara le proprie dipendenze');

// --- 8. i template sono sintatticamente bilanciati ---
// Un <!-- ENDIF --> orfano non e visibile leggendo il file: Twig se ne accorge
// solo quando qualcuno apre la pagina, e a quel punto e un errore fatale.
$tag_errors = array();

foreach ($tpl_files as $file)
{
	$content = (string) file_get_contents($file);

	$opens  = preg_match_all('/<!--\s*IF\s/', $content);
	$closes = preg_match_all('/<!--\s*ENDIF\s*-->/', $content);

	if ($opens !== $closes)
	{
		$tag_errors[] = basename($file) . ": IF $opens / ENDIF $closes";
	}

	preg_match_all('/<!--\s*BEGIN\s+(\w+)/', $content, $b);
	preg_match_all('/<!--\s*END\s+(\w+)/', $content, $e);

	$open_blocks = $b[1];
	$close_blocks = $e[1];
	sort($open_blocks);
	sort($close_blocks);

	if ($open_blocks !== $close_blocks)
	{
		$tag_errors[] = basename($file) . ': BEGIN/END non corrispondenti ('
			. implode(',', $open_blocks) . ' vs ' . implode(',', $close_blocks) . ')';
	}
}

$t->empty_($tag_errors, 'i tag IF/ENDIF e BEGIN/END dei template sono bilanciati');

// --- 9. i servizi ricevono tanti argomenti quanti ne accetta il costruttore ---
// Un argomento di troppo in services.yml non produce alcun errore: PHP lo
// ignora, e il parametro precedente riceve il valore sbagliato. E esattamente
// come '@request' e finito dentro $root_path.
$yaml = (string) @file_get_contents($root . '/config/services.yml');
$service_errors = array();

preg_match_all(
	'/^\s{4}([\w.]+):\s*\n\s{8}class:\s*([\w\\\\]+)\s*\n\s{8}arguments:\s*\n((?:\s{12}-.*\n)+)/m',
	$yaml, $services, PREG_SET_ORDER
);

foreach ($services as $svc)
{
	$arg_count = preg_match_all('/^\s{12}-/m', $svc[3]);
	$class_file = $root . '/' . str_replace('\\', '/', preg_replace('/^salvocortesiano\\\\meilisearch\\\\/', '', $svc[2])) . '.php';

	if (!file_exists($class_file))
	{
		continue;
	}

	$src = (string) file_get_contents($class_file);

	if (!preg_match('/public function __construct\(([^)]*)\)/s', $src, $ctor))
	{
		continue;
	}

	$params = trim($ctor[1]) === '' ? 0 : preg_match_all('/\$\w+/', $ctor[1]);

	if ($params !== $arg_count)
	{
		$service_errors[] = $svc[1] . ": services.yml $arg_count, costruttore $params";
	}
}

$t->empty_($service_errors, 'ogni servizio riceve il numero di argomenti che il costruttore accetta');

/* ================================================================== */

echo "\n" . str_repeat('=', 60) . "\n";
echo 'Superati: ' . $t->passed . '   Falliti: ' . count($t->failed) . "\n";

foreach ($t->failed as $f)
{
	echo "  FALLITO: $f\n";
}

echo empty($t->failed) ? "TUTTO A POSTO\n" : "CI SONO ERRORI\n";

exit(empty($t->failed) ? 0 : 1);
