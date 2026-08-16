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
