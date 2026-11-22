#!/usr/bin/env python3
"""Read-only clustering experiment. Reads a frozen JSONL gzip; never calls an API."""
import argparse
from collections import Counter
import gzip
import hashlib
import html
import json
from pathlib import Path
import platform
import re

import numpy as np
import sklearn
from sklearn.cluster import KMeans
from sklearn.feature_extraction.text import TfidfVectorizer, ENGLISH_STOP_WORDS
from sklearn.metrics import adjusted_rand_score, silhouette_score, silhouette_samples
from sklearn.preprocessing import normalize
from threadpoolctl import threadpool_limits

GENERIC = {'php', 'symfony', 'bundle', 'bundles', 'symfony-bundle', 'symfony bundle', 'framework', 'library', 'composer', 'extension'}

def keywords(values):
    return sorted({re.sub(r'\s+', ' ', str(v).strip().lower()) for v in values if str(v).strip()})

def specific(values):
    return [v for v in keywords(values) if v not in GENERIC and not re.fullmatch(r'(symfony|php)[ -]?[\d.x]+', v)]

def run(source):
    with gzip.open(source, 'rt') as f:
        records = [json.loads(line) for line in f if line.strip()]
    manifest, rows = records[0], records[1:]
    if manifest['type'] != 'manifest' or manifest['source'] != 'legacy-package-cache' or any(r['type'] != 'package' for r in rows):
        raise ValueError('Expected the validated cached-vector export')
    if len(rows) != manifest['sampleSize'] or len({r['name'] for r in rows}) != len(rows):
        raise ValueError('Sample count or identity mismatch')
    x = np.array([r['vector'] for r in rows], dtype=np.float64)
    if x.shape != (len(rows), manifest['profile']['dimensions']) or not np.isfinite(x).all() or np.any(np.linalg.norm(x, axis=1) == 0):
        raise ValueError('Invalid vectors')
    x = normalize(x)
    sweep = []
    fits = {}
    for k in range(4, min(12, len(rows) - 1) + 1):
        fit = KMeans(n_clusters=k, n_init=20, random_state=42).fit(x)
        fits[k] = fit
        sweep.append({'k': k, 'cosineSilhouette': float(silhouette_score(x, fit.labels_, metric='cosine'))})
    selected = max(sweep, key=lambda r: (r['cosineSilhouette'], -r['k']))
    k = selected['k']
    fit = fits[k]
    # Stable presentation numbers, independent of the arbitrary KMeans label integers.
    order = sorted(range(k), key=lambda c: min(rows[i]['name'] for i in range(len(rows)) if fit.labels_[i] == c))
    mapping = {c: i + 1 for i, c in enumerate(order)}
    labels = np.array([mapping[int(c)] for c in fit.labels_])
    silhouettes = silhouette_samples(x, labels, metric='cosine')
    similarities = x @ x.T
    centers = normalize(fit.cluster_centers_)
    stability = []
    for seed in [7, 19, 81]:
        alternate = KMeans(n_clusters=k, n_init=20, random_state=seed).fit_predict(x)
        stability.append({'seed': seed, 'adjustedRand': float(adjusted_rand_score(labels, alternate))})
    # The following metadata is consulted only AFTER vector clustering.
    specific_tags = [specific(r['keywords']) for r in rows]
    descriptions = [(r['description'] or '') + ' ' + r['name'].split('/')[-1].replace('-', ' ') for r in rows]
    stop = sorted(set(ENGLISH_STOP_WORDS) | GENERIC | {'symfony2', 'symfony3', 'symfony4', 'symfony5', 'symfony6', 'symfony7', 'symfony8', 'php5', 'php7', 'php8', 'use', 'using', 'provides', 'support', 'integration'})
    tfidf = TfidfVectorizer(stop_words=stop, min_df=2, token_pattern=r'(?u)\b[a-zA-Z][a-zA-Z0-9_-]+\b')
    terms = tfidf.fit_transform(descriptions)
    vocabulary = tfidf.get_feature_names_out()
    groups = []
    for c in order:
        ids = np.flatnonzero(fit.labels_ == c)
        ranked = sorted(ids, key=lambda i: (-float(x[i] @ centers[c]), rows[i]['name']))
        avg_terms = np.asarray(terms[ids].mean(axis=0)).ravel()
        top_terms = [str(vocabulary[i]) for i in np.argsort(-avg_terms)[:8] if avg_terms[i] > 0]
        kw = Counter(tag for i in ids for tag in specific_tags[i])
        members = []
        for i in ranked:
            members.append({key: rows[i][key] for key in ['name', 'description', 'keywords', 'readmeCharacters']} | {
                'specificKeywords': specific_tags[i], 'centroidCosine': float(x[i] @ centers[c]),
                'silhouette': float(silhouettes[i])})
        pairs = [(i, j) for ai, i in enumerate(ids) for j in ids[ai + 1:]]
        groups.append({'id': mapping[c], 'size': len(ids), 'descriptionTerms': top_terms,
            'meanSilhouette': float(np.mean(silhouettes[ids])),
            'medianReadmeCharacters': float(np.median([rows[i].get('readmeCharacters', 0) for i in ids])),
            'meanPairCosine': float(np.mean([similarities[i, j] for i, j in pairs])) if pairs else None,
            'noKeywords': sum(not rows[i]['keywords'] for i in ids),
            'noSpecificKeywords': sum(not specific_tags[i] for i in ids),
            'topKeywords': kw.most_common(10), 'members': members})
    # Keyword-only baseline cannot place records with no informative keywords.
    usable = [i for i, tags in enumerate(specific_tags) if tags]
    keyword_fit = TfidfVectorizer(analyzer=lambda tags: tags).fit_transform([specific_tags[i] for i in usable])
    baseline_labels = KMeans(n_clusters=k, n_init=20, random_state=42).fit_predict(keyword_fit)
    baseline = {'packages': len(usable), 'excludedWithoutSpecificKeywords': len(rows) - len(usable),
        'adjustedRandAgainstEmbeddings': float(adjusted_rand_score(labels[usable], baseline_labels)),
        'groups': [{'size': int(sum(baseline_labels == c)), 'names': [rows[usable[i]]['name'] for i in np.flatnonzero(baseline_labels == c)]} for c in range(k)]}
    comparable = [(i, j) for ai, i in enumerate(usable) for j in usable[ai + 1:]]
    within = [(i, j) for i, j in comparable if labels[i] == labels[j]]
    share = lambda pairs: sum(bool(set(specific_tags[i]) & set(specific_tags[j])) for i, j in pairs) / len(pairs) if pairs else None
    cross_keyword = sorted([(float(similarities[i, j]), i, j) for i in range(len(rows)) for j in range(i + 1, len(rows))
        if labels[i] == labels[j] and not set(specific_tags[i]) & set(specific_tags[j])], reverse=True)[:16]
    return {'manifest': manifest, 'fixtureSha256': hashlib.sha256(Path(source).read_bytes()).hexdigest(),
        'versions': {'python': platform.python_version(), 'numpy': np.__version__, 'scikitLearn': sklearn.__version__},
        'method': {'algorithm': 'KMeans on L2-normalized cached vectors', 'seed': 42, 'nInit': 20,
            'selection': 'Highest cosine silhouette among k=4..12; exploratory, not a validated taxonomy',
            'keywordsUsedForClustering': False, 'modelCalls': 0},
        'sweep': sweep, 'selected': selected, 'seedStability': stability,
        'keywordCoverage': {'withAny': sum(bool(r['keywords']) for r in rows), 'withSpecific': len(usable),
            'genericExcluded': sorted(GENERIC), 'withinClusterPairSharedSpecificKeyword': share(within),
            'allPairsSharedSpecificKeyword': share(comparable), 'comparableWithinPairs': len(within), 'comparableAllPairs': len(comparable)},
        'keywordBaseline': baseline, 'groups': groups,
        'closePairsWithoutSharedSpecificKeyword': [{'a': rows[i]['name'], 'b': rows[j]['name'], 'cosine': value,
            'keywordsA': rows[i]['keywords'], 'keywordsB': rows[j]['keywords'], 'cluster': int(labels[i])} for value, i, j in cross_keyword]}

