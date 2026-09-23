<?php
/**
 *
 * Meilisearch Search Backend. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	// Impostazioni, mostrate da meilisearch_backend::acp() in ACP -> Impostazioni di ricerca
	'MEILISEARCH_URL'					=> 'URL di Meilisearch',
	'MEILISEARCH_URL_EXPLAIN'			=> 'URL di base dell&rsquo;istanza Meilisearch, senza slash finale. Esempio: <samp>http://127.0.0.1:7700</samp>. Tieni l&rsquo;istanza su un&rsquo;interfaccia privata o dietro un firewall: non ha alcun controllo di accesso per utente.',

	'MEILISEARCH_API_KEY'				=> 'Chiave API',
	'MEILISEARCH_API_KEY_EXPLAIN'		=> 'Master key, oppure una chiave API con le azioni <samp>search</samp>, <samp>documents.*</samp>, <samp>indexes.*</samp>, <samp>settings.*</samp>, <samp>stats.get</samp> e <samp>tasks.get</samp>. Lascia vuoto solo se l&rsquo;istanza gira senza master key. Il valore &egrave; salvato in chiaro nella tabella config ed &egrave; leggibile da qualsiasi fondatore.',

	'MEILISEARCH_INDEX'					=> 'Nome dell&rsquo;indice',
	'MEILISEARCH_INDEX_EXPLAIN'			=> 'L&rsquo;indice Meilisearch su cui scrive questo forum. Usa un nome diverso per ogni forum se pi&ugrave; installazioni condividono la stessa istanza Meilisearch.',

	'MEILISEARCH_LOCALES'				=> 'Lingue dei contenuti',
	'MEILISEARCH_LOCALES_EXPLAIN'		=> 'Codici ISO 639 separati da virgola per le lingue usate sul forum, ad esempio <samp>ita,eng</samp>. Fissa la tokenizzazione invece di affidarsi al riconoscimento automatico per documento, che sui post brevi &egrave; meno affidabile. Lascia vuoto per il riconoscimento automatico. Richiede Meilisearch 1.10 o superiore; sulle versioni precedenti l&rsquo;impostazione viene ignorata senza errori.',

	'MEILISEARCH_TIMEOUT'				=> 'Timeout delle richieste',
	'MEILISEARCH_TIMEOUT_EXPLAIN'		=> 'Secondi di attesa per una risposta di Meilisearch prima di rinunciare. Tienilo basso: un motore di ricerca lento non deve bloccare il rendering delle pagine.',

	'MEILISEARCH_MAX_RESULTS'			=> 'Limite dei candidati',
	'MEILISEARCH_MAX_RESULTS_EXPLAIN'	=> 'Numero massimo di post id restituiti da Meilisearch per una query, prima che phpBB riapplichi permessi e ordinamento in SQL. Valori pi&ugrave; alti danno conteggi pi&ugrave; completi sulle ricerche generiche, al costo di una clausola SQL <samp>IN()</samp> pi&ugrave; grande. 1000 va bene per la maggior parte dei forum.',

	'MEILISEARCH_BATCH_SIZE'			=> 'Dimensione del blocco di indicizzazione',
	'MEILISEARCH_BATCH_SIZE_EXPLAIN'	=> 'Numero di post inviati per ogni richiesta HTTP durante la reindicizzazione completa. Blocchi pi&ugrave; grandi sono pi&ugrave; veloci ma consumano pi&ugrave; memoria da entrambi i lati.',

	'MEILISEARCH_MIN_CHARS'				=> 'Caratteri minimi di ricerca',
	'MEILISEARCH_MIN_CHARS_EXPLAIN'		=> 'I termini pi&ugrave; corti di questo valore vengono scartati. A differenza del fulltext MySQL non c&rsquo;&egrave; un limite imposto dal motore, quindi 2 &egrave; un valore sicuro e permette di trovare sigle e codici prodotto.',

	'MEILISEARCH_MAX_CHARS'				=> 'Caratteri massimi di ricerca',
	'MEILISEARCH_MAX_CHARS_EXPLAIN'		=> 'I termini pi&ugrave; lunghi di questo valore vengono scartati.',

	'MEILISEARCH_TYPO'					=> 'Tolleranza agli errori di battitura',
	'MEILISEARCH_TYPO_EXPLAIN'			=> 'Consente la corrispondenza approssimata, cos&igrave; che <samp>fourm</samp> trovi comunque <samp>forum</samp>. Disattivala se il forum si basa su codici o numeri di parte esatti, dove una corrispondenza quasi giusta &egrave; fuorviante. Dopo la modifica occorre riapplicare le impostazioni dalla pagina di diagnostica.',

	'MEILISEARCH_RELEVANCE'				=> 'Ordinamento per rilevanza',
	'MEILISEARCH_RELEVANCE_EXPLAIN'		=> 'Come vengono ordinati i risultati per parole chiave. Meilisearch mette pi&ugrave; in alto i documenti che corrispondono a pi&ugrave; termini, quindi una ricerca di due parole mette per primi i messaggi che le contengono entrambe; l&rsquo;ordinamento di phpBB altrimenti butterebbe via quella classifica. Nota che il modulo di ricerca avanzata preseleziona &ldquo;Ora del messaggio&rdquo;, quindi invia sempre un ordinamento anche se l&rsquo;utente non ha toccato nulla &mdash; ed &egrave; il motivo per cui la modalit&agrave; consigliata tratta quel valore predefinito come &ldquo;nessuna scelta&rdquo;.',
	'MEILISEARCH_RELEVANCE_DEFAULT'		=> 'Rilevanza, a meno che l&rsquo;utente scelga un ordinamento diverso (consigliato)',
	'MEILISEARCH_RELEVANCE_IF_UNSET'	=> 'Rilevanza solo quando non viene inviato alcun ordinamento',
	'MEILISEARCH_RELEVANCE_NEVER'		=> 'Mai &mdash; usa sempre l&rsquo;ordinamento di phpBB',

	'MEILISEARCH_QUEUE'					=> 'Coda di ripetizione',
	'MEILISEARCH_QUEUE_EXPLAIN'			=> 'Quando Meilisearch non &egrave; raggiungibile, registra i post id interessati e li ritenta tramite cron invece di perdere l&rsquo;aggiornamento. Fortemente consigliata: senza, i messaggi scritti durante un&rsquo;interruzione non entrano mai nell&rsquo;indice.',

	// Errori
	'MEILISEARCH_EXT_DISABLED'			=> 'L&rsquo;estensione Meilisearch &egrave; selezionata come backend di ricerca ma non &egrave; attiva. Attivala, oppure riporta il forum su un altro backend.',
	'MEILISEARCH_NO_CURL'				=> 'L&rsquo;estensione PHP cURL &egrave; necessaria ma non &egrave; disponibile su questo server.',
	'MEILISEARCH_NO_URL'				=> 'Nessun URL Meilisearch configurato.',
	'MEILISEARCH_UNREACHABLE'			=> 'Non &egrave; stato possibile raggiungere l&rsquo;istanza Meilisearch.',
	'MEILISEARCH_SETTINGS_FAILED'		=> 'Non &egrave; stato possibile applicare le impostazioni dell&rsquo;indice.',
	'MEILISEARCH_PURGE_FAILED'			=> 'Non &egrave; stato possibile svuotare l&rsquo;indice.',
	'MEILISEARCH_NOT_ACTIVE'			=> 'Meilisearch &egrave; installato ma non &egrave; il backend di ricerca attivo, quindi al momento nulla di questo forum viene indicizzato.',

	// Statistiche indice, mostrate in ACP -> Manutenzione -> Indice di ricerca
	'MEILISEARCH_STAT_DOCUMENTS'		=> 'Messaggi indicizzati',
	'MEILISEARCH_STAT_INDEXING'			=> 'Elaborazione in corso',
	'MEILISEARCH_STAT_QUEUE'			=> 'Operazioni in attesa',
	'MEILISEARCH_STAT_ERROR'			=> 'Ultimo errore',

	// Modulo di diagnostica
	'ACP_MEILISEARCH_DIAGNOSTICS_EXPLAIN'	=> 'Stato in tempo reale della connessione e dell&rsquo;indice Meilisearch. Le impostazioni di connessione si trovano in Generale &rarr; Configurazione del forum &rarr; Impostazioni di ricerca, insieme agli altri backend.',
	'MEILISEARCH_CONNECTION'			=> 'Connessione',
	'MEILISEARCH_STATUS'				=> 'Stato',
	'MEILISEARCH_REACHABLE'				=> 'Raggiungibile',
	'MEILISEARCH_INDEX_STATE'			=> 'Indice',
	'MEILISEARCH_INDEX_MISSING'			=> 'non ancora creato',
	'MEILISEARCH_LOCALES_AUTO'			=> 'riconoscimento automatico',
	'MEILISEARCH_QUEUE_PENDING'			=> 'Operazioni in attesa',
	'MEILISEARCH_GO_TO_SETTINGS'		=> 'Vai alle impostazioni di ricerca',
	'MEILISEARCH_GO_TO_INDEX'			=> 'Vai alla pagina dell&rsquo;indice di ricerca per crearlo o eliminarlo',
	'MEILISEARCH_APPLY_SETTINGS'		=> 'Crea l&rsquo;indice e applica le impostazioni',
	'MEILISEARCH_FLUSH_QUEUE'			=> 'Svuota subito la coda',
	'MEILISEARCH_CLEAR_QUEUE'			=> 'Scarta la coda',
	'MEILISEARCH_SETTINGS_APPLIED'		=> 'L&rsquo;indice esiste e le impostazioni sono state applicate.',
	'MEILISEARCH_QUEUE_FLUSHED'			=> array(
		0	=> 'Nessuna operazione in attesa da elaborare.',
		1	=> '%d operazione in attesa elaborata.',
		2	=> '%d operazioni in attesa elaborate.',
	),
	'MEILISEARCH_QUEUE_CLEARED'			=> 'La coda di ripetizione &egrave; stata scartata. I messaggi interessati dalle voci scartate potrebbero ora mancare dall&rsquo;indice; in caso di dubbio esegui una reindicizzazione completa.',

	// Voci di log
	'LOG_MEILISEARCH_ERROR'				=> '<strong>Errore Meilisearch</strong><br />&raquo; %s',
	'LOG_MEILISEARCH_SETTINGS_APPLIED'	=> '<strong>Impostazioni dell&rsquo;indice Meilisearch applicate</strong>',
	'LOG_MEILISEARCH_QUEUE_CLEARED'		=> '<strong>Coda di ripetizione Meilisearch scartata</strong>',
	// Forum indicizzati
	'ACP_MEILISEARCH_FORUMS_EXPLAIN'	=> 'Scegli quali forum possono essere scritti nell&rsquo;indice Meilisearch. All&rsquo;installazione questa lista &egrave; stata precompilata con tutti i forum non leggibili dagli ospiti; adattala al tuo forum.',
	'MEILISEARCH_FORUMS_WARNING_TITLE'	=> 'Cosa fa, e cosa non fa',
	'MEILISEARCH_FORUMS_WARNING'		=> 'Questo &egrave; un controllo sui <strong>contenuti</strong>, non sui permessi. I risultati di ricerca rispettano gi&agrave; i permessi dei forum e la visibilit&agrave; di moderazione per ogni utente, perch&eacute; phpBB riapplica le proprie condizioni SQL dopo che Meilisearch ha trovato le parole chiave &mdash; nessuno &egrave; mai stato in grado di trovare messaggi che non pu&ograve; leggere. Escludere un forum qui va oltre: i suoi messaggi non escono mai dal database, quindi nemmeno un accesso diretto all&rsquo;API di Meilisearch pu&ograve; rivelarli. <strong>Il prezzo &egrave; che anche i membri che hanno legittimamente accesso a un forum escluso non ne troveranno i messaggi tramite la ricerca.</strong>',
	'MEILISEARCH_FORUMS_LIST'			=> 'Forum',
	'MEILISEARCH_FORUMS_COL_NAME'		=> 'Forum',
	'MEILISEARCH_FORUMS_EXCLUDE'		=> 'Escludi dall&rsquo;indice',
	'MEILISEARCH_FORUMS_INDEXED'		=> 'Indicizzati',
	'MEILISEARCH_FORUMS_EXCLUDED'		=> 'Esclusi',
	'MEILISEARCH_FORUMS_NONE'			=> 'Nessun forum trovato.',
	'MEILISEARCH_FORUMS_NO_INDEXING'	=> 'indicizzazione disattivata nelle impostazioni del forum',
	'MEILISEARCH_FORUMS_PRESELECT'		=> 'Preseleziona i forum non leggibili dagli ospiti',
	'MEILISEARCH_FORUMS_PRESELECT_NOTE'	=> 'La lista qui sotto mostra ora la selezione suggerita. <strong>Non &egrave; stato salvato nulla</strong> &mdash; controllala e premi Invia per applicarla.',
	'MEILISEARCH_FORUMS_PRESELECTED'	=> 'Selezione suggerita caricata nel modulo.',
	'MEILISEARCH_FORUMS_PURGE'			=> 'Rimuovi dall&rsquo;indice i forum esclusi',
	'MEILISEARCH_FORUMS_SAVED'			=> 'La lista delle esclusioni &egrave; stata salvata. I messaggi gi&agrave; indicizzati di un forum appena escluso sono ancora nell&rsquo;indice: usa &ldquo;Rimuovi dall&rsquo;indice i forum esclusi&rdquo; per eliminarli.',
	'MEILISEARCH_FORUMS_PURGED'			=> 'I documenti appartenenti ai forum esclusi sono stati rimossi dall&rsquo;indice.',
	'MEILISEARCH_STAT_EXCLUDED'			=> 'Forum esclusi',

	'LOG_MEILISEARCH_FORUMS_SAVED'		=> '<strong>Lista di esclusione forum Meilisearch aggiornata</strong>',
	'LOG_MEILISEARCH_FORUMS_PURGED'		=> '<strong>Forum esclusi rimossi dall&rsquo;indice Meilisearch</strong>',
	// Generazione della chiave API
	'MEILISEARCH_KEY_SECTION'			=> 'Chiave API',
	'MEILISEARCH_KEY_EXPLAIN'			=> 'La chiave serve solo se Meilisearch &egrave; raggiungibile dalla rete. Se gira su questo server su <samp>127.0.0.1</samp> ed &egrave; stato avviato senza master key, lascia il campo vuoto &mdash; &egrave; il binding su loopback a fare da barriera. Altrimenti crea la chiave qui, invece di richiederla a qualcuno. La chiave generata &egrave; limitata all&rsquo;indice di questo forum e alle sole azioni che l&rsquo;estensione esegue davvero, cos&igrave; una chiave trafugata non pu&ograve; essere usata per eliminare altri indici o leggere altri forum.',
	'MEILISEARCH_KEY_CURRENT'			=> 'Chiave salvata',
	'MEILISEARCH_KEY_PRESENT'			=> 'Una chiave &egrave; configurata.',
	'MEILISEARCH_KEY_ABSENT'			=> 'Nessuna chiave configurata. &Egrave; corretto cos&igrave; se Meilisearch gira su questo stesso server su 127.0.0.1 senza master key &mdash; lascia il campo vuoto e ignora il generatore qui sotto. La chiave serve solo se l&rsquo;istanza &egrave; raggiungibile dalla rete.',
	'MEILISEARCH_KEY_PERMISSIONS'		=> 'Permessi richiesti',
	'MEILISEARCH_KEY_GENERATE'			=> 'Genera una nuova chiave API',
	'MEILISEARCH_KEY_MASTER'			=> 'Master key',
	'MEILISEARCH_KEY_MASTER_EXPLAIN'	=> 'Il valore che hai passato come <samp>MEILI_MASTER_KEY</samp> all&rsquo;avvio di Meilisearch. Se non ce l&rsquo;hai pi&ugrave;, ispeziona le variabili d&rsquo;ambiente del container o riavvialo con una nuova.',
	'MEILISEARCH_KEY_MASTER_WARNING'	=> 'La master key viene usata per questa sola richiesta e <strong>non viene mai salvata</strong>. Nella configurazione del forum finisce solo la chiave generata. Non inserire la master key nel campo delle Impostazioni di ricerca.',
	'MEILISEARCH_KEY_GENERATE_BUTTON'	=> 'Genera e salva la chiave API',
	'MEILISEARCH_KEY_MASTER_REQUIRED'	=> 'Inserisci la master key di Meilisearch per generare una chiave API.',
	'MEILISEARCH_KEY_GENERATED'			=> 'Una nuova chiave API &egrave; stata creata e salvata. Le chiavi generate in precedenza restano valide sull&rsquo;istanza Meilisearch; revocale l&agrave; se non ti servono pi&ugrave;.',

	'LOG_MEILISEARCH_KEY_GENERATED'		=> '<strong>Chiave API Meilisearch generata</strong>',
	// Avviso nelle pagine di ricerca
	'MEILISEARCH_BANNER'				=> 'Mostra un avviso nelle pagine di ricerca',
	'MEILISEARCH_BANNER_EXPLAIN'		=> 'Mostra una riga in cima al modulo di ricerca avanzata e alla pagina dei risultati, per indicare agli utenti quale motore di ricerca &egrave; in uso. Visibile a tutti gli utenti. L&rsquo;avviso viene nascosto automaticamente quando Meilisearch non &egrave; il backend attivo, cos&igrave; non pu&ograve; mai dichiarare il falso.',
	'MEILISEARCH_BANNER_TEXT'			=> 'La ricerca di questo forum &egrave; gestita da Meilisearch: gli errori di battitura sono tollerati e si possono trovare anche parole brevi come sigle e codici prodotto.',
	// Pulsanti di test della diagnostica
	'MEILISEARCH_TEST_CONN'				=> 'Prova la connessione',
	'MEILISEARCH_TEST_CONN_EXPLAIN'		=> 'Interroga l&rsquo;endpoint di stato e riporta il tempo di andata e ritorno. Utile per individuare un collegamento che funziona ma &egrave; abbastanza lento da rallentare le pagine.',
	'MEILISEARCH_TEST_CONN_BUTTON'		=> 'Esegui il test di connessione',
	'MEILISEARCH_TEST_CONN_OK'			=> 'Connessione OK. Meilisearch ha risposto in %1$s ms (%2$s ms comprensivi di overhead).',
	'MEILISEARCH_TEST_CONN_FAIL'		=> 'Test di connessione fallito. %s',

	'MEILISEARCH_TEST_INDEX'			=> 'Prova l&rsquo;indice',
	'MEILISEARCH_TEST_INDEX_EXPLAIN'	=> 'Esegue una ricerca vera sull&rsquo;indice, invece di limitarsi a leggere le statistiche. &Egrave; l&rsquo;unico controllo che percorre lo stesso tragitto usato dalla pagina di ricerca.',
	'MEILISEARCH_TEST_INDEX_BUTTON'		=> 'Esegui il test dell&rsquo;indice',
	'MEILISEARCH_TEST_INDEX_OK'			=> 'Indice OK. La query ha trovato %1$d documenti in %2$s ms.',
	'MEILISEARCH_TEST_INDEX_FAIL'		=> 'Test dell&rsquo;indice fallito. %s',

	'MEILISEARCH_TEST_KEY'				=> 'Prova la chiave API',
	'MEILISEARCH_TEST_KEY_EXPLAIN'		=> 'Prova una per una le operazioni che l&rsquo;estensione esegue davvero. Individua una chiave presente ma priva di un permesso, cosa che altrimenti verrebbe fuori solo a met&agrave; di una reindicizzazione.',
	'MEILISEARCH_TEST_KEY_BUTTON'		=> 'Esegui il test della chiave',
	'MEILISEARCH_TEST_KEY_OK'			=> 'Chiave API OK. Tutte le operazioni necessarie sono riuscite: %s.',
	'MEILISEARCH_TEST_KEY_FAIL'			=> 'La chiave API &egrave; stata rifiutata per: %1$s. Genera una nuova chiave qui sotto. Ultimo errore: %2$s',
	// Rapporto di verifica
	'ACP_MEILISEARCH_HEALTH_EXPLAIN'	=> 'Una verifica completa dell&rsquo;installazione, che sonda ogni livello in sequenza: PHP, DNS, TCP, TLS, HTTP, autenticazione, impostazioni dell&rsquo;indice, una query reale e il lato phpBB. Non scrive nulla, quindi questa pagina si pu&ograve; eseguire e ricaricare in qualsiasi momento anche su un forum attivo.',

	'HEALTH_BLOCK_ENV'					=> 'Ambiente',
	'HEALTH_BLOCK_CONN'					=> 'Connettivit&agrave;',
	'HEALTH_BLOCK_INSTANCE'				=> 'Istanza Meilisearch',
	'HEALTH_BLOCK_INDEX'				=> 'Indice',
	'HEALTH_BLOCK_SEARCH'				=> 'Test di ricerca reale',
	'HEALTH_BLOCK_PHPBB'				=> 'Integrazione phpBB',

	'HEALTH_EXT_VERSION'				=> 'Versione dell&rsquo;estensione',
	'HEALTH_PHPBB_VERSION'				=> 'Versione di phpBB',
	'HEALTH_PHP_VERSION'				=> 'Versione di PHP',
	'HEALTH_CURL'						=> 'cURL / libreria TLS',
	'HEALTH_MBSTRING'					=> 'mbstring',
	'HEALTH_MEMORY_LIMIT'				=> 'Limite di memoria PHP',
	'HEALTH_MAX_EXECUTION'				=> 'Tempo massimo di esecuzione',
	'HEALTH_SERVER_TIME'				=> 'Ora del server',

	'HEALTH_URL'						=> 'URL configurato',
	'HEALTH_SCHEME'						=> 'Protocollo',
	'HEALTH_HOST'						=> 'Risoluzione dell&rsquo;host',
	'HEALTH_TCP'						=> 'Connessione TCP',
	'HEALTH_TLS'						=> 'Certificato TLS',
	'HEALTH_TLS_VALUE'					=> 'emesso da %1$s, scade il %2$s (%3$d giorni rimasti)',
	'HEALTH_ENDPOINT'					=> 'Endpoint di stato',
	'HEALTH_LATENCY_VALUE'				=> 'min %1$s, media %2$s, max %3$s su 3 chiamate',
	'HEALTH_TIMEOUT_SETTING'			=> 'Timeout configurato',

	'HEALTH_MEILI_VERSION'				=> 'Versione di Meilisearch',
	'HEALTH_DB_SIZE'					=> 'Dimensione del database su disco',
	'HEALTH_DB_USED'					=> 'Dimensione del database in uso',
	'HEALTH_LAST_UPDATE'				=> 'Ultima scrittura',
	'HEALTH_INDEX_COUNT'				=> 'Indici su questa istanza',
	'HEALTH_FAILED_TASKS'				=> 'Operazioni fallite recenti',
	'HEALTH_FAILED_TASK'				=> 'Operazione fallita',
	'HEALTH_PENDING_TASKS'				=> 'Operazioni in coda o in corso',

	'HEALTH_INDEX_UID'					=> 'Nome dell&rsquo;indice',
	'HEALTH_INDEX_EXISTS'				=> 'Indice raggiungibile',
	'HEALTH_DOCUMENTS'					=> 'Documenti nell&rsquo;indice',
	'HEALTH_IS_INDEXING'				=> 'Indicizzazione in corso',
	'HEALTH_SETTINGS'					=> 'Impostazioni dell&rsquo;indice',
	'HEALTH_ATTR_FILT'					=> 'Attributi filtrabili',
	'HEALTH_ATTR_SORT'					=> 'Attributi ordinabili',
	'HEALTH_ATTR_SEAR'					=> 'Attributi ricercabili',
	'HEALTH_TYPO'						=> 'Tolleranza ai refusi (valore reale)',
	'HEALTH_MAX_HITS'					=> 'maxTotalHits (reale / richiesto)',
	'HEALTH_LOCALES'					=> 'Lingue dei contenuti',

	'HEALTH_QUERY_EMPTY'				=> 'Query vuota',
	'HEALTH_QUERY_TERM'					=> 'Query con parola reale',
	'HEALTH_QUERY_FILTER'				=> 'Query con filtro',
	'HEALTH_QUERY_VALUE'				=> '%1$s risultati in %2$s',
	'HEALTH_QUERY_TERM_VALUE'			=> '&ldquo;%1$s&rdquo; → %2$s risultati in %3$s',

	'HEALTH_BACKEND'					=> 'Backend di ricerca attivo',
	'HEALTH_ELIGIBLE_POSTS'				=> 'Messaggi da indicizzare',
	'HEALTH_DRIFT'						=> 'Scostamento indice/forum',
	'HEALTH_DRIFT_VALUE'				=> '%1$s documenti (%2$s%%)',
	'HEALTH_QUEUE'						=> 'Coda di ripetizione',
	'HEALTH_QUEUE_AGE'					=> 'Operazione in coda pi&ugrave; vecchia',
	'HEALTH_CRON'						=> 'Ultima esecuzione del cron',
	'HEALTH_EXCLUDED_FORUMS'			=> 'Forum esclusi',
	'HEALTH_RELEVANCE_MODE'				=> 'Modalit&agrave; rilevanza',
	'HEALTH_BATCH_SIZE'					=> 'Dimensione del blocco di indicizzazione',
	'HEALTH_MAX_RESULTS'				=> 'Limite dei candidati',

	'HEALTH_SKIPPED'					=> 'Saltato',
	'HEALTH_SKIPPED_UNREACHABLE'		=> 'l&rsquo;istanza non ha risposto, quindi questi controlli non sono stati eseguiti',
	'HEALTH_SKIPPED_NO_INDEX'			=> 'l&rsquo;indice manca o non &egrave; raggiungibile, quindi nessuna query &egrave; stata possibile',
	'HEALTH_SCOPED_KEY'					=> 'non disponibile con una chiave limitata a un indice (normale, non &egrave; un guasto)',
	'HEALTH_NOT_SET'					=> 'non configurato',
	'HEALTH_MISSING'					=> 'mancante',
	'HEALTH_PRESENT'					=> 'presente',
	'HEALTH_UNLIMITED'					=> 'illimitato',
	'HEALTH_NONE'						=> 'nessuna',
	'HEALTH_NEVER'						=> 'mai',
	'HEALTH_COMPLETE'					=> 'tutti presenti',
	'HEALTH_MISSING_LIST'				=> 'mancanti: %s',
	'HEALTH_HOURS'						=> '%s ore',
	'HEALTH_HOURS_AGO'					=> '%s ore fa',

	'HEALTH_VERDICT_OK'					=> 'Tutto in ordine',
	'HEALTH_VERDICT_WARN'				=> 'Funziona, ma ci sono punti da rivedere',
	'HEALTH_VERDICT_FAIL'				=> 'Problemi rilevati',
	'HEALTH_SUMMARY'					=> '%1$d superati, %2$d avvisi, %3$d errori &mdash; rapporto generato in %4$d ms.',
	'HEALTH_HINTS'						=> 'Cosa fare',
	'HEALTH_REPORT'						=> 'Rapporto in testo semplice',
	'HEALTH_REPORT_EXPLAIN'				=> 'Lo stesso rapporto in formato testo, da incollare in una discussione di supporto. Non contiene chiavi API n&eacute; contenuti dei messaggi.',
	'HEALTH_COPY'						=> 'Copia negli appunti',
	'HEALTH_COPIED'						=> 'Copiato',
	'HEALTH_RERUN'						=> 'Esegui di nuovo i controlli',
	'HEALTH_GO_DIAGNOSTICS'				=> 'Torna alla diagnostica',

	'HEALTH_HINT_CURL'					=> 'L&rsquo;estensione cURL non &egrave; disponibile. Senza di essa nulla di questa estensione pu&ograve; funzionare; chiedi al tuo host di abilitarla.',
	'HEALTH_HINT_EXECUTION'				=> 'max_execution_time &egrave; basso. La costruzione dell&rsquo;indice funziona comunque &mdash; phpBB riprende dopo ogni timeout &mdash; ma richieder&agrave; pi&ugrave; passaggi.',
	'HEALTH_HINT_NO_URL'				=> 'Imposta l&rsquo;URL di Meilisearch in Generale &rarr; Configurazione server &rarr; Motore di ricerca.',
	'HEALTH_HINT_PLAINTEXT'				=> 'L&rsquo;istanza &egrave; raggiunta via HTTP in chiaro su un indirizzo non locale, quindi la chiave API e i contenuti dei messaggi attraversano la rete non cifrati. Mettila dietro HTTPS.',
	'HEALTH_HINT_TCP'					=> 'La porta non ha accettato la connessione. O il demone &egrave; fermo, o un firewall lo sta bloccando. Sul server: <samp>systemctl status meilisearch</samp> e <samp>journalctl -u meilisearch -n 50</samp>.',
	'HEALTH_HINT_TLS'					=> 'Il certificato TLS &egrave; scaduto o sta per scadere. Verifica che il rinnovo automatico funzioni ancora: <samp>certbot renew --dry-run</samp>.',
	'HEALTH_HINT_UNREACHABLE'			=> 'Meilisearch non ha risposto. Riavvialo con <samp>systemctl restart meilisearch</samp>, poi controlla <samp>journalctl -u meilisearch -n 50</samp> per capire perch&eacute; si &egrave; fermato. Se si ferma ripetutamente, aggiungi <samp>Restart=always</samp> e un limite <samp>MemoryMax</samp> all&rsquo;unit&agrave; systemd.',
	'HEALTH_HINT_SLOW'					=> 'Il tempo di risposta &egrave; alto. Se la connessione TCP qui sopra &egrave; stata veloce, il collo di bottiglia &egrave; il demone e non la rete &mdash; verifica la memoria disponibile sul server.',
	'HEALTH_HINT_NO_INDEX'				=> 'L&rsquo;indice non esiste. Premi &ldquo;Crea l&rsquo;indice e applica le impostazioni&rdquo; nella pagina di diagnostica, poi ricostruiscilo in Manutenzione &rarr; Indice ricerca.',
	'HEALTH_HINT_EMPTY_INDEX'			=> 'L&rsquo;indice esiste ma non contiene documenti. Ricostruiscilo in Manutenzione &rarr; Indice ricerca. Fino ad allora le ricerche non restituiscono nulla.',
	'HEALTH_HINT_SETTINGS'				=> 'Le impostazioni reali dell&rsquo;indice non corrispondono a quelle attese dall&rsquo;estensione. Premi &ldquo;Crea l&rsquo;indice e applica le impostazioni&rdquo; nella diagnostica; &egrave; un&rsquo;operazione sicura e non elimina documenti.',
	'HEALTH_HINT_FAILED_TASKS'			=> 'Meilisearch ha registrato operazioni fallite. Poich&eacute; l&rsquo;indicizzazione &egrave; asincrona, questi errori non arrivano mai al log di phpBB &mdash; il messaggio qui sopra &egrave; la causa reale. Un errore di disco pieno o di permessi qui spiega un indice che ha smesso di aggiornarsi senza preavviso.',
	'HEALTH_HINT_QUERY'					=> 'Una query &egrave; stata rifiutata. Se il messaggio cita un attributo, riapplica le impostazioni dell&rsquo;indice; se cita l&rsquo;autorizzazione, rigenera la chiave API.',
	'HEALTH_HINT_TERM_MISS'				=> 'Una parola presa dal titolo di un messaggio reale non ha prodotto risultati. L&rsquo;indice &egrave; probabilmente obsoleto: ricostruiscilo in Manutenzione &rarr; Indice ricerca.',
	'HEALTH_HINT_NOT_ACTIVE'			=> 'Meilisearch non &egrave; il backend di ricerca attivo, quindi il forum non lo sta usando. Attivalo in Generale &rarr; Configurazione server &rarr; Motore di ricerca.',
	'HEALTH_HINT_DRIFT'					=> 'Il numero di documenti non corrisponde ai messaggi che dovrebbero essere indicizzati. Alcuni messaggi mancano dall&rsquo;indice &mdash; tipicamente perch&eacute; il demone era fermo mentre venivano scritti. Svuota la coda di ripetizione, e ricostruisci l&rsquo;indice se la differenza &egrave; ampia.',
	'HEALTH_HINT_QUEUE'					=> 'Ci sono operazioni in attesa nella coda di ripetizione, il che significa che a un certo punto le scritture verso Meilisearch sono fallite. Vengono riprovate dal cron; premi &ldquo;Svuota subito la coda&rdquo; nella diagnostica per forzarlo.',
	'HEALTH_HINT_CRON'					=> 'Il cron non &egrave; stato eseguito di recente. Senza, la coda di ripetizione non viene mai svuotata. Un cron di sistema &egrave; pi&ugrave; affidabile del cron web di phpBB: <samp>*/5 * * * * php /percorso/di/phpBB/bin/phpbbcli.php cron:run --quiet</samp>',
	// Badge ACP
	'MEILI_BADGE_VERSION'				=> 'versione',
	'MEILI_BADGE_LICENSE'				=> 'licenza',
	// Stato della riga forum
	'MEILISEARCH_FORUMS_STATE_ON'		=> 'indicizzato',
	'MEILISEARCH_FORUMS_STATE_OFF'		=> 'escluso',
	// Guida al flusso e reindicizzazione
	'MEILISEARCH_FORUMS_GUIDE_TITLE'	=> 'Come rendere effettiva una modifica fatta qui',
	'MEILISEARCH_FORUMS_GUIDE'			=> 'Le due direzioni non sono simmetriche. <strong>Se escludi un forum</strong>: premi Invia, poi &ldquo;Rimuovi dall&rsquo;indice i forum esclusi&rdquo; &mdash; &egrave; una sola chiamata di eliminazione per filtro, istantanea anche su forum grandi. <strong>Se includi un forum</strong> che era escluso: premi Invia, poi usa il pannello di reindicizzazione qui sotto, perch&eacute; quei messaggi non sono mai stati inviati a Meilisearch e vanno riletti dal database. Nota che la pagina Indice di ricerca di phpBB mostra &ldquo;Crea&rdquo; <em>oppure</em> &ldquo;Cancella&rdquo;, mai entrambi: quando l&rsquo;indice contiene documenti il pulsante &ldquo;Crea&rdquo; l&igrave; non c&rsquo;&egrave; pi&ugrave; &mdash; ed &egrave; proprio ci&ograve; che il pannello qui sotto sostituisce. La reindicizzazione <strong>non &egrave; distruttiva</strong>: Meilisearch sostituisce i documenti sul posto in base al post id, quindi la ricerca continua a funzionare e non devi mai cancellare l&rsquo;indice prima. Un&rsquo;ultima cosa: un forum che ha &ldquo;Abilita l&rsquo;indicizzazione per la ricerca&rdquo; su No nelle proprie impostazioni phpBB resta fuori comunque, qualunque cosa scegli qui; nella lista sono segnalati.',

	'MEILISEARCH_REINDEX'				=> 'Reindicizzazione',
	'MEILISEARCH_REINDEX_EXPLAIN'		=> 'Scorre la tabella dei messaggi e reinvia i documenti a Meilisearch. Procede a blocchi e riprende dopo ogni timeout, quindi &egrave; sicura anche su hosting con limiti di esecuzione bassi. I forum esclusi e quelli con l&rsquo;indicizzazione disattivata vengono saltati.',
	'MEILISEARCH_REINDEX_SCOPE'			=> 'Cosa reindicizzare',
	'MEILISEARCH_REINDEX_SCOPE_EXPLAIN'	=> 'Scegli un singolo forum dopo averne incluso uno che era escluso &mdash; &egrave; molto pi&ugrave; rapido che scorrere tutto il board. Scegli tutti i forum dopo una modifica massiva o quando il rapporto di verifica segnala uno scostamento.',
	'MEILISEARCH_REINDEX_ALL'			=> 'Tutti i forum indicizzati',
	'MEILISEARCH_REINDEX_BUTTON'		=> 'Avvia la reindicizzazione',
	'MEILISEARCH_REINDEX_PROGRESS'		=> 'Reindicizzazione: messaggio %1$d di %2$d (%3$d%%). La pagina si ricarica da sola; lasciala aperta.',
	'MEILISEARCH_REINDEX_DONE'			=> 'Reindicizzazione completata.',

	'LOG_MEILISEARCH_REINDEXED'			=> '<strong>Reindicizzazione Meilisearch avviata dalla pagina dei forum indicizzati</strong>',
));
