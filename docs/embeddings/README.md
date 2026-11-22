# Package file embedding pilot

Each `PackageFile` is a document. Its Markdown stays intact: ATX headings delimit sections (fenced-code headings are ignored), then ai-embed-bundle's model-aware paragraph chunker packs each section within 1,800 tokens including title and Nomic task prefix. Oversized paragraphs are split safely; there is no overlap. Setext headings remain ordinary paragraphs in this first version. Repeated context includes package name, repository-relative file path and heading ancestry, bounded to 240 characters; the original full heading remains in the source.

All stored file kinds use this path, including README, AGENTS and additional documents. Additional document IDs include the repository-relative path, so identical content in two docs files does not collide. Existing README/AGENTS IDs remain unchanged. `app:package-files:import-local` imports README.md, AGENTS.md and UTF-8 `.md`, `.txt`, `.rst`, `.adoc` files recursively under docs/. It does not fetch remote repositories or delete docs absent from that local directory. Existing root-file content is replaced only in PackageFile; the older Package README/vector experiment remains separate.

## Storage and lifecycle

- `var/embeddings/packages.duckdb`: durable, app-independent requests, source sections, chunk offsets, model profiles and vectors, using ai-embed-bundle and ai-duckdb-store.
- `package_file_embedding`: disposable PostgreSQL projection, one row per ready file/profile/chunk; includes source offsets, heading, request ID, document ID and vector. No averaged file or package vector.
- Existing `package.embedding` and its fingerprint are untouched. The existing semantic-search UI still uses that legacy representation; chunk-level search/UI integration is a later slice.
- Doctrine postPersist on PackageFile ensures requests and immediately restores any ready vectors. It never calls a provider. A consumer generates pending inputs; run the embed command again to populate ready results.
- Markdown preprocessing version is `markdown-sections-v1`. The bundle separately records tokenizer/chunker versions. Provider input hashes include the model profile and exact prefixed input, independent of database row IDs.

The pilot is enabled only in `dev`. Production and tests do not instantiate the embedding pipeline by default. It pins `nomic-embed-text:v1.5` and its digest through separate PACKAGE_FILE_EMBED_* variables, preserving the legacy experiment's settings. Native PDO DuckDB and the explicit optional adapter dependency are required. Import, consumer and projection commands must run sequentially because the DuckDB cache has a single-writer lock.

## Reproduce on a small fixture

After `composer install`, apply migrations. Use a package already in the local database:

```sh
php bin/console app:package-files:import-local survos/jsonl-bundle ~/sites/mono/bu/jsonl-bundle
php bin/console app:package-files:import-local survos/api-grid-bundle ~/sites/mono/bu/api-grid-bundle
mkdir -p var/embedding-fixtures/package-files
php bin/console app:package-files:embed survos/jsonl-bundle --freeze=var/embedding-fixtures/package-files/jsonl.jsonl.gz
php bin/console app:package-files:embed survos/api-grid-bundle --freeze=var/embedding-fixtures/package-files/api-grid.jsonl.gz
php bin/console ai:embed:consume --batch-size=8 --limit=200
php bin/console app:package-files:prove-cached var/embedding-fixtures/package-files/jsonl.jsonl.gz
php bin/console app:package-files:prove-cached var/embedding-fixtures/package-files/api-grid.jsonl.gz
php bin/console ai:embed:export var/embedding-fixtures/package-files/nomic-embeddings.jsonl.gz
```

Freeze/export commands refuse to overwrite existing archives. The proof requires a local dev database, unchanged file content and legacy vectors, the same model profile, and all requests ready. It deletes only that package's file rows, reloads frozen rows with DBAL inside a transaction (without enqueuing workflow messages), reconstructs projections, then invokes the real consumer and checks zero calls and identical vectors. Other model projections are retained. A failure rolls back the PostgreSQL reload. It does not purge package records or depend on fresh GitHub fetches.

Verified 2026-11-22:

| Fixture | Files | Chunks | Reload model calls |
| --- | ---: | ---: | ---: |
| JSONL bundle README + AGENTS | 2 | 90 | 0 |
| API Grid README + four docs | 5 | 66 | 0 |

All 156 vectors were generated locally in 20 Nomic batches. Both proofs retain the same call count (20) and identical projections. All 17,429 legacy package embedding/fingerprint rows were unchanged; their serialized snapshot SHA-256 is `2fd2601b355921fb98ffc85070db41a1196c9f8d094d45b3ea245c2d55146844`. Frozen source fixtures, original file/legacy backups and the vector export are retained under ignored `var/embedding-fixtures/package-files/`. Proof receipts are alongside this README.

The closed DuckDB file measured 4,206,592 bytes; the vector JSONL gzip measured 722,049 bytes. They are not equivalent archives: DuckDB also contains document/chunk mappings and queue/audit tables; the gzip export contains embedding inputs, profiles and vectors only.

## Later: OpenAI and shared snapshots

ai-embed-bundle already supports OpenAI batch submission/collection. Select a distinct OpenAI profile with matching tokenizer/prefix/dimensions, retain this DuckDB file, ensure these same files, submit/collect, then repopulate. No paid OpenAI calls were made for this pilot.

S3 exchange is now implemented by ai-embed-bundle's single `EmbeddingStorageService` command service. The shared private bucket is `survos-embeddings`, region `fsn1`, endpoint `https://fsn1.your-objectstorage.com`. Packages publishes under `survos-sites/packages/`. These locations are committed configuration; only the access/secret keys are in `.env.local`.

```sh
php bin/console embed:push survos-sites/packages
php bin/console embed:pull survos-sites/packages   # absent/identical local file only
php bin/console embed:merge survos-sites/packages # additive, conflicts roll back
```

Snapshots contain completed vectors and complete source/chunk mappings across profiles, excluding pending jobs and provider ownership. Immutable `.duckdb` and `.json` objects live under `snapshots/<sha256>` with a `latest.json` pointer. Pull and merge accept `--snapshot=<sha256>`. Merge stages and verifies the file locally rather than querying S3 directly. Existing local vectors are never force-overwritten.

Live verification uploaded all 156 vectors, pulled a separate DuckDB database, then restored both frozen fixtures with zero provider calls and identical projection hashes. The pull's provider-call count was zero before and after, confirming that no queue/audit state crossed machines. A merge into the original cache reused all 156 vectors. Receipts are stored alongside this document. Working cache files are still local; no fsn1 application deployment or persistent mount was changed.
