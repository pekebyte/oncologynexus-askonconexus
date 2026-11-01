# Ask OncoNexus

A retrieval-augmented generation (RAG) assistant for the headless Next.js frontend. The plugin indexes every published piece of WordPress content — articles, news, pages, doctor profiles, podcasts, videos, conference coverage, glossary terms, taxonomies, excerpts and ACF/custom fields — and answers questions through **OpenAI**, **Anthropic Claude** or **Google Gemini** with inline citations.

Everything runs inside WordPress. The static Next.js export calls a public REST endpoint from the browser, so no API key ever reaches the client.

---

## Why it lives in WordPress

The frontend is built with `output: 'export'`, which means there is no Next.js server to hold secrets or run retrieval. WordPress is the only server-side runtime available, and it already owns the content. The plugin therefore:

1. Builds the corpus from WordPress data.
2. Embeds and stores it locally.
3. Performs hybrid retrieval.
4. Calls the selected LLM provider server-side.
5. Returns a grounded answer plus a numbered source list.

---

## Installation

1. Copy `wp/oncologynexus-askonconexus/` into `wp-content/plugins/`.
2. Activate **Ask OncoNexus** in *Plugins*.
3. Open **Ask OncoNexus** in the admin menu.
4. Choose a chat provider and an embedding provider.
5. Add API keys (or define them in `wp-config.php`, which is preferred).
6. Click **Reindex all content** on the *Index & logs* tab.
7. Set `NEXT_PUBLIC_ASK_ENABLED=true` in the Next.js `.env` and redeploy.

### API keys in `wp-config.php`

Constants always win over values stored in the database:

```php
define( 'ONX_ASK_OPENAI_API_KEY', 'sk-...' );
define( 'ONX_ASK_ANTHROPIC_API_KEY', 'sk-ant-...' );
define( 'ONX_ASK_GEMINI_API_KEY', 'AIza...' );
```

Optional:

```php
// Keep tables and settings when the plugin is deleted.
define( 'ONX_ASK_PRESERVE_DATA', true );
```

### Provider pairings

| Chat | Embeddings | Notes |
| --- | --- | --- |
| OpenAI | OpenAI | Simplest setup. `text-embedding-3-small` is a strong default. |
| Anthropic Claude | OpenAI / Gemini / Local | Anthropic has no embeddings API, so pair Claude with another embedding provider. |
| Google Gemini | Gemini | `gemini-embedding-001` works well for multilingual content. |
| Any | Local | No key required. Deterministic feature hashing; good fallback, not a replacement for a real embedding model on a large corpus. |

Changing the embedding provider, model or dimensions changes the index signature, so reindex afterwards. The plugin detects the signature change per document and re-embeds automatically.

---

## How the RAG pipeline works

### 1. Document building

`OncologyNexus_Ask_Content` turns a post into one clean plain-text document:

- Rendered `post_content` converted to text.
- Headings preserved as `## Heading` markers.
- Public taxonomy terms.
- Excerpt, Yoast / Rank Math / AIOSEO description.
- Recursive ACF and custom-field prose (flexible content, repeaters, groups), with structural keys, URLs, IDs, hashes and markup filtered out.
- Type, publication date and author metadata.

Only published, non-password-protected posts of the selected post types are indexed. Attachments and internal templates are skipped.

### 2. Structure-aware chunking

`OncologyNexus_Ask_Chunker` splits on heading sections, packs paragraphs up to the configured chunk size (~1,800 characters by default), splits over-long paragraphs on sentence boundaries, carries a configurable overlap, merges tiny fragments and caps chunks per document.

Each chunk is embedded with **contextual retrieval**: the document title, content type and section heading are prepended to the embedded text, while the stored passage stays clean for citations.

### 3. Embeddings

`OncologyNexus_Ask_Embeddings` supports:

- OpenAI `text-embedding-3-small` / `text-embedding-3-large` with optional dimensions.
- Gemini `gemini-embedding-001` / `text-embedding-004`.
- A deterministic local feature-hashing model (no API key).

