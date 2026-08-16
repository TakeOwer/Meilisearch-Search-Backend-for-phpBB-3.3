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