def render(report, labels):
    esc = lambda v: html.escape(str(v), quote=True)
    n = report['manifest']['sampleSize']
    cards = []
    for group in report['groups']:
        title = labels.get(str(group['id']), ', '.join(group['descriptionTerms'][:3]))
        members = []
        for m in group['members']:
            tags = ', '.join(m['keywords']) or 'No Composer keywords'
            members.append(f'<tr><td><a href="https://packagist.org/packages/{esc(m["name"])}">{esc(m["name"])}</a><small>{esc(m["description"] or "")}</small></td><td>{esc(tags)}</td><td>{m["centroidCosine"]:.3f}</td></tr>')
        top = ', '.join(f'{tag} ({count})' for tag, count in group['topKeywords'])
        cards.append(f'<section><h2>{group["id"]}. {esc(title)} <span>{group["size"]} packages</span></h2><p>{group["noKeywords"]} without keywords · {group["noSpecificKeywords"]} without specific keywords · mean silhouette {group["meanSilhouette"]:.3f} · median README {group["medianReadmeCharacters"]:,.0f} characters</p><p><b>Composer keywords:</b> {esc(top or "none")}</p><details open><summary>Members, closest to centroid first</summary><table class="members"><thead><tr><th>Package and description</th><th>Existing Composer keywords</th><th>Cosine to centroid</th></tr></thead><tbody>{"".join(members)}</tbody></table></details></section>')
    metrics = ''.join(f'<tr><td>{r["k"]}</td><td>{r["cosineSilhouette"]:.4f}</td></tr>' for r in report['sweep'])
    cov = report['keywordCoverage']
    overview = ''.join(f'<tr><td>{g["id"]}. {esc(labels.get(str(g["id"]), ", ".join(g["descriptionTerms"][:3])))}</td><td>{g["size"]}</td><td>{g["noKeywords"]}</td><td>{g["medianReadmeCharacters"]:,.0f}</td></tr>' for g in report['groups'])
    return f'''<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Packages: cached embedding clusters</title>
<style>body{{font:16px/1.5 system-ui;margin:0;background:#f3f5f8;color:#172334}}main{{max-width:1150px;margin:auto;padding:36px 24px}}h1{{font-size:36px;line-height:1.15}}h2{{font-size:23px}}h2 span{{font-size:15px;color:#526178;margin-left:12px}}section,.intro{{background:white;padding:24px;margin:20px 0;border:1px solid #d8e0ea;border-radius:12px}}table{{border-collapse:collapse;width:100%;font-size:14px}}td,th{{text-align:left;padding:10px;border-bottom:1px solid #e1e6ee;vertical-align:top}}td:first-child{{width:50%}}small{{display:block;color:#526178;margin-top:5px}}a{{color:#1748a3}}input{{font:inherit;width:90%;padding:12px;border:1px solid #8c9aaa;border-radius:6px}}.note{{color:#526178}}summary{{cursor:pointer}}code{{overflow-wrap:anywhere}}</style>
<main><h1>What groups emerge from cached package embeddings?</h1><p>{n}-package pilot · 22 November 2026 · No new embeddings or model API calls</p>
<div class="intro"><p><b>{n} current cached vectors</b>, sampled by a fixed hash of package name. One pinned Nomic profile, using the existing whole-README pooled vectors—not the newer per-file cache. Composer keywords were withheld from clustering.</p>
<p>Selected <b>{report['selected']['k']} groups</b>; cosine silhouette <b>{report['selected']['cosineSilhouette']:.3f}</b>. Scores near zero indicate overlapping groups. These are candidate browsing groups, not verified tags.</p>
<p>{cov['withAny']}/{n} packages have any keywords; {cov['withSpecific']}/{n} have specific keywords after removing generic PHP/Symfony/bundle terms. Among packages with specific keywords, shared-keyword pairs occur at {100*cov['withinClusterPairSharedSpecificKeyword']:.1f}% within embedding groups versus {100*cov['allPairsSharedSpecificKeyword']:.1f}% across the sample.</p>
<h2>Emerging groups</h2><table><thead><tr><th>Interpretation after inspecting members</th><th>Packages</th><th>No keywords</th><th>Median README characters</th></tr></thead><tbody>{overview}</tbody></table><p><b>Pilot finding:</b> the Sonata/admin/content ecosystem is recognizable, but broad groups mix capabilities and one group largely contains README stubs. The result supports further experimentation, not automatic tag assignment. Short source text, pooled vectors and shared ecosystem vocabulary are plausible contributors; this experiment does not isolate their effects.</p><p>Labels are human interpretations of package descriptions, applied after clustering. They are not generated embeddings or ground truth. Cosine to centroid indicates representativeness, not confidence in a tag.</p><input id="filter" placeholder="Filter packages, descriptions, or Composer keywords" aria-label="Filter packages"><p class="note">Full membership below; nothing is hidden to make a group look better.</p></div>
{''.join(cards)}
<section><h2>Method and checks</h2><p>KMeans, 20 starts, random seed 42, L2-normalized 768-dimensional vectors. Compared k=4–12 using cosine silhouette. Stability at three other seeds (adjusted Rand): {', '.join(format(s['adjustedRand'], '.3f') for s in report['seedStability'])}. Keywords-only clustering agrees with these groups at adjusted Rand {report['keywordBaseline']['adjustedRandAgainstEmbeddings']:.3f}, on {report['keywordBaseline']['packages']} packages with informative keywords. Agreement is not accuracy.</p><table><tr><th>k</th><th>Cosine silhouette</th></tr>{metrics}</table><p>Sampling seed: <code>{esc(report['manifest']['seed'])}</code><br>Fixture SHA-256: <code>{report['fixtureSha256']}</code></p><p><a href="https://scikit-learn.org/stable/modules/generated/sklearn.metrics.silhouette_score.html">Silhouette definition and interpretation</a></p></section></main>
<script>document.getElementById('filter').addEventListener('input',e=>{{const q=e.target.value.toLowerCase();document.querySelectorAll('table.members tbody tr').forEach(r=>r.hidden=!r.textContent.toLowerCase().includes(q));}});</script></html>'''

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('fixture', type=Path)
    parser.add_argument('output', type=Path)
    parser.add_argument('--labels', type=Path)
    args = parser.parse_args()
    with threadpool_limits(limits=1):
        report = run(args.fixture)
    labels = json.loads(args.labels.read_text()) if args.labels else {}
    args.output.mkdir(parents=True, exist_ok=True)
    report['interpretiveLabels'] = labels
    (args.output / 'clusters.json').write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
    (args.output / 'clusters.html').write_text(render(report, labels))
    print(json.dumps({key: report[key] for key in ['selected', 'keywordCoverage', 'seedStability']}, indent=2))
    for group in report['groups']:
        print('\nGROUP', group['id'], group['size'], group['descriptionTerms'])
        for row in group['members'][:10]:
            print(row['name'], '|', row['description'], '|', row['keywords'])