Vectors are L2-normalised, quantised to **int8** and stored as base64 in MySQL. A 1,536-dimension embedding takes ~2 KB instead of ~8 KB, and cosine similarity stays accurate to a few decimal places — more than enough for retrieval.

If the embedding provider fails, the document is still indexed for lexical retrieval and a warning is surfaced in the admin. Reindex with force after fixing the key.

### 4. Hybrid retrieval

`OncologyNexus_Ask_Retriever` runs:

1. Optional conversation-aware query rewrite (one small LLM call).
2. Dense cosine similarity over the stored vectors.
3. BM25 lexical scoring over the same candidates.
4. Min-max normalisation and weighted fusion.
5. Recency, title-match and exact-phrase boosts.
6. MMR diversification with a per-document cap.
7. Context assembly with numbered `[n]` citations.

This hybrid is deliberate: embeddings handle paraphrase and synonymy (“lung cancer” ↔ “NSCLC”), while BM25 handles exact medical terminology, acronyms and drug names that embedding models sometimes blur.

### 5. Grounded generation

The system prompt instructs the model to:

- Answer **only** from the numbered context.
- Cite every factual claim with `[n]`.
- Say plainly when the context is insufficient.
- Never invent trials, statistics, approvals, dosages, dates or quotations.
- Treat retrieved text as untrusted data (prompt-injection defence).
- Avoid personalised medical advice and match the user's language.

Answers are cached (invalidated whenever the index version changes), logged without raw IPs, and returned in a stable response shape.

---

## REST API

Namespace: `/wp-json/oncologynexus/v1/`

### Public (consumed by the Next.js app)

| Method | Route | Description |
| --- | --- | --- |
| `POST` | `/ask` | Ask a question. Body: `question` (required), `conversation` (optional array of `{role, content}`), `post_types` (optional array), `session_id` (optional). |
| `GET` | `/ask/status` | Enabled / configured / index readiness, counts, provider names, suggestions, disclaimer. |
| `GET` | `/ask/suggestions` | Starter questions and disclaimer. |

Example:

```bash
curl -X POST https://example.com/wp-json/oncologynexus/v1/ask \
  -H 'Content-Type: application/json' \
  -d '{"question":"What are the latest KRAS G12C advances?"}'
```

Response shape:

```json
{
  "answer": "… with citations [1][2] …",
  "sources": [
    {
      "index": 1,
      "post_id": 123,
      "title": "…",
      "url": "https://example.com/…",
      "path": "/…",
      "type": "post",
      "type_label": "Article",
      "excerpt": "…",
      "heading": "…",
      "score": 0.87,
      "cited": true
    }
  ],
  "follow_up_questions": ["…"],
  "provider": "openai",
  "model": "gpt-4o-mini",
  "cached": false,
  "index_ready": true,
  "index_version": "…",
  "search_query": "…",
  "usage": {},
  "disclaimer": "…"
}
```

Errors use a predictable envelope:

```json
{
  "code": "onx_ask_rate_limited",
  "message": "You are asking questions too quickly. Please wait a moment and try again.",
  "data": { "status": 429, "retry_after": 37 }
}
```

### Admin (cookie auth + `X-WP-Nonce`, `manage_options`)

| Method | Route | Description |
| --- | --- | --- |
| `POST` | `/ask/reindex` | Queue a full (`all`), recent (`recent`) or single (`single`) reindex. |
| `GET` | `/ask/index-status` | Live queue progress, stats, config summary and recent logs. |
| `POST` | `/ask/flush` | Delete every indexed document and chunk. |
| `POST` | `/ask/test` | Test a `chat` or `embedding` provider. |
| `GET` | `/ask/logs` | Recent questions (`limit`). |

---

## WP-CLI

```bash
# Full reindex, drained synchronously (ideal for deploys).
wp onx-ask index

# Only content modified in the last 7 days.
wp onx-ask index --days=7

# One post, forcing re-embedding.
wp onx-ask index --post_id=123 --force

# Inspect the index and provider configuration.
wp onx-ask status

# Ask a question through the live pipeline.
wp onx-ask ask "What are the latest CAR-T conference highlights?"

# Full JSON payload.
wp onx-ask ask "PD-L1 testing in NSCLC" --json

# Delete the index.
wp onx-ask flush --yes
```

