# Meilisearch-Search-Backend-for-phpBB-3.3
A drop-in replacement for phpBB's built-in fulltext search. It registers a new **search backend** that delegates keyword matching to a [Meilisearch](https://www.meilisearch.com/) instance, while keeping every permission check inside phpBB's own SQL.

![phpBB 3.3+](https://img.shields.io/badge/phpBB-3.3%2B-blue)
![PHP 7.1+](https://img.shields.io/badge/PHP-7.1%2B-8892bf)
![licence GPL-2.0-only](https://img.shields.io/badge/licence-GPL--2.0--only-green)


# Why replace phpBB's built-in search?

This document explains what is wrong with phpBB's native search, what changes
when you put Meilisearch behind it, and — just as importantly — **when you should
not bother**.

If you only read one thing: phpBB's search does not fail loudly. It returns
"No suitable matches were found" and the user assumes the content is not there.
The posts are there. The search cannot reach them.

---

## 1. The short version

| | phpBB native | With this extension |
|---|---|---|
| Minimum word length | 3–4 characters, **fixed by the server** | 2 by default, admin-configurable |
| `V8`, `SSD`, `R7`, `AMG` | not searchable | searchable |
| Typos | no results | results |
| Accents (`perché` / `perche`) | two different words | the same word |
| Italian plurals and verb forms | two different words | handled |
| Mixed-language boards | one stemmer for everything | per-language tokenisation |
| Very common words | silently dropped | kept |
| Index location | inside MySQL | outside MySQL |
| Index size | often ~2× the posts table | a few hundred MB, off the database |
| Result ranking | word frequency | BM25 with relevance ordering |

---

## 2. What is actually broken, backend by backend

phpBB 3.3 ships four search backends. They are not four levels of quality: they
are four different compromises, and every one of them fails on something a real
forum needs.

### `fulltext_native` — the default

The best relevance of the four, and the one nearly every board uses because it
works everywhere without configuration. Two structural problems:

**The index lives in your database and it is enormous.** It is built from two
tables, `search_wordlist` (one row per distinct word ever posted) and
`search_wordmatch` (one row per word-per-post). On a real board the second one
reaches millions of rows. phpBB's own documentation notes that a board with
around 300 MB of posts produces an index of roughly 600 MB — **twice the size of
the content it indexes**. On shared hosting with a database quota, this alone can
be the thing that stops you growing.

**It silently discards common words.** Words that appear in a large share of
posts get flagged as "common" in `search_wordlist` and are then ignored. On a
specialist forum this is exactly backwards: on a photography board, "lens" is in
half the posts, so "lens" becomes unsearchable — the very word people search
for.

### `fulltext_mysql`

The index is small because MySQL manages it. But the minimum word length is a
**server variable**, not a phpBB setting:

- InnoDB: `innodb_ft_min_token_size`, default **3**
- MyISAM: `ft_min_word_len`, default **4**

On shared hosting you cannot change either. Which means, permanently:

- a car forum cannot search `V8`
- a hardware forum cannot search `SSD` or `RAM`
- a photography forum cannot search `f/2` or `R7`
- a Mercedes forum cannot search `AMG`

These are not edge cases. On most technical forums they are among the most
frequent queries.

### `fulltext_postgres`

Only available on PostgreSQL, which very few phpBB boards run. The text-search
configuration is fixed when the index is built, so changing language handling
means rebuilding from scratch.

### `fulltext_sphinx`

Fast, but requires shell access and a separate daemon — so it was never an option
for most boards — and Sphinx as an open source project is effectively
unmaintained. Choosing it today means building on something that has stopped
moving.

**None of the four** offers typo tolerance. **None** does useful stemming outside
a handful of languages. **None** handles a board where people write in two
languages.

---

## 3. The same searches, before and after

Concrete cases, all of them things forum members actually type.

| Query | phpBB native | Meilisearch |
|---|---|---|
| `SSD` | nothing (too short for MySQL fulltext) | all posts about SSDs |
| `carburatre` (typo) | nothing | posts about carburettors |
| `perche` (no accent) | misses `perché` | finds both |
| `carburatori` | misses `carburatore` | finds both |
| `V8 admission` | nothing (`V8` discarded) | relevant posts |
| `lens` on a photography board | nothing ("common word") | all of them |
| `"exact phrase"` | works | works |
| `-exclusion` | works | works |
| `+word`, `word \| word` | works | approximated, see §7 |

The pattern is consistent: the native backend fails on **short terms, misspelled
terms, inflected forms, and terms that are too frequent**. Those four categories
cover a very large share of what people actually search for.

---

## 4. What this does to your database

This is the argument that convinces most administrators, because it is
measurable.

With `fulltext_native`, the two index tables are frequently the largest in the
entire database — larger than `phpbb_posts` itself. They grow with every post and
they are never smaller than the content.

With this extension the index is not in MySQL at all. `search_wordlist` and
`search_wordmatch` can be dropped entirely once Meilisearch is running and
verified.

What that gives you:

- a smaller database, so **faster and cheaper backups**
- less pressure on the MySQL buffer pool, so the rest of the board benefits
- search queries stop competing with page rendering for database resources
- room to grow on hosting with a database quota

The old index stays untouched until you delete it, which means the migration has
a rollback at every step.

---

## 5. Italian, and multilingual boards

Worth stating separately because most search comparisons are written in English
and quietly ignore this.

**Accents.** `perché`, `perchè`, `perche` — members type all three. The native
backend treats them as three different words. Meilisearch normalises them to one.

**Inflection.** Italian is far more inflected than English. `carburatore` /
`carburatori`, `cerco` / `cercare` / `cercavo`. phpBB's native stemmer does not
handle Italian; Meilisearch does.

**Mixed languages.** Many Italian forums have English titles, English product
names, English quotes. A single stemmer cannot serve both. Meilisearch tokenises
per language, and this extension lets you declare which languages the board uses
(`ita,eng`) instead of relying on per-document guessing, which is unreliable on
short posts.

---

## 6. What the members will notice

The interface does not change at all. There is no new search box, no new page,
no retraining. What changes is that searches start returning results.

Concretely:

- fewer duplicate topics, because people find the existing one
- fewer "already answered, use search" replies, because search actually works
- old content becomes reachable again — on a board with years of history, this
  is the difference between an archive and a graveyard
- optional: a short notice on the search page telling users that typos are
  tolerated and short words work, so they change how they search

---

## 7. Where it is *not* better — read this too

An honest comparison has to include the losses.

**Boolean operators are approximated.** phpBB's native syntax supports `+word`,
`-word`, `word | word` and parentheses. Meilisearch handles `"exact phrases"` and
`-exclusions` natively, but `+` and `|` are dropped: its ranking already places
documents matching more terms higher, which is the practical equivalent of OR,
but it is not the same thing. Power users who rely on `+` will notice.

**Result counts are capped.** Only the top N Meilisearch hits (1000 by default,
configurable) are passed to the permission stage. On a very broad query the
reported total is an undercount. Native search has no such cap.

**Relevance ordering is not always active.** phpBB's search form has no
"sort by relevance" option, so this extension applies relevance only when the
user has not explicitly chosen a sort order. If your style pre-selects a sort,
relevance never kicks in.

**It needs a machine.** Meilisearch is a daemon, not a PHP library. On shared
hosting with no VPS, the only route is Meilisearch Cloud, which is paid after a
trial. This is the single biggest reason not to adopt it.

**One more moving part.** A second service to install, monitor and keep updated.
If it goes down, search stops working — posting and reading are unaffected, and
a retry queue prevents the index from drifting, but it is one more thing that can
break.

---

## 8. Should you use it?

**Yes, if:**

- your board is technical, and short terms like model numbers matter
- you have years of content that people cannot find
- your search index is a problem in the database
- your board is not in English, or not only in English
- you control a VPS or dedicated server

**Probably not, if:**

- your board is small, and the native index costs nothing
- your only hosting is shared and you do not want a recurring cost
- your members depend on phpBB's boolean syntax
- nobody has ever complained about search

The honest test: search your own board for a three-letter term you know appears
in posts. If you get nothing, this extension solves a real problem you have. If
you get results, your board may not need it.

---
---

# ITALIANO:

# Perché sostituire la ricerca integrata di phpBB?

Questo documento spiega cosa non va nella ricerca nativa di phpBB, cosa cambia
mettendoci dietro Meilisearch e — cosa altrettanto importante — **quando non ne
vale la pena**.

Se leggi una cosa sola, leggi questa: la ricerca di phpBB non fallisce in modo
evidente. Restituisce "Nessun risultato trovato" e l'utente conclude che il
contenuto non ci sia. I messaggi ci sono. È la ricerca che non riesce ad
arrivarci.

---

## 1. In breve

| | phpBB nativo | Con questa estensione |
|---|---|---|
| Lunghezza minima delle parole | 3–4 caratteri, **fissata dal server** | 2 di default, configurabile |
| `V8`, `SSD`, `R7`, `AMG` | non cercabili | cercabili |
| Errori di battitura | nessun risultato | risultati |
| Accenti (`perché` / `perche`) | due parole diverse | la stessa parola |
| Plurali e forme verbali italiane | due parole diverse | gestiti |
| Forum con lingue miste | un solo stemmer per tutto | tokenizzazione per lingua |
| Parole molto frequenti | scartate senza avviso | mantenute |
| Posizione dell'indice | dentro MySQL | fuori da MySQL |
| Dimensione dell'indice | spesso ~2× la tabella dei post | poche centinaia di MB, fuori dal database |
| Ordinamento dei risultati | frequenza delle parole | BM25 con ordinamento per rilevanza |

---

## 2. Cosa è rotto davvero, backend per backend

phpBB 3.3 offre quattro backend di ricerca. Non sono quattro livelli di qualità:
sono quattro compromessi diversi, e ognuno fallisce su qualcosa di cui un forum
reale ha bisogno.

### `fulltext_native` — quello predefinito

La rilevanza migliore dei quattro, ed è quello che quasi tutti i forum usano
perché funziona ovunque senza configurazione. Due problemi strutturali:

**L'indice vive nel tuo database ed è enorme.** È costruito su due tabelle,
`search_wordlist` (una riga per ogni parola distinta mai scritta) e
`search_wordmatch` (una riga per ogni coppia parola-messaggio). Su un forum reale
la seconda arriva a milioni di righe. La documentazione di phpBB stessa segnala
che un forum con circa 300 MB di messaggi produce un indice di circa 600 MB —
**il doppio del contenuto che indicizza**. Su hosting condiviso con una quota sul
database, questo da solo può essere ciò che ti impedisce di crescere.

**Scarta le parole comuni senza dirtelo.** Le parole presenti in una quota
elevata di messaggi vengono marcate come "comuni" in `search_wordlist` e da quel
momento ignorate. Su un forum specialistico è esattamente il contrario di quello
che serve: su un forum di fotografia "obiettivo" è in metà dei messaggi, quindi
"obiettivo" diventa non cercabile — proprio la parola che la gente cerca.

### `fulltext_mysql`

L'indice è piccolo perché lo gestisce MySQL. Ma la lunghezza minima delle parole
è una **variabile del server**, non un'impostazione di phpBB:

- InnoDB: `innodb_ft_min_token_size`, default **3**
- MyISAM: `ft_min_word_len`, default **4**

Su hosting condiviso non puoi cambiare né l'una né l'altra. Il che significa, in
modo permanente:

- un forum di auto non può cercare `V8`
- un forum di hardware non può cercare `SSD` o `RAM`
- un forum di fotografia non può cercare `f/2` o `R7`
- un forum Mercedes non può cercare `AMG`

Non sono casi limite. Sulla maggior parte dei forum tecnici sono tra le ricerche
più frequenti in assoluto.

### `fulltext_postgres`

Disponibile solo su PostgreSQL, che pochissimi forum phpBB usano. La
configurazione di ricerca testuale è fissata al momento della costruzione
dell'indice, quindi cambiare la gestione della lingua significa ricostruire tutto
da zero.

### `fulltext_sphinx`

Veloce, ma richiede accesso shell e un demone separato — quindi non è mai stato
un'opzione per la maggior parte dei forum — e Sphinx come progetto open source è
di fatto abbandonato. Sceglierlo oggi significa costruire su qualcosa che si è
fermato.

**Nessuno dei quattro** offre tolleranza agli errori di battitura. **Nessuno** fa
stemming utile oltre a una manciata di lingue. **Nessuno** gestisce un forum dove
si scrive in due lingue.

---

## 3. Le stesse ricerche, prima e dopo

Casi concreti, tutti cose che i membri di un forum digitano davvero.

| Ricerca | phpBB nativo | Meilisearch |
|---|---|---|
| `SSD` | niente (troppo corta per il fulltext MySQL) | tutti i messaggi sugli SSD |
| `carburatre` (refuso) | niente | messaggi sui carburatori |
| `perche` (senza accento) | non trova `perché` | trova entrambi |
| `carburatori` | non trova `carburatore` | trova entrambi |
| `V8 aspirazione` | niente (`V8` scartata) | messaggi pertinenti |
| `obiettivo` su un forum di fotografia | niente ("parola comune") | tutti |
| `"frase esatta"` | funziona | funziona |
| `-esclusione` | funziona | funziona |
| `+parola`, `parola \| parola` | funziona | approssimato, vedi §7 |

Lo schema è coerente: il backend nativo fallisce su **termini corti, termini
scritti male, forme flesse e termini troppo frequenti**. Quelle quattro categorie
coprono una quota molto ampia di ciò che la gente cerca davvero.

---

## 4. Cosa comporta per il tuo database

È l'argomento che convince la maggior parte degli amministratori, perché è
misurabile.

Con `fulltext_native` le due tabelle dell'indice sono spesso le più grandi
dell'intero database — più grandi della stessa `phpbb_posts`. Crescono a ogni
messaggio e non sono mai più piccole del contenuto.

Con questa estensione l'indice non è in MySQL. `search_wordlist` e
`search_wordmatch` si possono eliminare del tutto una volta che Meilisearch è in
funzione e verificato.

Cosa ti dà:

- un database più piccolo, quindi **backup più veloci ed economici**
- meno pressione sul buffer pool di MySQL, con beneficio per tutto il resto del
  forum
- le query di ricerca smettono di competere con il rendering delle pagine per le
  risorse del database
- spazio per crescere su hosting con quota sul database

Il vecchio indice resta intatto finché non lo elimini tu, il che significa che la
migrazione ha una via di ritorno a ogni passo.

---

## 5. L'italiano, e i forum multilingua

Vale la pena dirlo a parte, perché quasi tutti i confronti sui motori di ricerca
sono scritti in inglese e questo punto lo saltano.

**Accenti.** `perché`, `perchè`, `perche` — i membri li scrivono tutti e tre. Il
backend nativo li tratta come tre parole diverse. Meilisearch li normalizza a
una.

**Flessione.** L'italiano è molto più flesso dell'inglese. `carburatore` /
`carburatori`, `cerco` / `cercare` / `cercavo`. Lo stemmer nativo di phpBB non
gestisce l'italiano; Meilisearch sì.

**Lingue miste.** Molti forum italiani hanno titoli in inglese, nomi di prodotto
in inglese, citazioni in inglese. Un solo stemmer non può servire entrambe.
Meilisearch tokenizza per lingua, e questa estensione ti permette di dichiarare
quali lingue usa il forum (`ita,eng`) invece di affidarsi al riconoscimento
automatico per documento, che sui messaggi brevi è poco affidabile.

---

## 6. Cosa noteranno i membri

L'interfaccia non cambia per niente. Nessuna nuova barra di ricerca, nessuna
pagina nuova, niente da reimparare. Quello che cambia è che le ricerche iniziano
a restituire risultati.

In concreto:

- meno argomenti duplicati, perché la gente trova quello che già esiste
- meno risposte del tipo "già risposto, usa la ricerca", perché la ricerca
  funziona davvero
- i contenuti vecchi tornano raggiungibili — su un forum con anni di storia,
  è la differenza tra un archivio e un cimitero
- facoltativo: un avviso sulla pagina di ricerca che informa gli utenti che i
  refusi sono tollerati e le parole corte funzionano, così cambiano il modo in
  cui cercano

---

## 7. Dove *non* è migliore — leggi anche questo

Un confronto onesto deve includere le perdite.

**Gli operatori booleani sono approssimati.** La sintassi nativa di phpBB
supporta `+parola`, `-parola`, `parola | parola` e le parentesi. Meilisearch
gestisce nativamente `"frasi esatte"` ed `-esclusioni`, ma `+` e `|` vengono
rimossi: il suo ranking mette già più in alto i documenti che corrispondono a più
termini, che è l'equivalente pratico di un OR, ma non è la stessa cosa. Gli utenti
esperti che si affidano a `+` se ne accorgeranno.

**I conteggi dei risultati sono limitati.** Solo i primi N risultati Meilisearch
(1000 di default, configurabile) passano allo stadio dei permessi. Su una query
molto generica il totale riportato è per difetto. La ricerca nativa non ha questo
limite.

**L'ordinamento per rilevanza non è sempre attivo.** Il modulo di ricerca di
phpBB non ha una voce "ordina per rilevanza", quindi questa estensione la applica
solo quando l'utente non ha scelto esplicitamente un ordinamento. Se il tuo tema
preseleziona un ordinamento, la rilevanza non entra mai in gioco.

**Serve una macchina.** Meilisearch è un demone, non una libreria PHP. Su hosting
condiviso senza un VPS, l'unica strada è Meilisearch Cloud, che è a pagamento
dopo la prova. È la ragione singola più importante per non adottarlo.

**Un pezzo in più che si muove.** Un secondo servizio da installare, monitorare e
tenere aggiornato. Se si ferma, la ricerca smette di funzionare — scrivere e
leggere non ne risentono, e una coda di ripetizione impedisce all'indice di
divergere, ma è una cosa in più che si può rompere.

---

## 8. Ti conviene?

**Sì, se:**

- il tuo forum è tecnico, e contano i termini corti come i codici modello
- hai anni di contenuti che la gente non riesce a trovare
- l'indice di ricerca è un problema nel database
- il tuo forum non è in inglese, o non solo in inglese
- controlli un VPS o un server dedicato

**Probabilmente no, se:**

- il forum è piccolo, e l'indice nativo non costa nulla
- il tuo unico hosting è condiviso e non vuoi un costo ricorrente
- i tuoi membri dipendono dalla sintassi booleana di phpBB
- nessuno si è mai lamentato della ricerca

La prova onesta: cerca sul tuo forum un termine di tre lettere che sai essere
presente nei messaggi. Se non ottieni nulla, questa estensione risolve un
problema che hai davvero. Se ottieni risultati, forse il tuo forum non ne ha
bisogno.


# Meilisearch Search Backend for phpBB 3.3

A drop-in replacement for phpBB's built-in fulltext search. It registers a new
**search backend** that delegates keyword matching to a [Meilisearch](https://www.meilisearch.com/)
instance, while keeping every permission check inside phpBB's own SQL.

Nothing in the user-facing interface changes. There is no second search box, no
new template, no modified `search.php`. An administrator switches the backend in
the ACP and the existing search page, the header quick-search, "Search this
topic" and author search all start using Meilisearch.

**What you get over the native backend**

- Typo tolerance — `carburator` finds `carburetor`
- Words of any length — `V8`, `SSD` and `R7` become searchable
- Proper multilingual tokenisation, including mixed-language boards
- Accent folding — `perché` matches `perche`
- The index lives outside MySQL, so `search_wordlist` and `search_wordmatch`
  disappear from the database
- Relevance ranking, per-forum indexing control, and an ACP diagnostics page

## Table of contents

1. [Why](#why)
2. [Architecture](#architecture)
3. [Requirements](#requirements)
4. [Deployment](#deployment)
   - [Setup A — same server](#setup-a--meilisearch-on-the-same-server-as-phpbb)
   - [Setup B — separate server (Hetzner)](#setup-b--meilisearch-on-a-separate-server-hetzner)
   - [Setup C — Meilisearch Cloud](#setup-c--meilisearch-cloud)
   - [Finishing up](#finishing-up-all-setups)
5. [Configuration reference](#configuration-reference)
6. [Building the index](#building-the-index)
   - [Choosing which forums to index](#choosing-which-forums-to-index)
7. [Operating it](#operating-it)
8. [Security model](#security-model)
9. [Limitations and trade-offs](#limitations-and-trade-offs)
10. [Troubleshooting](#troubleshooting)
11. [Uninstalling](#uninstalling)
12. [Developer notes](#developer-notes)
13. [phpBB 4.0](#phpbb-40)
14. [License](#license)

---
## Why

phpBB 3.3 ships four search backends and each one forces a bad trade:

| Backend | Problem |
|---|---|
| `fulltext_native` | Best relevance of the four, but the `search_wordlist` / `search_wordmatch` tables commonly reach **twice the size of the posts table**. On large boards the index dominates the database. |
| `fulltext_mysql` | Small index, but InnoDB's `innodb_ft_min_token_size` defaults to 3 and MyISAM's `ft_min_word_len` to 4. Both are **server variables**, so on shared hosting you cannot search for "AMG", "V8" or "R7". |
| `fulltext_postgres` | Only on PostgreSQL, and the text search configuration is fixed at index build time. |
| `fulltext_sphinx` | Requires shell access and a separate daemon; Sphinx itself is effectively unmaintained as an open source project. |

None of them offers typo tolerance, none does useful stemming outside a handful
of languages, and none handles a board where users write in two languages.

Meilisearch fixes all of those, and moves the index **out of MySQL entirely**.

---

## Architecture

### The two-stage query model

This is the single most important design decision in the extension, and the one
to understand before modifying anything.

```
User query
    │
    ▼
┌─────────────────────────────────────────────────────────┐
│ Stage 1 — Meilisearch                                   │
│   POST /indexes/<uid>/search                            │
│   filters: forum_id NOT IN [...], topic_id, poster_id,  │
│            post_time, is_first_post                     │
│   returns: up to `meilisearch_max_results` post ids,    │
│            in relevance order                           │
└─────────────────────────────────────────────────────────┘
    │  candidate post ids
    ▼
┌─────────────────────────────────────────────────────────┐
│ Stage 2 — SQL against phpBB's own tables                │
│   SELECT DISTINCT p.post_id ...                         │
│   WHERE p.post_id IN (candidates)                       │
│     AND <$post_visibility>      ← moderation/approval   │
│     AND p.forum_id NOT IN (...) ← forum permissions     │
│     AND <author / topic / date filters>                 │
│   ORDER BY <phpBB sort key>                             │
└─────────────────────────────────────────────────────────┘
    │
    ▼
Final ordered id list → phpBB result cache → search page
```

Stage 2 is not an optimisation, it is the security boundary.

phpBB hands a search backend a raw SQL fragment in `$post_visibility` that
encodes, per forum, which post visibility states the current user may see —
approved only, or also unapproved and soft-deleted where they hold `m_approve`.
It is generated by `phpbb\content_visibility::get_global_visibility_sql()` and it
is not expressible as a Meilisearch filter without reimplementing phpBB's
moderator permission resolution. Reimplementing it would mean that a bug in this
extension leaks posts from private forums or from the moderation queue.

By running phpBB's own `WHERE` clause last, that class of vulnerability is
structurally impossible. The cost is one extra SQL round trip against an indexed
primary key, which is cheap.

### Relevance ordering

phpBB's search UI has no "sort by relevance" option; it sorts by post time,
author, forum or subject. Stage 2 therefore destroys Meilisearch's ranking by
default.

When `meilisearch_relevance` is on **and** the request carries no explicit `sk`
parameter (i.e. the user has not chosen a sort order), the extension re-orders
the surviving ids back into Meilisearch's relevance order in PHP. If the user
does pick a sort order, that choice always wins. For topic-mode results, each
topic inherits the rank of its best-matching post.

### Write path

```
submit_post() / ACP reindex loop
    │
    ▼
meilisearch_backend::index()          buffers post ids
    │
    ├─ normal posting → flush immediately (1 document)
    └─ ACP / CLI      → flush every `meilisearch_batch_size` ids
    │
    ▼
indexer::push()
    │  one batched SELECT to load topic_id, post_time,
    │  post_visibility, is_first_post + s9e markup stripping
    ▼
POST /indexes/<uid>/documents
    │
    └─ on failure → INSERT INTO phpbb_meili_queue (post ids only)
                    → retried by the cron task
```

`index()` deliberately ignores the `$message` and `$subject` arguments phpBB
passes in and re-reads the committed row instead. That guarantees the indexed
document matches what is actually in the database, and it lets us pick up
`topic_id`, `post_time` and `post_visibility`, which phpBB does not pass to
search backends at all.

The retry queue stores **ids only, never content**. A replayed entry always
indexes the current version of the post, so a queue that sits around for a day
cannot resurrect stale text.

### Document schema

| Field | Type | Role |
|---|---|---|
| `post_id` | int | Primary key |
| `topic_id` | int | filterable |
| `forum_id` | int | filterable |
| `poster_id` | int | filterable |
| `post_time` | int | filterable, sortable |
| `post_visibility` | int | filterable (indexed for future use; **not** relied on for permissions) |
| `is_first_post` | 0/1 | filterable — backs `titleonly` and `firstpost` search modes |
| `post_subject` | string | searchable |
| `post_text` | string | searchable, s9e/TextFormatter markup stripped |

`displayedAttributes` is restricted to `post_id`: responses carry ids only, which
keeps them small since the bodies are re-read from MySQL anyway.

---

## Requirements

- phpBB **3.3.x** (developed against 3.3.18-dev, verified byte-identical against
  the released 3.3.17)
- PHP **7.4+** with `curl`, `json` and `mbstring`
- Meilisearch **1.6+**; **1.10+** for `localizedAttributes`, **1.2+** for the
  per-forum index purge
- A machine that can run the Meilisearch daemon, with **GLIBC 2.29 or newer**
- Outbound HTTP(S) from the web server to that machine

There is no Composer dependency. The official `meilisearch-php` SDK pulls in
Guzzle and PSR-18, which conflicts with phpBB's own `vendor/` tree often enough
that a 300-line cURL wrapper is the safer choice.

**Shared hosting alone is not enough.** Meilisearch is a daemon, not a PHP
library: it needs a machine that can keep a process running. If your board is on
shared hosting, either point it at a VPS you control ([Setup
B](#setup-b--meilisearch-on-a-separate-server-hetzner)) or use [Meilisearch
Cloud](#setup-c--meilisearch-cloud), which is paid after a trial period. Read
[Deployment](#deployment) before installing anything.

---


## Installing the extension

Copy the extension so that the tree looks like:

```
phpBB/ext/salvocortesiano/meilisearch/
```

Then **ACP → Customise → Manage extensions → Meilisearch Search Backend →
Enable** (Italian panels: *Personalizzazioni → Gestione estensioni*).

Enabling only creates the config keys, the retry-queue table and the ACP
modules. It does not touch the current search backend and it does not contact
Meilisearch. Setting up the daemon comes next.

---

## Deployment

Meilisearch is a **separate daemon**, not a PHP library. Something has to keep it
running and listening on a port. That single fact decides which of the three
setups below applies to you.

| Your situation | Setup | Cost |
|---|---|---|
| phpBB on a VPS/dedicated server you control | [A — same server](#setup-a--meilisearch-on-the-same-server-as-phpbb) | none |
| phpBB on shared hosting, plus a VPS elsewhere | [B — separate server](#setup-b--meilisearch-on-a-separate-server-hetzner) | none beyond the VPS |
| No server at all | [C — Meilisearch Cloud](#setup-c--meilisearch-cloud) | paid after the trial |

> **Shared hosting alone is not enough.** If your only machine is a shared host,
> Setup C is the only option, and it is not free past the trial period. Check
> this before investing time.

### Checking whether your host can run Meilisearch

Meilisearch is a Rust binary linked against **GLIBC 2.29 or newer**. Plenty of
shared hosts and older CentOS 7 boxes ship 2.17 and the binary simply refuses to
start:

```
./meilisearch: /lib64/libc.so.6: version `GLIBC_2.29' not found
```

Test it before anything else. Over SSH on the host in question:

```bash
ldd --version | head -1
```

Ubuntu 20.04+, Debian 11+, RHEL/Alma/Rocky 9+ are fine. CentOS 7 and Debian 10
are not.

---

## Setup A — Meilisearch on the same server as phpBB

The simplest and most secure arrangement: Meilisearch listens on loopback only,
so nothing outside the machine can reach it, and **no API key is required**.

All commands run over SSH on the server, as root.

### A1. Install the binary

```bash
curl -L https://install.meilisearch.com | sh
mv ./meilisearch /usr/local/bin/
chmod +x /usr/local/bin/meilisearch
meilisearch --version
```

The last command must print a version. If it prints a GLIBC error, stop: this
host cannot run Meilisearch, go to Setup B or C.

### A2. Create a service account and data directory

```bash
useradd -d /var/lib/meilisearch -s /bin/false -M meilisearch
mkdir -p /var/lib/meilisearch
chown -R meilisearch:meilisearch /var/lib/meilisearch
chmod 750 /var/lib/meilisearch
```

Run these as **four separate commands**. Chained with `&&`, a "user already
exists" error aborts the rest and leaves the directory owned by root — which
produces `Permission denied (os error 13)` at startup and is easy to misdiagnose.

### A3. Create the systemd unit

```bash
cat > /etc/systemd/system/meilisearch.service <<'EOF'
[Unit]
Description=Meilisearch
After=network.target

[Service]
Type=simple
User=meilisearch
Group=meilisearch
WorkingDirectory=/var/lib/meilisearch
ExecStart=/usr/local/bin/meilisearch --http-addr 127.0.0.1:7700 --db-path /var/lib/meilisearch --env development
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
```

`WorkingDirectory` is **not optional**. Without it systemd starts the process in
`/root`, which the `meilisearch` user cannot enter, and the service dies with
`Permission denied` — an error that looks like a data-directory problem and is
not.

`--env development` disables the mandatory master key. That is safe **only**
because of `--http-addr 127.0.0.1`, which makes the port unreachable from the
network. Never combine `development` with a public bind address.

### A4. Start and verify

```bash
systemctl daemon-reload
systemctl enable --now meilisearch
systemctl status meilisearch --no-pager
curl http://127.0.0.1:7700/health
```

Expected: `{"status":"available"}`.

If the service fails, the useful output is not `systemctl status` but:

```bash
journalctl -u meilisearch -n 30 --no-pager
```

### A5. Configure phpBB

**ACP → General → Server configuration → Search settings**
(Italian panels: *Generale → Configurazione server → Motore di ricerca*)

| Field | Value |
|---|---|
| Meilisearch URL | `http://127.0.0.1:7700` |
| API key | *(leave empty)* |
| Index name | `phpbb_posts` |
| Content languages | `ita` or `ita,eng` |

Submit, but **leave the search backend selector on the native backend for now.**

Then go to [Finishing up](#finishing-up-all-setups).

---

## Setup B — Meilisearch on a separate server (Hetzner)

For a board on shared hosting when you also have a VPS. Meilisearch runs on the
VPS; phpBB reaches it over HTTPS. This requires TLS, an API key and a firewall,
because post content and credentials now cross the public internet.

Target layout:

```
shared host (phpBB)  ──HTTPS──►  search.example.com  ──►  VPS
                                                          Nginx or Caddy
                                                          Meilisearch on 127.0.0.1:7700
```

### B0. Connect to the VPS from Windows

The browser-based console on most providers **cannot paste**. Use PowerShell,
where right-click pastes:

```powershell
ssh root@YOUR_SERVER_IP
```

On Hetzner, the root password arrives by email when the server is created. If it
was created with an SSH key, no password is asked. If you lost the password:
Hetzner Console → select the server → the **Rescue** tab → *Reset root password*.
It is not under "Actions". Note that the reset reboots the server.

### B1. Install Meilisearch

Follow **A1 to A4 unchanged**, but in A3 replace the `ExecStart` line with a
master key and production mode:

```bash
# generate a key and store it where only root and the service can read it
echo "MEILI_MASTER_KEY=$(openssl rand -base64 48 | tr -d '\n')" > /etc/meilisearch.env
chown root:meilisearch /etc/meilisearch.env
chmod 640 /etc/meilisearch.env
```

`chmod 600` would lock out the service account, since the unit runs as
`meilisearch`, not root.

Then the unit becomes:

```bash
cat > /etc/systemd/system/meilisearch.service <<'EOF'
[Unit]
Description=Meilisearch
After=network.target

[Service]
Type=simple
User=meilisearch
Group=meilisearch
WorkingDirectory=/var/lib/meilisearch
EnvironmentFile=/etc/meilisearch.env
ExecStart=/usr/local/bin/meilisearch --http-addr 127.0.0.1:7700 --db-path /var/lib/meilisearch --env production
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now meilisearch
curl http://127.0.0.1:7700/health
```

Meilisearch still binds to loopback. The web server in front of it is what will
be exposed.

### B2. Point a subdomain at the VPS

In your DNS panel create an **A record**:

| Type | Name | Value |
|---|---|---|
| A | `search` | your VPS IPv4 |

Verify from the VPS before continuing:

```bash
dig +short search.example.com
```

It must print the VPS IP. **Do not proceed until it does**: Let's Encrypt rate
limits failed attempts, and you will lock yourself out for an hour.

### B3. Check what already listens on port 80

```bash
ss -tlnp | grep -E ':80 |:443 '
```

The answer decides the next step. A VPS that already runs something usually has
Nginx or Apache on port 80, and installing a second web server will simply fail
with `bind: address already in use`.

#### B3a. Nginx is already installed

Use it as a reverse proxy rather than adding Caddy:

```bash
apt install -y certbot python3-certbot-nginx

cat > /etc/nginx/sites-available/meilisearch <<'EOF'
server {
    listen 80;
    server_name search.example.com;

    location / {
        proxy_pass http://127.0.0.1:7700;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 300s;
        client_max_body_size 100M;
    }
}
EOF

ln -sf /etc/nginx/sites-available/meilisearch /etc/nginx/sites-enabled/meilisearch
nginx -t
```

`nginx -t` must report `test is successful`. If it does not, **stop and fix it**
— do not reload, or you will take down whatever else the server hosts.

```bash
systemctl reload nginx
certbot --nginx -d search.example.com --agree-tos --no-eff-email -m you@example.com --redirect
curl https://search.example.com/health
```

`proxy_read_timeout 300s` matters: a full reindex sends large batches and the
default 60s can cut them off.

#### B3b. Nothing is on port 80

Caddy handles certificates on its own:

```bash
apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt update && apt install -y caddy

cat > /etc/caddy/Caddyfile <<'EOF'
search.example.com {
	reverse_proxy 127.0.0.1:7700
}
EOF

systemctl enable --now caddy
curl https://search.example.com/health
```

Use `systemctl enable --now` on the first run, not `reload`: reloading a service
that has never started fails with `caddy.service is not active, cannot reload`.

Note that `curl -I` sends a HEAD request and Meilisearch answers `405 Not
Allowed` on `/health`. That is not an error — it proves Meilisearch is replying
through the proxy. Use a plain `curl` for a real check.

### B4. Configure phpBB and generate the key

**ACP → General → Server configuration → Search settings**

| Field | Value |
|---|---|
| Meilisearch URL | `https://search.example.com` |
| API key | *(leave empty for now)* |
| Index name | `phpbb_posts` |
| Content languages | `ita` or `ita,eng` |

Submit, leaving the backend on native.

Read the master key on the VPS:

```bash
cat /etc/meilisearch.env
```

Copy only the part after `MEILI_MASTER_KEY=`.

Then **ACP → Extensions → Meilisearch → Meilisearch diagnostics**, section
*Generate a new API key*: paste the master key and press the button. The
extension calls `POST /keys`, stores the returned key itself, and never persists
the master key.

The generated key is restricted to this board's index and to these actions:

```
search, documents.add, documents.delete, indexes.get, indexes.create,
settings.get, settings.update, stats.get, tasks.get
```

Global actions such as `version` and `dumps.create` are deliberately absent:
Meilisearch rejects an index-scoped key that carries any global action
(`index_scoped_api_key_with_global_action`). The practical consequence is that
the diagnostics page cannot display the server version when using a generated
key. That is a cosmetic loss and not worth a global key.

Press **Run key test** to confirm every required operation succeeds.

### B5. Restrict access with a firewall

At this point `search.example.com` answers anyone on the internet. The API key
protects the data, but the surface is larger than it needs to be.

First find your web host's outbound IP. Over SSH **on the shared host**, not the
VPS:

```bash
curl -s https://api.ipify.org; echo
```

Then, in the Hetzner Console → **Firewalls** → *Create Firewall*, allow inbound:

| Protocol | Port | Source |
|---|---|---|
| TCP | 443 | web host IP `/32` |
| TCP | 80 | `0.0.0.0/0` and `::/0` |
| TCP | 22 | your own IP `/32` |

Three warnings, all of which have bitten people:

- **Port 80 must stay open to everyone**, or Certbot cannot renew and HTTPS
  expires in 90 days.
- **Port 22 must stay reachable by you**, or you lock yourself out. The provider
  console bypasses the firewall and is your way back in.
- **Check what else the VPS runs** (`ss -tlnp`) before applying. A firewall that
  only opens 22/80/443 will break any other service on that machine.

If your web host's outbound IP is dynamic — common on shared hosting — skip the
443 restriction. A broken search is worse than a slightly larger attack surface,
and the API key is genuine protection on its own.

Now go to [Finishing up](#finishing-up-all-setups).

---

## Setup C — Meilisearch Cloud

No server required, but **not free**: the plan starts with a 14-day trial and
becomes paid afterwards. Confirm current pricing at
[cloud.meilisearch.com](https://cloud.meilisearch.com) before committing.

1. Register and create a project, choosing the region nearest your board.
2. Wait for the project status to become *running*.
3. Copy the project URL, e.g. `ms-abc123def456.par.meilisearch.io`.
4. Open **View API keys** and copy the **Default Admin API Key** — not the
   Default Search API Key, which cannot write.

Do **not** create an index in the Cloud dashboard. The extension creates it with
the correct filterable, sortable and searchable attributes; an index created by
hand is empty and misconfigured.

Then in **ACP → General → Server configuration → Search settings**:

| Field | Value |
|---|---|
| Meilisearch URL | `https://ms-abc123def456.par.meilisearch.io` (with `https://`, no trailing slash) |
| API key | the Default Admin API Key |
| Index name | `phpbb_posts` |
| Content languages | `ita` or `ita,eng` |

The key generator in the diagnostics page is unnecessary here: Cloud already
issues a usable key.

Note that with Cloud, post content is processed by a third party. Say so in your
privacy policy — see [Security model](#security-model).

---

## Finishing up (all setups)

The order matters. Following it means the board's search never breaks, not even
for a minute.

### 1. Verify the connection

**ACP → Extensions → Meilisearch → Meilisearch diagnostics.**

Status must read **Reachable**. Press **Run connection test** for the round-trip
time. Anything under ~50 ms on a local socket, or under ~200 ms over the
internet, is healthy.

### 2. Create the index

Press **Create index and apply settings**. This creates the index and writes the
attribute configuration. Expected result: *The index exists and its settings have
been applied.*

### 3. Choose which forums to index

**ACP → Extensions → Meilisearch → Indexed forums.**

On installation the exclusion list is pre-filled with every forum guests cannot
read. Review it and submit. See
[Choosing which forums to index](#choosing-which-forums-to-index) for what the
setting does and does not do.

### 4. Build the index — with the old backend still active

**ACP → Maintenance → Search index** (Italian: *Manutenzione → Indice ricerca*),
row **Meilisearch**, press **Create index**.

phpBB walks the posts table in batches with a progress bar, resuming after
timeouts. Throughput is typically 1,500–4,000 posts/second.

**Do not switch the search backend yet.** phpBB can build an index for a backend
that is not active, so the board keeps searching with the native backend
throughout.

### 5. Confirm the index is populated

Back on the diagnostics page, *Indexed posts* must show a number close to your
board's post count. Press **Run index test**: it performs a real query and
reports matches and response time.

### 6. Switch the backend

**ACP → General → Server configuration → Search settings** → *Search backend* →
**Meilisearch** → Submit.

phpBB asks for confirmation and calls `init()`, which re-checks reachability. If
anything fails the switch is aborted and the board stays where it was.

### 7. Test as a real user

Log in as an ordinary member and search for:

- a **two or three letter term** that exists in posts, such as `V8` or `SSD`.
  MySQL fulltext discards these; if you get results, Meilisearch is answering.
- a **word with a typo**. Results mean typo tolerance is working.
- a term you know appears **only in a private forum**. You must get nothing.
  This is the permission check and it is the most important test on this page.

### 8. Optional — reclaim the old index

Once satisfied, **ACP → Maintenance → Search index** → *phpBB Native Fulltext* →
**Delete index**. This frees the `search_wordlist` and `search_wordmatch` tables,
often the largest tables in the database.

Do this **last**. Until you delete it, the native index is your instant rollback.

### 9. Set up cron

The retry queue is drained by a cron task. phpBB's web cron works, but a real
system cron is better:

```
*/5 * * * * /usr/bin/php /path/to/phpBB/bin/phpbbcli.php cron:run --quiet
```


## Configuration reference

All options live in **ACP → General → Board configuration → Search settings**,
rendered by `meilisearch_backend::acp()`. They are stored in `phpbb_config`.

| Key | Default | Notes |
|---|---|---|
| `meilisearch_url` | `http://127.0.0.1:7700` | No trailing slash. |
| `meilisearch_api_key` | *(empty)* | Master key, or a key with `search`, `documents.*`, `indexes.*`, `settings.*`, `stats.get`, `tasks.get`. |
| `meilisearch_index` | `phpbb_posts` | Use a distinct name per board if several share one instance. |
| `meilisearch_locales` | *(empty)* | ISO 639 codes, e.g. `ita,eng`. Empty = per-document auto-detection. |
| `meilisearch_timeout` | `5` | Seconds. Keep low; a slow engine must not stall page rendering. |
| `meilisearch_max_results` | `1000` | Candidate cap. Also written to Meilisearch as `pagination.maxTotalHits`. |
| `meilisearch_batch_size` | `250` | Documents per HTTP request during a reindex. |
| `meilisearch_min_chars` | `2` | Minimum term length. No engine-imposed floor, unlike MySQL. |
| `meilisearch_max_chars` | `100` | Maximum term length. |
| `meilisearch_typo` | `1` | Typo tolerance. **Changing this requires re-applying settings** from the diagnostics page. |
| `meilisearch_relevance` | `1` | Relevance ordering when the user has not chosen a sort. |
| `meilisearch_queue_enable` | `1` | Retry queue. Leave on. |
| `meilisearch_excluded_forums` | *(guest-unreadable forums)* | Comma-separated forum ids. Edited in **Extensions → Meilisearch → Indexed forums**, not here. |
| `meilisearch_queue_gc` | `300` | Cron interval in seconds. No ACP field; edit via CLI or the config table. |

### A note on languages

Two distinct things are often confused:

- **Interface language** — the ACP strings. `language/en/` and `language/it/` are
  both complete.
- **Content tokenisation** — how Meilisearch segments and normalises post text.
  This is `meilisearch_locales`.

Meilisearch auto-detects language per document, which is unreliable on short
posts. Pinning `ita,eng` on a mixed Italian/English board measurably improves
recall. Unlike SQLite FTS5 (whose only stemmer is English-only Porter),
Meilisearch handles Italian morphology properly.

---

## Building the index

**ACP → Maintenance → Search index → Meilisearch → Create index.**

The extension deliberately does **not** implement `create_index()`. When that
method is absent, `acp_search` runs its own batched loop: it walks the posts
table in ascending `post_id` order, calls `index()` per post, respects
`still_on_time()`, meta-refreshes, draws a progress bar and resumes exactly where
it stopped. Reimplementing that would be strictly worse.

`delete_index()` **is** implemented, because Meilisearch truncates an index with a
single `DELETE /indexes/<uid>/documents` call — no reason to walk every post.

Rough figures on a 4-core VPS with Meilisearch on the same host: **1,500–4,000
posts/second**, dominated by MySQL reads and s9e markup stripping rather than by
Meilisearch. A 500,000-post board takes a few minutes.

Forums with *Enable search indexing* set to No in forum settings are skipped by
phpBB's loop, exactly as with the native backend.

### Choosing which forums to index

**ACP → Extensions → Meilisearch → Indexed forums.**

A checkbox per forum marks it as *excluded*. Excluded forums are filtered out in
`indexer::build_documents()`, so their posts never reach Meilisearch — not during
a full reindex, not on posting, not through the retry queue.

On installation the list is pre-populated by `m2_forum_exclusions` with every
forum the guest account cannot read. That is a conservative starting point, not a
policy: whatever you save is authoritative from then on. The *Preselect forums
guests cannot read* button reloads that suggestion into the form without saving,
so you can review it before applying.

Two things to know:

- **Changing the list does not retro-actively clean the index.** Posts indexed
  under the old list stay there. Press *Remove excluded forums from the index*
  afterwards; it issues a single delete-by-filter call
  (`forum_id IN [...]`, Meilisearch 1.2+) rather than walking the posts table.
  Moving a forum the other way — from excluded to indexed — needs a reindex,
  since its posts were never sent.
- **Excluding a forum removes it from search for everyone**, including the
  moderators who can read it. If your staff use a private forum as a working
  archive, leave it indexed and rely on the SQL permission stage instead.

### Reindexing safely on a live board

Meilisearch upserts on `post_id`, so re-running "Create index" over an existing
index is non-destructive: documents are replaced in place, and search keeps
working throughout. You do **not** need to delete the index first unless the
document schema changed.

---

## Operating it

### Cron

The task `salvocortesiano.meilisearch.cron.flush_queue` runs every
`meilisearch_queue_gc` seconds and drains the retry queue. It is a no-op (one
`COUNT` query) on a healthy board.

phpBB's default web cron is fine, but a real system cron is better:

```
*/5 * * * * /usr/bin/php /path/to/phpBB/bin/phpbbcli.php cron:run --quiet
```

### Monitoring

The diagnostics page shows reachability, server version, document count, whether
Meilisearch is currently processing, and the queue depth.

**The one number to watch is the queue depth.** A steadily growing queue means
Meilisearch has been unreachable for a while and your index is drifting out of
sync. Once it is reachable again the queue drains by itself.

`ACP → Maintenance → Search index` also shows indexed posts, processing state and
pending retries via `index_stats()`.

### What happens when Meilisearch goes down

- Posting, editing, viewing: unaffected.
- New and edited posts: ids land in `phpbb_meili_queue`, replayed by cron.
- Searching: returns no results, and an entry is written to the phpBB error log.
  It does not throw a fatal error at the user.

### Rolling back

Switch the backend to phpBB Native Fulltext in the ACP. The native tables are
untouched by this extension, so if a native index was previously built, it is
still there and search resumes immediately.

---

## Security model

**Read this before exposing anything.**

1. **Meilisearch has no per-user access control.** Anyone who can reach the HTTP
   API can read every indexed post, including private forums. The protection is
   the network boundary, not the key: bind it to `127.0.0.1`, or put it on a
   private segment, or firewall it to the web server's address. Never expose it
   publicly. Running without a key (Option A) is safe precisely because nothing
   off-host can connect; running with a key on an exposed port is not a
   substitute for that boundary.

2. **The API key is stored in plain text** in `phpbb_config`, like every other
   phpBB config value. Any founder with ACP access and anyone with database read
   access can retrieve it. This is why the extension generates a scoped key for
   you rather than asking for the master key: the stored credential can search
   and index this board, and nothing else. The master key is only ever held in a
   POST body for the duration of one request and is never written to the
   database.

3. **Permission enforcement never leaves phpBB.** Meilisearch is asked only which
   post ids match the words. Forum permissions and moderation visibility are
   applied afterwards in SQL. This is intentional and should not be "optimised"
   by pushing `post_visibility` into the Meilisearch filter — see
   [Architecture](#architecture). No user has ever been able to find a post they
   are not allowed to read, regardless of the exclusion list below.

4. **Forum exclusions are defence in depth, not the permission check.** The
   *Indexed forums* screen decides what is allowed to leave the database in the
   first place. Excluding a forum means its posts are never written to
   Meilisearch, so a compromised or misconfigured instance cannot reveal them.
   On installation the list is pre-filled with every forum guests cannot read.
   **The cost is real: members who legitimately have access to an excluded forum
   cannot find its posts through search either.** See
   [Choosing which forums to index](#choosing-which-forums-to-index).

5. **Post content leaves the database.** Under GDPR the Meilisearch instance is
   a processing location for user-generated content. If it is hosted by a third
   party (Meilisearch Cloud), say so in your privacy policy.

6. **Private messages are not indexed.** phpBB does not route PMs through the
   search backend, and this extension does not add them.

---

## Limitations and trade-offs

Known and accepted, listed so nobody has to discover them the hard way:

- **Result counts are capped.** Only the top `meilisearch_max_results` hits reach
  stage 2. On a very broad query ("the") the reported total is an undercount.
  Raise the cap if this matters; the ceiling is the size of the SQL `IN()` clause.

- **phpBB's boolean operators are approximated.** Meilisearch does not implement
  `+word`, `|`, or nested groups. `"exact phrase"` and `-exclusion` are passed
  through natively; `+` and `|` are stripped, since Meilisearch's ranking already
  places documents matching more terms higher. This is a behaviour change from
  the native backend and should be mentioned in your board's search help.

- **Relevance ordering is lost when the user picks a sort.** By design.

- **Author-only search never touches Meilisearch.** With no keywords there is
  nothing to match, so `author_search()` is plain SQL, behaviourally identical to
  `fulltext_mysql`.

- **Attachment filenames and poll options are not indexed.** Neither are they in
  core phpBB.

- **Guest posts by `post_username` are handled in SQL only.** When an author
  search includes a guest name, the poster filter is not pushed down to
  Meilisearch; stage 2 covers it.

- **`phpbb_search_results` is still used.** Result-set caching remains phpBB's,
  invalidated once per indexing batch rather than once per post — indexing
  500,000 posts with per-post cache invalidation is ruinous.

---

## Troubleshooting

**"The Meilisearch extension is selected as the search backend but is not enabled."**
The extension was disabled while it was the active backend. phpBB's ext class
loader still resolves the class from disk, but the DI services are gone. Re-enable
the extension, or change `search_type` back:

```sql
UPDATE phpbb_config SET config_value = '\\phpbb\\search\\fulltext_native'
WHERE config_name = 'search_type';
```

**"Meilisearch API error [index_not_found]"**
Press *Create index and apply settings* on the diagnostics page.

**"Attribute `X` is not filterable"**
The index settings are stale — this happens if the index was created by an older
version of the extension. Re-apply settings from the diagnostics page.

**Searches return nothing, index shows documents**
Almost always a permission mismatch in stage 2, or a `filter` rejected by
Meilisearch. Enable phpBB's debug mode and check the error log; a rejected filter
is logged as `LOG_MEILISEARCH_ERROR`.

**Searches return nothing, index shows 0 documents**
The index was never built. ACP → Maintenance → Search index → Create index.

**Queue grows and never drains**
Cron is not running, or Meilisearch is still unreachable. Run
`php bin/phpbbcli.php cron:run` manually and watch the diagnostics page.

**`Permission denied (os error 13)` at Meilisearch startup**
Either the data directory is not owned by the service account, or the unit has
no `WorkingDirectory` and systemd starts the process in `/root`. Check both:

```bash
ls -ld /var/lib/meilisearch /etc/meilisearch.env
grep WorkingDirectory /etc/systemd/system/meilisearch.service
```

**`status=203/EXEC`**
The binary is not at the path in `ExecStart`. Re-run the install step and check
`meilisearch --version`.

**`bind: address already in use` when starting Caddy**
Something already holds port 80. Run `ss -tlnf | grep ':80 '` and use the
existing web server as a reverse proxy instead — see
[B3a](#b3a-nginx-is-already-installed).

**`index_scoped_api_key_with_global_action` when generating a key**
A global action was requested for an index-scoped key. Update to 1.2.2 or later,
where `version` was removed from the requested action list.

**`missing_authorization_header`**
The instance requires a master key but the API key field is empty. Generate a
key from the diagnostics page before pressing *Create index and apply settings*.

**The front-end notice does not appear on the results page**
Your style ships its own `search_results.html` without the
`search_results_header_before` event. Since 1.4.0 the extension detects this and
falls back to injecting from `overall_header_content_before`; make sure you are
on 1.4.0 or later and that the phpBB cache has been purged.

**`localizedAttributes` errors on older Meilisearch**
Handled automatically: `indexer::apply_settings()` retries without that key.
Upgrade to 1.10+ or leave *Content languages* empty.

---

## Uninstalling

1. **First** switch the search backend away from Meilisearch and rebuild the
   native index, otherwise the board is left without working search.
2. ACP → Manage extensions → Disable, then Delete data.

`revert_data()` includes a safety net: if Meilisearch is still the active backend
when data is deleted, `search_type` is forced back to `fulltext_native`.

Deleting the extension does **not** delete the Meilisearch index. Remove it
manually if you no longer need it:

```bash
curl -X DELETE -H "Authorization: Bearer <key>" http://127.0.0.1:7700/indexes/phpbb_posts
```

---

## Developer notes

### How phpBB finds this backend

There is no service registration involved. `includes/acp/acp_search.php::get_search_types()`
runs the extension finder:

```php
$finder->extension_suffix('_backend')
       ->extension_directory('/search')
       ->core_path('phpbb/search/')
       ->get_classes();
```

So **any** class in `ext/<vendor>/<ext>/search/` whose file name ends in
`_backend.php` is offered in the ACP selector. The class is then instantiated
directly:

```php
$search = new $search_type($error, $phpbb_root_path, $phpEx, $auth,
                           $config, $db, $user, $phpbb_dispatcher);
```

That positional signature is fixed and cannot be changed. Because search backends
are not DI services, heavier dependencies are pulled from `$phpbb_container` by
hand in the constructor, guarded by a `try`/`catch` so a disabled extension
produces a clean error rather than a fatal.

### File map

```
salvocortesiano/meilisearch/
├── composer.json
├── ext.php                              PHP / cURL / JSON pre-flight checks
├── config/services.yml                  client, indexer, cron task
├── search/
│   └── meilisearch_backend.php          the backend phpBB discovers
├── meili/
│   ├── client.php                       cURL wrapper, never throws
│   └── indexer.php                      documents, batching, retry queue
├── cron/task/flush_queue.php
├── acp/
│   ├── main_info.php
│   └── main_module.php                  diagnostics + indexed forums
├── adm/style/
│   ├── acp_meilisearch.html
│   └── acp_meilisearch_forums.html
├── event/listener.php                   front-end search notice
├── styles/all/template/                 notice markup + template events
├── migrations/v10x/
│   ├── m1_initial_schema.php
│   ├── m2_forum_exclusions.php
│   └── m3_search_banner.php
└── language/{en,it}/
```

### Backend methods and who calls them

| Method | Caller |
|---|---|
| `get_name()` | ACP selector label |
| `init()` | ACP on backend switch, and by `delete_index()` |
| `split_keywords()` | `search.php` before every keyword search |
| `keyword_search()` | `search.php` |
| `author_search()` | `search.php` |
| `index()` | `submit_post()`, and the ACP reindex loop |
| `index_remove()` | post deletion, `functions_admin.php` |
| `tidy()` | cron, and after every ACP indexing batch — our flush point |
| `delete_index()` | ACP "Delete index" |
| `index_created()`, `index_stats()` | ACP search index page |
| `acp()` | ACP search settings, returns `['tpl' => ..., 'config' => ...]` |

`create_index()` is intentionally absent, so `acp_search` provides the batched
resumable loop.

### Events

| Event | Purpose |
|---|---|
| `salvocortesiano.meilisearch.modify_search_key` | Alter the result-cache key |
| `salvocortesiano.meilisearch.refine_query_before` | Alter the stage-2 SQL |

### Renaming the vendor

Namespace, directory names, service ids, the ACP `auth` string
(`ext_salvocortesiano/meilisearch`), the form key and `composer.json` must all
agree. To rename:

```bash
grep -rl 'salvocortesiano' . | xargs sed -i 's/salvocortesiano/newvendor/g'
cd .. && mv salvocortesiano newvendor
```

Then purge the phpBB cache.

---

## phpBB 4.0

**This extension does not work on phpBB 4.0 as-is.** 4.0 refactored search into
`phpbb\search\backend\` with a real `search_backend_interface`, renamed `acp()`
to `get_acp_data()`, and changed the `create_index()` / `delete_index()`
contract. The two-stage query model and everything in `meili/` carry over
unchanged; the port is confined to `search/meilisearch_backend.php`.

---

## License

GPL-2.0-only, matching phpBB.

## Status

Version 1.5.0. Running in production on a phpBB 3.3.17 board with ~34,000 indexed
posts, against Meilisearch 1.53 behind Nginx and Let's Encrypt.

All PHP files pass `php -l` on PHP 8.3; the DI wiring, namespaces and template
variables are checked automatically at build time; the language files are
key-complete and aligned in English and Italian.

Issues and pull requests are welcome. When reporting a problem, please include
the phpBB version, the Meilisearch version, which of the three setups you use,
and the output of the three test buttons on the diagnostics page.

# Meilisearch Search Backend per phpBB 3.3

Un rimpiazzo diretto della ricerca fulltext integrata di phpBB. Registra un
nuovo **backend di ricerca** che delega la corrispondenza delle parole chiave a
un'istanza [Meilisearch](https://www.meilisearch.com/), mantenendo ogni
controllo dei permessi dentro l'SQL di phpBB.

Nell'interfaccia utente non cambia nulla. Nessuna seconda barra di ricerca,
nessun template nuovo, nessun `search.php` modificato. L'amministratore cambia
il backend nel PCA e la pagina di ricerca esistente, la ricerca rapida
nell'intestazione, "Cerca in questo argomento" e la ricerca per autore iniziano
tutte a usare Meilisearch.

**Cosa ottieni rispetto al backend nativo**

- Tolleranza agli errori di battitura — `carburatre` trova `carburatore`
- Parole di qualsiasi lunghezza — `V8`, `SSD` e `R7` diventano cercabili
- Tokenizzazione multilingua corretta, anche su forum con lingue miste
- Normalizzazione degli accenti — `perché` trova `perche`
- L'indice vive fuori da MySQL: `search_wordlist` e `search_wordmatch`
  spariscono dal database
- Ordinamento per rilevanza, controllo dell'indicizzazione per forum e una
  pagina di diagnostica nel PCA

## Indice

1. [Perché](#perché)
2. [Architettura](#architettura)
3. [Requisiti](#requisiti)
4. [Installazione dell'estensione](#installazione-dellestensione)
5. [Messa in opera](#messa-in-opera)
   - [Scenario A — stesso server](#scenario-a--meilisearch-sullo-stesso-server-di-phpbb)
   - [Scenario B — server separato (Hetzner)](#scenario-b--meilisearch-su-un-server-separato-hetzner)
   - [Scenario C — Meilisearch Cloud](#scenario-c--meilisearch-cloud)
   - [Completamento](#completamento-tutti-gli-scenari)
6. [Riferimento delle opzioni](#riferimento-delle-opzioni)
7. [Costruzione dell'indice](#costruzione-dellindice)
8. [Gestione quotidiana](#gestione-quotidiana)
9. [Modello di sicurezza](#modello-di-sicurezza)
10. [Limiti e compromessi](#limiti-e-compromessi)
11. [Risoluzione dei problemi](#risoluzione-dei-problemi)
12. [Disinstallazione](#disinstallazione)
13. [Note per sviluppatori](#note-per-sviluppatori)
14. [phpBB 4.0](#phpbb-40)
15. [Licenza](#licenza)

---

## Perché

phpBB 3.3 offre quattro backend di ricerca e ognuno impone un compromesso
sgradevole:

| Backend | Problema |
|---|---|
| `fulltext_native` | La rilevanza migliore dei quattro, ma le tabelle `search_wordlist` e `search_wordmatch` arrivano comunemente al **doppio della dimensione della tabella dei post**. Sui forum grandi l'indice domina il database. |
| `fulltext_mysql` | Indice piccolo, ma `innodb_ft_min_token_size` vale 3 di default e `ft_min_word_len` di MyISAM vale 4. Sono **variabili del server**, quindi su hosting condiviso non puoi cercare "AMG", "V8" o "R7". |
| `fulltext_postgres` | Solo su PostgreSQL, e la configurazione di ricerca testuale è fissata al momento della costruzione dell'indice. |
| `fulltext_sphinx` | Richiede accesso shell e un demone separato; Sphinx come progetto open source è di fatto abbandonato. |

Nessuno offre tolleranza agli errori di battitura, nessuno fa stemming utile
oltre a una manciata di lingue, e nessuno gestisce un forum dove si scrive in
due lingue.

Meilisearch risolve tutto questo, e sposta l'indice **completamente fuori da
MySQL**.

---

## Architettura

### Il modello di query a due stadi

È la decisione di progetto più importante dell'estensione, e la prima da capire
prima di modificare qualsiasi cosa.

```
Query dell'utente
    │
    ▼
┌─────────────────────────────────────────────────────────┐
│ Stadio 1 — Meilisearch                                  │
│   POST /indexes/<uid>/search                            │
│   filtri: forum_id NOT IN [...], topic_id, poster_id,   │
│           post_time, is_first_post                      │
│   restituisce: fino a `meilisearch_max_results` post id │
│               in ordine di rilevanza                    │
└─────────────────────────────────────────────────────────┘
    │  post id candidati
    ▼
┌─────────────────────────────────────────────────────────┐
│ Stadio 2 — SQL sulle tabelle di phpBB                   │
│   SELECT DISTINCT p.post_id ...                         │
│   WHERE p.post_id IN (candidati)                        │
│     AND <$post_visibility>      ← moderazione           │
│     AND p.forum_id NOT IN (...) ← permessi dei forum    │
│     AND <filtri autore / argomento / data>              │
│   ORDER BY <chiave di ordinamento phpBB>                │
└─────────────────────────────────────────────────────────┘
    │
    ▼
Elenco finale ordinato → cache risultati phpBB → pagina di ricerca
```

Lo stadio 2 non è un'ottimizzazione: **è il confine di sicurezza**.

phpBB passa ai backend di ricerca un frammento SQL grezzo in `$post_visibility`
che codifica, forum per forum, quali stati di visibilità l'utente corrente può
vedere — solo approvati, oppure anche non approvati e cancellati in modo
reversibile dove ha il permesso `m_approve`. È generato da
`phpbb\content_visibility::get_global_visibility_sql()` e non è esprimibile come
filtro Meilisearch senza reimplementare la risoluzione dei permessi di
moderazione di phpBB. Reimplementarla significherebbe che un bug in questa
estensione fa trapelare messaggi da forum privati o dalla coda di moderazione.

Facendo eseguire per ultima la clausola `WHERE` di phpBB, quella classe di
vulnerabilità diventa strutturalmente impossibile. Il costo è un giro SQL in più
su una chiave primaria indicizzata, che è trascurabile.

### Ordinamento per rilevanza

L'interfaccia di ricerca di phpBB non ha una voce "ordina per rilevanza": ordina
per data, autore, forum o titolo. Lo stadio 2 quindi distruggerebbe il ranking
di Meilisearch.

Quando `meilisearch_relevance` è attivo **e** la richiesta non porta un parametro
`sk` esplicito (cioè l'utente non ha scelto un ordinamento), l'estensione
ricostruisce l'elenco in ordine di rilevanza in PHP. Se invece l'utente sceglie
un ordinamento, la sua scelta vince sempre. In modalità argomenti, ogni argomento
eredita il rango del suo messaggio più pertinente.

### Percorso di scrittura

```
submit_post() / ciclo di reindicizzazione del PCA
    │
    ▼
meilisearch_backend::index()          accumula i post id
    │
    ├─ pubblicazione normale → invio immediato (1 documento)
    └─ PCA / CLI             → invio ogni `meilisearch_batch_size` id
    │
    ▼
indexer::push()
    │  una SELECT a blocchi per topic_id, post_time,
    │  post_visibility, is_first_post + pulizia del markup s9e
    ▼
POST /indexes/<uid>/documents
    │
    └─ in caso di errore → INSERT INTO phpbb_meili_queue (solo post id)
                           → ritentato dal cron
```

`index()` ignora deliberatamente gli argomenti `$message` e `$subject` che phpBB
passa, e rilegge invece la riga già scritta nel database. Questo garantisce che
il documento indicizzato corrisponda a ciò che è realmente nel database, e
permette di recuperare `topic_id`, `post_time` e `post_visibility`, che phpBB non
passa affatto ai backend di ricerca.

La coda di ripetizione memorizza **solo id, mai contenuti**. Una voce riprocessata
indicizza sempre la versione attuale del messaggio, quindi una coda rimasta ferma
per un giorno non può resuscitare testo obsoleto.

### Schema del documento

| Campo | Tipo | Ruolo |
|---|---|---|
| `post_id` | int | chiave primaria |
| `topic_id` | int | filtrabile |
| `forum_id` | int | filtrabile |
| `poster_id` | int | filtrabile |
| `post_time` | int | filtrabile, ordinabile |
| `post_visibility` | int | filtrabile (indicizzato per usi futuri; **non** usato per i permessi) |
| `is_first_post` | 0/1 | filtrabile — serve alle modalità `titleonly` e `firstpost` |
| `post_subject` | string | ricercabile |
| `post_text` | string | ricercabile, markup s9e/TextFormatter rimosso |

`displayedAttributes` è limitato a `post_id`: le risposte contengono solo id,
il che le mantiene piccole visto che i corpi vengono comunque riletti da MySQL.

---

## Requisiti

- phpBB **3.3.x** (sviluppata su 3.3.18-dev, verificata identica byte per byte
  sulla 3.3.17 rilasciata)
- PHP **7.4+** con `curl`, `json` e `mbstring`
- Meilisearch **1.6+**; **1.10+** per `localizedAttributes`, **1.2+** per la
  rimozione dall'indice dei forum esclusi
- Una macchina in grado di eseguire il demone Meilisearch, con **GLIBC 2.29 o
  superiore**
- Connessioni HTTP(S) in uscita dal web server verso quella macchina

Non c'è nessuna dipendenza Composer. L'SDK ufficiale `meilisearch-php` porta con
sé Guzzle e PSR-18, che entrano in conflitto con l'albero `vendor/` di phpBB
abbastanza spesso da rendere un wrapper cURL di 300 righe la scelta più sicura.

**L'hosting condiviso da solo non basta.** Meilisearch è un demone, non una
libreria PHP: serve una macchina che possa tenere vivo un processo. Se il forum
è su hosting condiviso, puntalo a un VPS che controlli
([Scenario B](#scenario-b--meilisearch-su-un-server-separato-hetzner)) oppure usa
[Meilisearch Cloud](#scenario-c--meilisearch-cloud), che è a pagamento dopo un
periodo di prova. Leggi [Messa in opera](#messa-in-opera) prima di installare
qualsiasi cosa.

---

## Installazione dell'estensione

Copia l'estensione in modo che l'albero risulti:

```
phpBB/ext/salvocortesiano/meilisearch/
```

Poi **PCA → Personalizzazioni → Gestione estensioni → Meilisearch Search Backend
→ Abilita**.

L'abilitazione crea soltanto le chiavi di configurazione, la tabella della coda
di ripetizione e i moduli del PCA. Non tocca il backend di ricerca attuale e non
contatta Meilisearch. La messa in opera del demone viene dopo.

---

## Messa in opera

Meilisearch è un **demone separato**, non una libreria PHP. Qualcosa deve tenerlo
acceso e in ascolto su una porta. Questo singolo fatto decide quale dei tre
scenari ti riguarda.

| La tua situazione | Scenario | Costo |
|---|---|---|
| phpBB su un VPS o dedicato che controlli | [A — stesso server](#scenario-a--meilisearch-sullo-stesso-server-di-phpbb) | nessuno |
| phpBB su hosting condiviso, più un VPS altrove | [B — server separato](#scenario-b--meilisearch-su-un-server-separato-hetzner) | nessuno oltre al VPS |
| Nessun server | [C — Meilisearch Cloud](#scenario-c--meilisearch-cloud) | a pagamento dopo la prova |

> **L'hosting condiviso da solo non basta.** Se l'unica macchina che hai è un
> hosting condiviso, lo Scenario C è l'unica strada, e non è gratuito oltre il
> periodo di prova. Verificalo prima di investire tempo.

### Verificare se il tuo host può eseguire Meilisearch

Meilisearch è un binario Rust collegato a **GLIBC 2.29 o superiore**. Molti
hosting condivisi e le macchine CentOS 7 più vecchie hanno la 2.17, e il binario
semplicemente si rifiuta di partire:

```
./meilisearch: /lib64/libc.so.6: version `GLIBC_2.29' not found
```

Verificalo prima di ogni altra cosa. Via SSH sull'host in questione:

```bash
ldd --version | head -1
```

Ubuntu 20.04+, Debian 11+, RHEL/Alma/Rocky 9+ vanno bene. CentOS 7 e Debian 10
no.

---

## Scenario A — Meilisearch sullo stesso server di phpBB

La configurazione più semplice e più sicura: Meilisearch ascolta solo su
loopback, quindi niente di esterno alla macchina può raggiungerlo, e **non serve
nessuna chiave API**.

Tutti i comandi si eseguono via SSH sul server, come root.

### A1. Installa il binario

```bash
curl -L https://install.meilisearch.com | sh
mv ./meilisearch /usr/local/bin/
chmod +x /usr/local/bin/meilisearch
meilisearch --version
```

L'ultimo comando deve stampare una versione. Se stampa un errore GLIBC, fermati:
questo host non può eseguire Meilisearch, passa allo Scenario B o C.

### A2. Crea l'utente di servizio e la cartella dati

```bash
useradd -d /var/lib/meilisearch -s /bin/false -M meilisearch
mkdir -p /var/lib/meilisearch
chown -R meilisearch:meilisearch /var/lib/meilisearch
chmod 750 /var/lib/meilisearch
```

Eseguili come **quattro comandi separati**. Concatenati con `&&`, un errore
"utente già esistente" interrompe il resto e lascia la cartella di proprietà di
root — cosa che produce `Permission denied (os error 13)` all'avvio ed è facile
da diagnosticare male.

### A3. Crea l'unità systemd

```bash
cat > /etc/systemd/system/meilisearch.service <<'EOF'
[Unit]
Description=Meilisearch
After=network.target

[Service]
Type=simple
User=meilisearch
Group=meilisearch
WorkingDirectory=/var/lib/meilisearch
ExecStart=/usr/local/bin/meilisearch --http-addr 127.0.0.1:7700 --db-path /var/lib/meilisearch --env development
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
```

`WorkingDirectory` **non è opzionale**. Senza, systemd avvia il processo in
`/root`, dove l'utente `meilisearch` non può entrare, e il servizio muore con
`Permission denied` — un errore che sembra un problema della cartella dati e non
lo è.

`--env development` disattiva l'obbligo della master key. È sicuro **soltanto**
grazie a `--http-addr 127.0.0.1`, che rende la porta irraggiungibile dalla rete.
Non combinare mai `development` con un indirizzo pubblico.

### A4. Avvia e verifica

```bash
systemctl daemon-reload
systemctl enable --now meilisearch
systemctl status meilisearch --no-pager
curl http://127.0.0.1:7700/health
```

Atteso: `{"status":"available"}`.

Se il servizio fallisce, l'output utile non è `systemctl status` ma:

```bash
journalctl -u meilisearch -n 30 --no-pager
```

### A5. Configura phpBB

**PCA → Generale → Configurazione server → Motore di ricerca**

| Campo | Valore |
|---|---|
| URL di Meilisearch | `http://127.0.0.1:7700` |
| Chiave API | *(lascia vuoto)* |
| Nome dell'indice | `phpbb_posts` |
| Lingue dei contenuti | `ita` oppure `ita,eng` |

Invia, ma **lascia per ora il selettore del backend sul motore nativo.**

Poi vai a [Completamento](#completamento-tutti-gli-scenari).

---

## Scenario B — Meilisearch su un server separato (Hetzner)

Per un forum su hosting condiviso quando disponi anche di un VPS. Meilisearch
gira sul VPS; phpBB lo raggiunge via HTTPS. Servono TLS, una chiave API e un
firewall, perché ora i contenuti dei messaggi e le credenziali attraversano
internet.

Schema di arrivo:

```
hosting condiviso (phpBB)  ──HTTPS──►  search.esempio.it  ──►  VPS
                                                               Nginx o Caddy
                                                               Meilisearch su 127.0.0.1:7700
```

### B0. Collegarsi al VPS da Windows

La console nel browser della maggior parte dei provider **non permette di
incollare**. Usa PowerShell, dove il tasto destro incolla:

```powershell
ssh root@IP_DEL_TUO_SERVER
```

Su Hetzner la password di root arriva via email alla creazione del server. Se il
server è stato creato con una chiave SSH, non viene chiesta alcuna password. Se
hai perso la password: Console Hetzner → seleziona il server → scheda **Rescue**
→ *Reset root password*. Non si trova sotto "Actions". Attenzione: il reset
riavvia il server.

### B1. Installa Meilisearch

Segui **da A1 ad A4 senza modifiche**, ma in A3 sostituisci la riga `ExecStart`
per usare una master key e la modalità production:

```bash
# genera una chiave e salvala dove solo root e il servizio possono leggerla
echo "MEILI_MASTER_KEY=$(openssl rand -base64 48 | tr -d '\n')" > /etc/meilisearch.env
chown root:meilisearch /etc/meilisearch.env
chmod 640 /etc/meilisearch.env
```

Un `chmod 600` escluderebbe l'utente del servizio, dato che l'unità gira come
`meilisearch` e non come root.

L'unità diventa:

```bash
cat > /etc/systemd/system/meilisearch.service <<'EOF'
[Unit]
Description=Meilisearch
After=network.target

[Service]
Type=simple
User=meilisearch
Group=meilisearch
WorkingDirectory=/var/lib/meilisearch
EnvironmentFile=/etc/meilisearch.env
ExecStart=/usr/local/bin/meilisearch --http-addr 127.0.0.1:7700 --db-path /var/lib/meilisearch --env production
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now meilisearch
curl http://127.0.0.1:7700/health
```

Meilisearch resta legato a loopback. Sarà il web server davanti a lui a essere
esposto.

### B2. Punta un sottodominio al VPS

Nel pannello DNS crea un **record A**:

| Tipo | Nome | Valore |
|---|---|---|
| A | `search` | l'IPv4 del tuo VPS |

Verifica dal VPS prima di continuare:

```bash
dig +short search.esempio.it
```

Deve stampare l'IP del VPS. **Non proseguire finché non lo fa**: Let's Encrypt
limita i tentativi falliti e ti bloccherebbe per un'ora.

### B3. Controlla cosa occupa già la porta 80

```bash
ss -tlnp | grep -E ':80 |:443 '
```

La risposta decide il passo successivo. Un VPS che ospita già qualcosa ha di
solito Nginx o Apache sulla porta 80, e installare un secondo web server
fallirebbe con `bind: address already in use`.

#### B3a. Nginx è già installato

Usalo come reverse proxy invece di aggiungere Caddy:

```bash
apt install -y certbot python3-certbot-nginx

cat > /etc/nginx/sites-available/meilisearch <<'EOF'
server {
    listen 80;
    server_name search.esempio.it;

    location / {
        proxy_pass http://127.0.0.1:7700;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 300s;
        client_max_body_size 100M;
    }
}
EOF

ln -sf /etc/nginx/sites-available/meilisearch /etc/nginx/sites-enabled/meilisearch
nginx -t
```

`nginx -t` deve riportare `test is successful`. Se non lo fa, **fermati e
correggi** — non ricaricare, o metteresti giù tutto il resto che il server
ospita.

```bash
systemctl reload nginx
certbot --nginx -d search.esempio.it --agree-tos --no-eff-email -m tua@email.it --redirect
curl https://search.esempio.it/health
```

`proxy_read_timeout 300s` conta: una reindicizzazione completa invia blocchi
grandi e i 60 secondi di default possono troncarli.

#### B3b. La porta 80 è libera

Caddy gestisce i certificati da solo:

```bash
apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt update && apt install -y caddy

cat > /etc/caddy/Caddyfile <<'EOF'
search.esempio.it {
	reverse_proxy 127.0.0.1:7700
}
EOF

systemctl enable --now caddy
curl https://search.esempio.it/health
```

Al primo avvio usa `systemctl enable --now`, non `reload`: ricaricare un servizio
mai avviato fallisce con `caddy.service is not active, cannot reload`.

Nota che `curl -I` invia una richiesta HEAD e Meilisearch risponde `405 Not
Allowed` su `/health`. Non è un errore: dimostra che Meilisearch sta rispondendo
attraverso il proxy. Per una verifica reale usa `curl` senza `-I`.

### B4. Configura phpBB e genera la chiave

**PCA → Generale → Configurazione server → Motore di ricerca**

| Campo | Valore |
|---|---|
| URL di Meilisearch | `https://search.esempio.it` |
| Chiave API | *(per ora lascia vuoto)* |
| Nome dell'indice | `phpbb_posts` |
| Lingue dei contenuti | `ita` oppure `ita,eng` |

Invia, lasciando il backend sul nativo.

Leggi la master key sul VPS:

```bash
cat /etc/meilisearch.env
```

Copia solo la parte dopo `MEILI_MASTER_KEY=`.

Poi **PCA → Estensioni → Meilisearch → Diagnostica Meilisearch**, sezione *Genera
una nuova chiave API*: incolla la master key e premi il pulsante. L'estensione
chiama `POST /keys`, salva da sé la chiave restituita e non memorizza mai la
master key.

La chiave generata è limitata all'indice di questo forum e a queste azioni:

```
search, documents.add, documents.delete, indexes.get, indexes.create,
settings.get, settings.update, stats.get, tasks.get
```

Azioni globali come `version` e `dumps.create` sono deliberatamente assenti:
Meilisearch rifiuta una chiave limitata a un indice che porti anche una sola
azione globale (`index_scoped_api_key_with_global_action`). La conseguenza
pratica è che la pagina di diagnostica non può mostrare la versione del server
quando si usa una chiave generata. È una perdita puramente estetica e non vale
una chiave globale.

Premi **Esegui il test della chiave** per confermare che tutte le operazioni
necessarie riescano.

### B5. Restringi l'accesso con un firewall

A questo punto `search.esempio.it` risponde a chiunque su internet. La chiave API
protegge i dati, ma la superficie esposta è più ampia del necessario.

Per prima cosa trova l'IP di uscita del tuo hosting. Via SSH **sull'hosting
condiviso**, non sul VPS:

```bash
curl -s https://api.ipify.org; echo
```

Poi, nella Console Hetzner → **Firewalls** → *Create Firewall*, consenti in
ingresso:

| Protocollo | Porta | Sorgente |
|---|---|---|
| TCP | 443 | IP dell'hosting `/32` |
| TCP | 80 | `0.0.0.0/0` e `::/0` |
| TCP | 22 | il tuo IP `/32` |

Tre avvertenze, tutte già costate care a qualcuno:

- **La porta 80 deve restare aperta a tutti**, altrimenti Certbot non può
  rinnovare e l'HTTPS scade dopo 90 giorni.
- **La porta 22 deve restare raggiungibile da te**, altrimenti ti chiudi fuori.
  La console del provider aggira il firewall ed è la tua via di rientro.
- **Controlla cos'altro gira sul VPS** (`ss -tlnp`) prima di applicare. Un
  firewall che apre solo 22/80/443 romperà qualsiasi altro servizio su quella
  macchina.

Se l'IP di uscita del tuo hosting è dinamico — cosa comune sull'hosting condiviso
— rinuncia alla restrizione sulla 443. Una ricerca rotta è peggio di una
superficie leggermente più ampia, e la chiave API è già una protezione reale.

Ora vai a [Completamento](#completamento-tutti-gli-scenari).

---

## Scenario C — Meilisearch Cloud

Nessun server richiesto, ma **non è gratuito**: si parte con una prova di 14
giorni e poi si paga. Verifica i prezzi attuali su
[cloud.meilisearch.com](https://cloud.meilisearch.com) prima di impegnarti.

1. Registrati e crea un progetto, scegliendo la regione più vicina al forum.
2. Attendi che lo stato del progetto diventi *running*.
3. Copia l'URL del progetto, ad esempio `ms-abc123def456.par.meilisearch.io`.
4. Apri **View API keys** e copia la **Default Admin API Key** — non la Default
   Search API Key, che non può scrivere.

**Non** creare un indice dalla dashboard di Cloud. Lo crea l'estensione con gli
attributi filtrabili, ordinabili e ricercabili corretti; un indice creato a mano
è vuoto e mal configurato.

Poi in **PCA → Generale → Configurazione server → Motore di ricerca**:

| Campo | Valore |
|---|---|
| URL di Meilisearch | `https://ms-abc123def456.par.meilisearch.io` (con `https://`, senza slash finale) |
| Chiave API | la Default Admin API Key |
| Nome dell'indice | `phpbb_posts` |
| Lingue dei contenuti | `ita` oppure `ita,eng` |

Il generatore di chiavi nella pagina di diagnostica qui non serve: Cloud fornisce
già una chiave utilizzabile.

Tieni presente che con Cloud i contenuti dei messaggi vengono elaborati da terzi.
Dichiaralo nella tua informativa privacy — vedi
[Modello di sicurezza](#modello-di-sicurezza).

---

## Completamento (tutti gli scenari)

L'ordine conta. Seguirlo significa che la ricerca del forum non si interrompe mai,
neanche per un minuto.

### 1. Verifica la connessione

**PCA → Estensioni → Meilisearch → Diagnostica Meilisearch.**

Lo stato deve indicare **Raggiungibile**. Premi **Esegui il test di connessione**
per il tempo di andata e ritorno. Sotto i ~50 ms su socket locale, o sotto i ~200
ms via internet, è sano.

### 2. Crea l'indice

Premi **Crea l'indice e applica le impostazioni**. Questo crea l'indice e scrive
la configurazione degli attributi. Risultato atteso: *L'indice esiste e le
impostazioni sono state applicate.*

### 3. Scegli quali forum indicizzare

**PCA → Estensioni → Meilisearch → Forum indicizzati.**

All'installazione la lista delle esclusioni è precompilata con tutti i forum non
leggibili dagli ospiti. Rivedila e invia. Vedi
[Scelta dei forum da indicizzare](#scelta-dei-forum-da-indicizzare) per capire
cosa fa e cosa non fa questa impostazione.

### 4. Costruisci l'indice — con il vecchio backend ancora attivo

**PCA → Manutenzione → Indice ricerca**, riga **Meilisearch**, premi **Crea
indice**.

phpBB scorre la tabella dei post a blocchi con una barra di avanzamento,
riprendendo dopo i timeout. La velocità tipica è di 1.500–4.000 messaggi al
secondo.

**Non cambiare ancora il backend di ricerca.** phpBB sa costruire l'indice di un
backend non attivo, quindi per tutto il tempo il forum continua a cercare con il
motore nativo.

### 5. Conferma che l'indice sia popolato

Tornando alla diagnostica, *Messaggi indicizzati* deve mostrare un numero vicino
al totale dei messaggi del forum. Premi **Esegui il test dell'indice**: esegue
una query reale e riporta corrispondenze e tempo di risposta.

### 6. Cambia il backend

**PCA → Generale → Configurazione server → Motore di ricerca** → *Backend di
ricerca* → **Meilisearch** → Invia.

phpBB chiede conferma e chiama `init()`, che ricontrolla la raggiungibilità. Se
qualcosa fallisce il cambio viene annullato e il forum resta dov'era.

### 7. Prova da utente reale

Accedi come membro normale e cerca:

- un termine di **due o tre lettere** presente nei messaggi, come `V8` o `SSD`.
  Il fulltext MySQL li scarta; se ottieni risultati, sta rispondendo Meilisearch.
- una parola **con un refuso**. Se la trova, la tolleranza agli errori funziona.
- un termine che sai comparire **solo in un forum privato**. Non devi ottenere
  nulla. Questa è la verifica dei permessi ed è il test più importante di questa
  pagina.

### 8. Facoltativo — recupera lo spazio del vecchio indice

Quando sei soddisfatto, **PCA → Manutenzione → Indice ricerca** → *phpBB Native
Fulltext* → **Elimina indice**. Questo libera le tabelle `search_wordlist` e
`search_wordmatch`, spesso le più grandi del database.

Fallo **per ultimo**. Finché non lo elimini, l'indice nativo è la tua via di
ritorno immediata.

### 9. Configura il cron

La coda di ripetizione viene svuotata da un cron task. Il cron web di phpBB
funziona, ma un cron di sistema è meglio:

```
*/5 * * * * /usr/bin/php /percorso/di/phpBB/bin/phpbbcli.php cron:run --quiet
```

---

## Riferimento delle opzioni

Tutte le opzioni si trovano in **PCA → Generale → Configurazione server → Motore
di ricerca**, generate da `meilisearch_backend::acp()`. Sono salvate in
`phpbb_config`.

| Chiave | Default | Note |
|---|---|---|
| `meilisearch_url` | `http://127.0.0.1:7700` | Senza slash finale. |
| `meilisearch_api_key` | *(vuoto)* | Master key, o una chiave con le azioni elencate sopra. |
| `meilisearch_index` | `phpbb_posts` | Usa un nome distinto per ogni forum se più installazioni condividono un'istanza. |
| `meilisearch_locales` | *(vuoto)* | Codici ISO 639, es. `ita,eng`. Vuoto = riconoscimento automatico per documento. |
| `meilisearch_timeout` | `5` | Secondi. Tienilo basso: un motore lento non deve bloccare le pagine. |
| `meilisearch_max_results` | `1000` | Limite dei candidati. Scritto anche su Meilisearch come `pagination.maxTotalHits`. |
| `meilisearch_batch_size` | `250` | Documenti per richiesta HTTP durante la reindicizzazione. |
| `meilisearch_min_chars` | `2` | Lunghezza minima dei termini. Nessun limite imposto dal motore, a differenza di MySQL. |
| `meilisearch_max_chars` | `100` | Lunghezza massima dei termini. |
| `meilisearch_typo` | `1` | Tolleranza agli errori. **Dopo la modifica occorre riapplicare le impostazioni** dalla diagnostica. |
| `meilisearch_relevance` | `1` | Ordinamento per rilevanza quando l'utente non ha scelto un ordinamento. |
| `meilisearch_queue_enable` | `1` | Coda di ripetizione. Lasciala attiva. |
| `meilisearch_banner_enable` | `0` | Avviso nelle pagine di ricerca, visibile a tutti gli utenti. |
| `meilisearch_excluded_forums` | *(forum non leggibili dagli ospiti)* | Id separati da virgola. Si modifica in **Estensioni → Meilisearch → Forum indicizzati**. |
| `meilisearch_queue_gc` | `300` | Intervallo del cron in secondi. Nessun campo nel PCA. |

### Una nota sulle lingue

Due cose distinte che vengono spesso confuse:

- **Lingua dell'interfaccia** — le stringhe del PCA. `language/en/` e
  `language/it/` sono entrambe complete.
- **Tokenizzazione dei contenuti** — come Meilisearch segmenta e normalizza il
  testo dei messaggi. È `meilisearch_locales`.

Meilisearch riconosce la lingua per documento, cosa poco affidabile sui messaggi
brevi. Fissare `ita,eng` su un forum misto italiano/inglese migliora
sensibilmente il richiamo. A differenza di SQLite FTS5 (il cui unico stemmer è
Porter, solo inglese), Meilisearch gestisce correttamente la morfologia
italiana.

---

## Costruzione dell'indice

**PCA → Manutenzione → Indice ricerca → Meilisearch → Crea indice.**

L'estensione deliberatamente **non** implementa `create_index()`. Quando quel
metodo è assente, `acp_search` esegue il proprio ciclo a blocchi: scorre la
tabella dei post in ordine di `post_id` crescente, chiama `index()` su ciascuno,
rispetta `still_on_time()`, fa meta-refresh, disegna una barra di avanzamento e
riprende esattamente dal punto in cui si era fermato. Reimplementarlo sarebbe
strettamente peggio.

`delete_index()` **è** implementato, perché Meilisearch svuota un indice con una
sola chiamata `DELETE /indexes/<uid>/documents` — non c'è ragione di scorrere
tutti i messaggi.

Cifre indicative su un VPS a 4 core con Meilisearch sulla stessa macchina:
**1.500–4.000 messaggi al secondo**, con il tempo dominato dalle letture MySQL e
dalla pulizia del markup s9e più che da Meilisearch. Un forum da 500.000
messaggi richiede pochi minuti.

I forum con *Abilita l'indicizzazione per la ricerca* impostata su No nelle
impostazioni del forum vengono saltati dal ciclo di phpBB, esattamente come con
il backend nativo.

### Scelta dei forum da indicizzare

**PCA → Estensioni → Meilisearch → Forum indicizzati.**

Una casella per forum lo marca come *escluso*. I forum esclusi vengono filtrati
in `indexer::build_documents()`, quindi i loro messaggi non raggiungono mai
Meilisearch — né durante una reindicizzazione completa, né alla pubblicazione,
né tramite la coda di ripetizione.

All'installazione la lista è precompilata da `m2_forum_exclusions` con tutti i
forum non leggibili dall'account ospite. È un punto di partenza prudente, non una
politica: da lì in poi comanda ciò che salvi tu. Il pulsante *Preseleziona i
forum non leggibili dagli ospiti* ricarica quel suggerimento nel modulo senza
salvare, così puoi rivederlo prima di applicarlo.

Due cose da sapere:

- **Cambiare la lista non ripulisce l'indice a posteriori.** I messaggi
  indicizzati sotto la vecchia lista restano. Premi *Rimuovi dall'indice i forum
  esclusi*: esegue una singola chiamata di eliminazione per filtro
  (`forum_id IN [...]`, Meilisearch 1.2+) invece di scorrere la tabella dei post.
  Il percorso inverso — da escluso a indicizzato — richiede una
  reindicizzazione, perché quei messaggi non sono mai stati inviati.
- **Escludere un forum lo toglie dalla ricerca per tutti**, moderatori compresi.
  Se lo staff usa un forum privato come archivio di lavoro, lascialo indicizzato
  e affidati allo stadio SQL dei permessi.

### Reindicizzare in sicurezza su un forum attivo

Meilisearch aggiorna in sovrascrittura sulla chiave primaria `post_id`, quindi
rieseguire "Crea indice" su un indice esistente non è distruttivo: i documenti
vengono sostituiti sul posto e la ricerca continua a funzionare per tutto il
tempo. **Non** serve eliminare l'indice prima, a meno che lo schema dei documenti
non sia cambiato.

---

## Gestione quotidiana

### Cron

Il task `salvocortesiano.meilisearch.cron.flush_queue` gira ogni
`meilisearch_queue_gc` secondi e svuota la coda di ripetizione. Su un forum sano
non fa nulla e costa una singola query di conteggio.

### Monitoraggio

La pagina di diagnostica mostra raggiungibilità, numero di documenti, se
Meilisearch sta elaborando, la profondità della coda e il numero di forum
esclusi. I tre pulsanti di test misurano latenza, eseguono una query reale e
verificano i permessi della chiave.

**Il numero da tenere d'occhio è la profondità della coda.** Una coda che cresce
costantemente significa che Meilisearch è irraggiungibile da un po' e il tuo
indice sta divergendo. Quando torna raggiungibile, la coda si svuota da sola.

**PCA → Manutenzione → Indice ricerca** mostra le stesse statistiche tramite
`index_stats()`.

### Cosa succede se Meilisearch si ferma

- Pubblicazione, modifica, lettura: non toccate.
- Messaggi nuovi e modificati: gli id finiscono in `phpbb_meili_queue` e vengono
  riprovati dal cron.
- Ricerca: non restituisce risultati e viene scritta una voce nel log errori di
  phpBB. Non genera un errore fatale per l'utente.

### Tornare indietro

Cambia il backend su phpBB Native Fulltext nel PCA. Le tabelle native non
vengono toccate da questa estensione, quindi se un indice nativo era stato
costruito in precedenza è ancora lì e la ricerca riprende immediatamente.

---

## Modello di sicurezza

**Da leggere prima di esporre qualsiasi cosa.**

1. **Meilisearch non ha controllo di accesso per utente.** Chiunque raggiunga
   l'API HTTP può leggere ogni messaggio indicizzato, forum privati compresi. La
   protezione è il confine di rete, non la chiave: legalo a `127.0.0.1`, mettilo
   su un segmento privato, o limita il firewall all'indirizzo del web server.
   Non esporlo mai pubblicamente. Girare senza chiave (Scenario A) è sicuro
   proprio perché nulla al di fuori della macchina può connettersi; girare con
   una chiave su una porta esposta non sostituisce quel confine.

2. **La chiave API è salvata in chiaro** in `phpbb_config`, come ogni altro
   valore di configurazione di phpBB. Qualsiasi fondatore con accesso al PCA e
   chiunque abbia accesso in lettura al database può recuperarla. È il motivo per
   cui l'estensione genera una chiave ristretta invece di chiedere la master key:
   la credenziale salvata può cercare e indicizzare questo forum, e nient'altro.
   La master key esiste solo dentro il corpo di una singola richiesta POST e non
   viene mai scritta nel database.

3. **L'applicazione dei permessi non lascia mai phpBB.** A Meilisearch si chiede
   soltanto quali post id corrispondono alle parole. Permessi dei forum e
   visibilità di moderazione sono applicati dopo, in SQL. È intenzionale e non
   va "ottimizzato" spingendo `post_visibility` nel filtro Meilisearch — vedi
   [Architettura](#architettura). Nessun utente è mai stato in grado di trovare
   un messaggio che non può leggere, indipendentemente dalla lista di esclusione.

4. **Le esclusioni per forum sono difesa in profondità, non il controllo dei
   permessi.** La schermata *Forum indicizzati* decide cosa può uscire dal
   database. Escludere un forum significa che i suoi messaggi non vengono mai
   scritti su Meilisearch, quindi nemmeno un'istanza compromessa o mal
   configurata può rivelarli. **Il prezzo è reale: anche i membri che hanno
   legittimamente accesso a un forum escluso non ne troveranno i messaggi tramite
   la ricerca.**

5. **I contenuti dei messaggi escono dal database.** Ai fini del GDPR l'istanza
   Meilisearch è un luogo di trattamento di contenuti generati dagli utenti. Se è
   ospitata da terzi (Meilisearch Cloud), dichiaralo nella tua informativa
   privacy.

6. **I messaggi privati non vengono indicizzati.** phpBB non li fa passare dal
   backend di ricerca, e questa estensione non li aggiunge.

---

## Limiti e compromessi

Noti e accettati, elencati perché nessuno debba scoprirli nel modo difficile:

- **I conteggi dei risultati sono limitati.** Solo i primi
  `meilisearch_max_results` risultati arrivano allo stadio 2. Su una query molto
  generica il totale riportato è per difetto. Alza il limite se ti serve; il
  tetto è la dimensione della clausola SQL `IN()`.

- **Gli operatori booleani di phpBB sono approssimati.** Meilisearch non
  implementa `+parola`, `|`, o i gruppi annidati. `"frase esatta"` e
  `-esclusione` passano nativamente; `+` e `|` vengono rimossi, dato che il
  ranking di Meilisearch mette già più in alto i documenti che corrispondono a
  più termini. È un cambio di comportamento rispetto al backend nativo e va
  segnalato nell'aiuto alla ricerca del tuo forum.

- **L'ordinamento per rilevanza si perde quando l'utente sceglie un
  ordinamento.** È voluto.

- **La ricerca per solo autore non tocca Meilisearch.** Senza parole chiave non
  c'è nulla da cercare, quindi `author_search()` è puro SQL, con comportamento
  identico a `fulltext_mysql`.

- **Nomi degli allegati e opzioni dei sondaggi non vengono indicizzati.** Non lo
  sono nemmeno nel phpBB di base.

- **I messaggi degli ospiti per `post_username` sono gestiti solo in SQL.**
  Quando una ricerca per autore include un nome ospite, il filtro sull'autore non
  viene passato a Meilisearch; ci pensa lo stadio 2.

- **`phpbb_search_results` è ancora in uso.** La cache degli insiemi di risultati
  resta quella di phpBB, invalidata una volta per blocco di indicizzazione invece
  che a ogni messaggio — indicizzare 500.000 messaggi con invalidazione per
  messaggio sarebbe rovinoso.

---

## Risoluzione dei problemi

**`Permission denied (os error 13)` all'avvio di Meilisearch**
O la cartella dati non appartiene all'utente del servizio, o l'unità non ha
`WorkingDirectory` e systemd avvia il processo in `/root`. Controlla entrambe:

```bash
ls -ld /var/lib/meilisearch /etc/meilisearch.env
grep WorkingDirectory /etc/systemd/system/meilisearch.service
```

**`status=203/EXEC`**
Il binario non si trova nel percorso indicato in `ExecStart`. Ripeti
l'installazione e verifica con `meilisearch --version`.

**`bind: address already in use` all'avvio di Caddy**
Qualcosa occupa già la porta 80. Esegui `ss -tlnp | grep ':80 '` e usa il web
server esistente come reverse proxy — vedi
[B3a](#b3a-nginx-è-già-installato).

**`index_scoped_api_key_with_global_action` generando la chiave**
È stata richiesta un'azione globale per una chiave limitata a un indice.
Aggiorna alla 1.2.2 o successiva, dove `version` è stato tolto dall'elenco.

**`missing_authorization_header`**
L'istanza richiede una master key ma il campo della chiave API è vuoto. Genera
una chiave dalla diagnostica prima di premere *Crea l'indice e applica le
impostazioni*.

**"L'estensione Meilisearch è selezionata come backend ma non è attiva"**
L'estensione è stata disabilitata mentre era il backend attivo. Riabilitala,
oppure riporta `search_type` indietro:

```sql
UPDATE phpbb_config SET config_value = '\\phpbb\\search\\fulltext_native'
WHERE config_name = 'search_type';
```

**`index_not_found`**
Premi *Crea l'indice e applica le impostazioni* nella diagnostica.

**`Attribute X is not filterable`**
Le impostazioni dell'indice sono obsolete, cosa che succede se l'indice è stato
creato da una versione precedente dell'estensione. Riapplica le impostazioni
dalla diagnostica.

**Le ricerche non restituiscono nulla ma l'indice contiene documenti**
Quasi sempre un filtro rifiutato da Meilisearch. Attiva la modalità debug di
phpBB e controlla il log errori: un filtro rifiutato viene registrato come
`LOG_MEILISEARCH_ERROR`.

**L'avviso non compare nella pagina dei risultati**
Il tuo tema ha un `search_results.html` proprio senza l'evento
`search_results_header_before`. Dalla 1.4.0 l'estensione se ne accorge e ripiega
sull'inserimento da `overall_header_content_before`; assicurati di essere alla
1.4.0 o successiva e di aver eliminato la cache di phpBB.

**La coda cresce e non si svuota mai**
Il cron non gira, o Meilisearch è ancora irraggiungibile. Esegui
`php bin/phpbbcli.php cron:run` a mano e osserva la diagnostica.

---

## Disinstallazione

1. **Per prima cosa** riporta il backend di ricerca su quello nativo e
   ricostruisci l'indice nativo, altrimenti il forum resta senza ricerca
   funzionante.
2. PCA → Gestione estensioni → Disabilita, poi Elimina i dati.

`revert_data()` include una rete di sicurezza: se Meilisearch è ancora il backend
attivo quando i dati vengono eliminati, `search_type` viene riportato d'ufficio a
`fulltext_native`.

L'eliminazione dell'estensione **non** elimina l'indice Meilisearch. Rimuovilo a
mano se non ti serve più:

```bash
curl -X DELETE -H "Authorization: Bearer <chiave>" http://127.0.0.1:7700/indexes/phpbb_posts
```

---

## Note per sviluppatori

### Come phpBB trova questo backend

Non c'è nessuna registrazione di servizi.
`includes/acp/acp_search.php::get_search_types()` esegue l'extension finder:

```php
$finder->extension_suffix('_backend')
       ->extension_directory('/search')
       ->core_path('phpbb/search/')
       ->get_classes();
```

Quindi **qualsiasi** classe in `ext/<vendor>/<ext>/search/` il cui file termini
in `_backend.php` compare nel selettore del PCA. La classe viene poi istanziata
direttamente:

```php
$search = new $search_type($error, $phpbb_root_path, $phpEx, $auth,
                           $config, $db, $user, $phpbb_dispatcher);
```

Quella firma posizionale è fissa e non può cambiare. Poiché i backend di ricerca
non sono servizi DI, le dipendenze più pesanti vengono prelevate a mano da
`$phpbb_container` nel costruttore, protette da un `try`/`catch` in modo che
un'estensione disabilitata produca un errore pulito invece di un fatale.

### Mappa dei file

```
salvocortesiano/meilisearch/
├── composer.json
├── ext.php                              controlli PHP / cURL / JSON
├── config/services.yml                  client, indexer, cron, listener
├── search/
│   └── meilisearch_backend.php          il backend che phpBB scopre
├── meili/
│   ├── client.php                       wrapper cURL, non lancia mai eccezioni
│   └── indexer.php                      documenti, batch, coda, esclusioni
├── cron/task/flush_queue.php
├── acp/
│   ├── main_info.php
│   └── main_module.php                  diagnostica + forum indicizzati
├── adm/style/
│   ├── acp_meilisearch.html
│   └── acp_meilisearch_forums.html
├── event/listener.php                   avviso nelle pagine di ricerca
├── styles/all/template/                 markup dell'avviso + eventi template
├── migrations/v10x/
│   ├── m1_initial_schema.php
│   ├── m2_forum_exclusions.php
│   └── m3_search_banner.php
└── language/{en,it}/
```

### Metodi del backend e chi li chiama

| Metodo | Chiamante |
|---|---|
| `get_name()` | etichetta nel selettore del PCA |
| `init()` | PCA al cambio di backend, e da `delete_index()` |
| `split_keywords()` | `search.php`, prima di ogni ricerca per parole chiave |
| `keyword_search()` | `search.php` |
| `author_search()` | `search.php` |
| `index()` | `submit_post()` e il ciclo di reindicizzazione del PCA |
| `index_remove()` | eliminazione messaggi, `functions_admin.php` |
| `tidy()` | cron, e dopo ogni blocco di indicizzazione — il nostro punto di flush |
| `delete_index()` | PCA "Elimina indice" |
| `index_created()`, `index_stats()` | pagina dell'indice di ricerca nel PCA |
| `acp()` | impostazioni di ricerca, restituisce `['tpl' => ..., 'config' => ...]` |

`create_index()` è volutamente assente, così `acp_search` fornisce il ciclo a
blocchi con ripresa.

### Eventi

| Evento | Scopo |
|---|---|
| `salvocortesiano.meilisearch.modify_search_key` | modificare la chiave di cache dei risultati |
| `salvocortesiano.meilisearch.refine_query_before` | modificare l'SQL dello stadio 2 |

### Rinominare il vendor

Namespace, nomi delle cartelle, id dei servizi, la stringa `auth` del PCA
(`ext_salvocortesiano/meilisearch`), la chiave del modulo e `composer.json`
devono coincidere. Per rinominare:

```bash
grep -rl 'salvocortesiano' . | xargs sed -i 's/salvocortesiano/nuovovendor/g'
cd .. && mv salvocortesiano nuovovendor
```

Poi elimina la cache di phpBB.

---

## phpBB 4.0

**Questa estensione non funziona sulla 4.0 così com'è.** La 4.0 ha rifattorizzato
la ricerca in `phpbb\search\backend\` con una vera `search_backend_interface`, i
backend sono diventati servizi DI registrati con il tag `search.backend`, `acp()`
è diventato `get_acp_options()`, e il contratto di `create_index()` /
`delete_index()` è cambiato.

Il modello di query a due stadi e tutto ciò che sta in `meili/` si trasferisce
invariato; il lavoro di porting è confinato a `search/meilisearch_backend.php` e
a `config/services.yml`.

La 4.0 è ancora in alpha: conviene attendere una beta prima di portare il codice,
altrimenti si rischia di rifare il lavoro a ogni release.

---

## Licenza

GPL-2.0-only, come phpBB.

## Stato

Versione 1.5.0. In produzione su un forum phpBB 3.3.17 con circa 34.000 messaggi
indicizzati, su Meilisearch 1.53 dietro Nginx e Let's Encrypt.

Tutti i file PHP passano `php -l` su PHP 8.3; il cablaggio DI, i namespace e le
variabili dei template sono verificati automaticamente in fase di build; i file
lingua sono completi e allineati in inglese e italiano.

Segnalazioni e pull request sono benvenute. Quando segnali un problema, indica la
versione di phpBB, la versione di Meilisearch, quale dei tre scenari usi e
l'output dei tre pulsanti di test della pagina di diagnostica.
