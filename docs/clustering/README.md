# Cached package clustering pilot — 2026-11-22

This is a read-only experiment, not a tag-writing feature. It uses existing package-level Nomic vectors; the newer per-file DuckDB fixture currently covers only two packages and would not make a meaningful package-clustering sample. No embeddings were generated and no model API was called.

## Method

`app:packages:cluster-export` scans documented packages with existing vectors, checks the current text/model fingerprint and vector shape through `PackageEmbeddingService::isCurrent()`, then selects the 192 smallest SHA-256 hashes of `packages-clustering-v1 + NUL + package name`. No keywords or topics influence selection. All 8,145 candidate vectors passed the freshness check. Export runs in a repeatable-read, read-only PostgreSQL transaction and uses jsonl-bundle's gzip writer.

The shared profile is `nomic-embed-text:latest`, pinned to digest `0a109f422b47e3a30ba2b10eca18548e944e8a23073ee3f3e947efcf3c45e59f`, 768 dimensions, representation `readme-v1-chunks-1500-mean`. That existing representation averages 1,500-character README chunks; the experiment does not change it or combine it with the newer per-file vectors.

`tools/cluster_packages.py` L2-normalizes the vectors and runs scikit-learn KMeans (20 starts, seed 42), comparing k=4–12 with cosine silhouette. It picks the highest score in that exploratory range. The script then compares the groups with Composer keywords and repeats the selected k with seeds 7, 19 and 81. Keywords are never clustering features. Human-readable group labels were written **after** inspecting members and are interpretations, not ground truth.

## Findings

The selected k was **6**, with cosine silhouette **0.0546**: weak separation. Alternative-seed adjusted Rand scores were **0.337, 0.352, 0.344**: membership is sensitive to initialization. This is not a reliable automatic taxonomy.

| Group interpretation | Packages | Without keywords | Median README characters |
| --- | ---: | ---: | ---: |
| Twig/UI/filesystem helpers, mixed | 33 | 10 | 1,417 |
| General integrations/libraries, broad catch-all | 90 | 26 | 4,717.5 |
| Very short README stubs, not a capability category | 13 | 3 | 44 |
| Service adapters/operational tools, mixed | 37 | 13 | 1,619 |
| Sonata/admin/content tooling, clearest theme | 15 | 3 | 1,570 |
| Small mixed group, no defensible topic | 4 | 1 | 465 |

The Sonata group includes `rikudou/sonata-formatter-bundle-fork`, `dkartasis8bitgroup/sonata-translation-bundle`, `awaresoft/sonata-block-bundle`, `sherlockode/sonata-modular-bundle`, and `devilcius/admin-menu-bundle`. The first two list `sonata`; Awaresoft and Sherlockode have no Composer keywords. It also includes unrelated Doctrine and chat/spelling utilities, so even the clearest group needs review.

Local neighbors can be useful even inside a weak global group: `likeuntomurphy/serverless-ogm` and `shedeza/sybase-orm` are both ORMs with no keywords (cosine 0.865). `simplethings/entity-audit-bundle` and `bentools/doctrine-changeset` concern Doctrine state/change tracking but share no specific keyword (cosine 0.841). High cosine is not itself proof of equivalent functionality: the REST and user BaconManager bundles are also very close (0.883), despite different functions and extremely thin READMEs.

## Composer keyword comparison

- **136/192** have any keywords; **56/192 (29.2%)** have none.
- **134/192** have a non-generic keyword after excluding PHP/Symfony/bundle/library/framework/composer terms and basic version variants. Exact normalization/exclusions are recorded in results.json and the script.
- Among pairs where both packages have specific keywords, **1.93%** of within-cluster pairs share a keyword, versus **1.38%** of all comparable pairs. That is only a modest association; keyword synonyms and sparse tags also limit this measure.
- A separate keyword-only TF-IDF/KMeans baseline, on the 134 usable records and the same k, has adjusted Rand agreement **0.020** with the embedding groups. This measures disagreement, not which method is correct. Keywords are not a trustworthy ground-truth taxonomy.

README length and documentation style are plausible contributors to the weak grouping. The 44-character median in the stub group is directly measured; sample source reads included `# BaconRestBundle` and `# Proxia CMS SDK bundle`. This experiment does not isolate causality or compare embedding models.

## Reproduce

```sh
mkdir -p var/experiments/clustering
php bin/console app:packages:cluster-export var/experiments/clustering/packages-192.jsonl.gz
uv venv --python 3.13 var/experiments/clustering/.venv
uv pip install --python var/experiments/clustering/.venv/bin/python -r tools/clustering-requirements.txt
var/experiments/clustering/.venv/bin/python tools/cluster_packages.py \
  var/experiments/clustering/packages-192.jsonl.gz \
  var/experiments/clustering/report --labels docs/clustering/labels.json
```

The export refuses to overwrite a frozen fixture. The exact analyzed fixture is retained locally at `var/experiments/clustering/packages-192-with-structure.jsonl.gz`; the earlier fixture has identical selected names, fingerprints and vector values, verified before the final run. Results include the analyzed file's SHA-256 and runtime versions. Selection remains deterministic for an unchanged eligible corpus; new or updated packages can change a future sample. Group labels in labels.json apply to this fixture, not arbitrary new samples.

Full group membership, seed checks, keyword baseline, and closest pairs are in results.json. The generated clusters.html is a self-contained, filterable view of every member and its original Composer keywords. Vectors and package records were not rewritten; the full legacy embedding snapshot checksum remained unchanged. No new clustering dependencies were added to PHP or production services; Python tooling lives in an ignored local virtual environment.

Next experiment: generate capability-focused, paragraph/token-aware representations for a bounded, adequately documented subset, excluding installation/license boilerplate. Compare against this baseline rather than publishing inferred tags now. Newspaper clustering should likewise operate on actual article text, with layout and editorial prominence as separate metadata.

References: [KMeans](https://scikit-learn.org/stable/modules/generated/sklearn.cluster.KMeans.html), [silhouette interpretation](https://scikit-learn.org/stable/modules/generated/sklearn.metrics.silhouette_score.html), [clustering evaluation](https://scikit-learn.org/stable/modules/clustering.html).