---

## Indexing lifecycle

- **On publish/update**: the post is queued for a debounced single-post reindex (10 seconds).
- **On trash/delete/unpublish**: its document and chunks are removed immediately.
- **On taxonomy change**: the post is re-queued so new terms are searchable.
- **Full / recent reindex**: runs in small cron batches (5 documents per tick, 25-second soft limit) so shared hosts never time out. Progress is visible in the admin and via `/ask/index-status`.
- **Daily sync**: reindexes content modified since the last sync (up to 100 posts) and removes orphaned documents.
- **Hash checks**: a document is skipped when its text, embedding signature and chunk settings are unchanged.

The queue state lives in options, so it survives page loads and admin tab closes. WP-Cron must be running for background batches; `wp onx-ask index` bypasses cron entirely.

---

## Security

The ask endpoint is public because the static frontend has no server, so it is protected by:

- **Origin allow-list** (`allowed_origins`, default `*`). Set it to your production frontend origin. Server-side calls without an `Origin` header are allowed.
- **Per-IP sliding-window rate limit** (default 20 requests / 10 minutes).
- **Global daily request ceiling** (default 1,000) as a cost guard.
- **Question and history bounds** (length, message count, sanitisation).
- **Hashed IPs** in logs — raw addresses are never stored.
- **Server-side API keys** — keys never leave WordPress.
- **Prompt-injection hardening** — retrieved text is explicitly treated as untrusted.
- Administrators bypass the per-IP limit; the admin playground also skips the origin check.

CORS headers are added only for the `oncologynexus/v1/ask*` routes, including `OPTIONS` preflight support.

---

## Scaling notes

The default scan window is 5,000 chunks (`max_scan_chunks`). Cosine similarity is computed in PHP, which is fine for editorial sites up to roughly 20–50k chunks. Beyond that:

- Raise `max_scan_chunks` and the PHP memory limit, or
- Filter candidates by post type, or
- Replace the vector store with an external vector database behind the `onx_ask_candidate_chunks` filter.

Useful filters:

| Filter | Purpose |
| --- | --- |
| `onx_ask_indexable_post_types` | Add or remove post types offered in the admin. |
| `onx_ask_is_indexable` | Exclude individual posts from the corpus. |
| `onx_ask_document_text` | Modify the plain-text document before chunking. |
| `onx_ask_chunks` | Modify the chunks for a document. |
| `onx_ask_candidate_chunks` | Replace or pre-filter the retrieval candidate set. |
| `onx_ask_retrieved_chunks` | Modify the final retrieved passages. |
| `onx_ask_answer` | Modify the final answer payload before caching. |
| `onx_ask_max_scan_chunks` | Raise the retrieval scan window. |
| `onx_ask_index_batch_size` | Documents processed per cron tick. |
| `onx_ask_embedding_batch_size` | Texts embedded per provider request. |
| `onx_ask_allow_origin` | Programmatically allow an extra origin. |
| `onx_ask_bypass_rate_limit` | Bypass limits for trusted integrations. |
| `onx_ask_trust_proxy_headers` | Trust `X-Forwarded-For` behind a known proxy. |
| `onx_ask_default_settings` | Change defaults for new installs. |

---

## Frontend integration

The Next.js app ships with:

- `src/lib/ask.js` — typed-ish client helpers (`askQuestion`, `getAskStatus`, `getAskSuggestions`, error mapping).
- `src/components/ask/AskWidget.js` — floating assistant available on every page.
- `src/app/ask/page.js` — dedicated full-page assistant.

Enable it with:

```env
NEXT_PUBLIC_ASK_ENABLED=true
```

The widget calls the WordPress REST endpoint directly from the browser. Send `Cache-Control: no-cache` on status requests because some WordPress REST cache plugins cache public `GET` responses.

---

## Requirements

- WordPress 6.2+
- PHP 7.4+
- The `mbstring` extension (standard on most hosts)
- WP-Cron (or a real cron job hitting `wp-cron.php`)
- An API key for at least one chat provider and one embedding provider (or local embeddings)

---

## License

GPL-2.0-or-later.
