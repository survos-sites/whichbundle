<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\Package;
use App\Service\PackageEmbeddingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Retrieval over the package catalog, shared by the chat (/ask) and the MCP server.
 *
 * find() merges two Elasticsearch rankings with reciprocal rank fusion: keywords over every
 * package (name, description, README) and, when the query can be embedded, kNN over the README
 * vectors of the documented packages. If the embedding service is down, keywords still answer.
 * Hits are hydrated from the database, so every fact the chat repeats is a Package row.
 */
final readonly class PackageFinder
{
    private const int RRF_K = 60;
    private const int README_EXCERPT = 6000;

    private string $es;

    public function __construct(
        private HttpClientInterface $http,
        private EntityManagerInterface $em,
        private PackageEmbeddingService $embeddings,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
        #[Autowire(env: 'ELASTICSEARCH_DSN')] string $dsn,
        #[Autowire(env: 'SEARCH_INDEX_PREFIX')] private string $prefix,
    ) {
        $this->es = rtrim((string) preg_replace('#^elasticsearch://#', 'http://', $dsn), '/');
    }

    /**
     * @param bool        $bundlesOnly     only packages of type symfony-bundle
     * @param bool        $includeRejected also packages that fail the catalog's PHP/Symfony requirements
     * @param string|null $symfony         only packages that support this Symfony major.minor, e.g. "7.4"
     *
     * @return array{semantic: bool, results: list<array<string, mixed>>}
     */
    public function find(string $question, int $limit = 10, bool $bundlesOnly = false, bool $includeRejected = false, ?string $symfony = null): array
    {
        $limit = max(1, min(25, $limit));
        $depth = $limit * 4;
        $filters = $includeRejected ? [] : [['bool' => ['must_not' => [['term' => ['state.keyword' => 'rejected']]]]]];
        if (null !== $symfony && '' !== $symfony) {
            $filters[] = ['term' => ['symfonyVersions' => $symfony]];
        }

        $lexical = $this->ids($this->prefix.'package', [
            'size' => $depth,
            '_source' => false,
            'query' => ['bool' => [
                'must' => [['multi_match' => [
                    'query' => $question,
                    'fields' => ['name^4', 'shortName^4', 'description^3', 'readme'],
                    'type' => 'best_fields',
                ]]],
                'filter' => $filters,
            ]],
        ]);

        $semantic = [];
        $embedded = false;
        try {
            $vector = $this->embeddings->embed($question);
            $embedded = true;
            $semantic = $this->ids($this->prefix.'package_semantic', [
                'size' => $depth,
                '_source' => false,
                'knn' => ['field' => 'embedding', 'query_vector' => $vector, 'k' => $depth, 'num_candidates' => 400, 'filter' => $filters ?: null],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Package search fell back to keywords: {message}', ['message' => $e->getMessage()]);
        }

        $scores = [];
        foreach (['keywords' => $lexical, 'meaning' => $semantic] as $source => $ids) {
            foreach ($ids as $rank => $id) {
                $scores[$id]['score'] = ($scores[$id]['score'] ?? 0) + 1 / (self::RRF_K + $rank + 1);
                $scores[$id]['matchedBy'][] = $source;
            }
        }
        uasort($scores, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $packages = $this->load(array_keys($scores));
        $results = [];
        foreach ($scores as $id => $hit) {
            $package = $packages[$id] ?? null;
            if (null === $package || ($bundlesOnly && !$package->isSymfonyBundle)) {
                continue;
            }
            $results[] = $this->summary($package) + ['matchedBy' => $hit['matchedBy']];
            if (count($results) >= $limit) {
                break;
            }
        }

        return ['semantic' => $embedded, 'results' => $results];
    }

    /** @return array<string, mixed>|null */
    public function get(string $name): ?array
    {
        $name = trim($name);
        $package = $this->em->find(Package::class, str_contains($name, '/') ? Package::idFromName($name) : $name);
        if (null === $package) {
            return null;
        }
        $readme = trim((string) $package->readme);

        return $this->summary($package) + [
            'keywords' => $package->keywords,
            'phpConstraint' => $package->phpVersionString,
            'symfonyConstraint' => $package->symfonyVersionString,
            'require' => $package->data['require'] ?? null,
            'homepage' => $package->data['homepage'] ?? null,
            'source' => $package->sourceUrl,
            'replacement' => $package->replacement,
            'hasAgentsMd' => $package->hasAgentsMd,
            'readme' => mb_strlen($readme) > self::README_EXCERPT ? mb_substr($readme, 0, self::README_EXCERPT).' […]' : ($readme ?: null),
        ];
    }

    /**
     * Search packagist.org itself, for packages this catalog does not hold (plain libraries are only
     * partly catalogued). Each hit says whether the catalog has it.
     *
     * @return list<array<string, mixed>>
     */
    public function searchPackagist(string $query, ?string $type = null, int $limit = 10): array
    {
        $query = ['q' => $query, 'per_page' => max(1, min(30, $limit))];
        if (null !== $type && '' !== $type) {
            $query['type'] = $type;
        }
        $data = $this->http->request('GET', 'https://packagist.org/search.json', ['query' => $query, 'timeout' => 10])->toArray();
        $names = array_column($data['results'] ?? [], 'name');
        $known = $this->load(array_map(Package::idFromName(...), $names));

        return array_map(fn (array $r) => [
            'name' => $r['name'],
            'description' => $r['description'] ?? null,
            'downloads' => $r['downloads'] ?? null,
            'favers' => $r['favers'] ?? null,
            'abandoned' => (bool) ($r['abandoned'] ?? false),
            'packagist' => $r['url'] ?? 'https://packagist.org/packages/'.$r['name'],
            'inCatalog' => isset($known[Package::idFromName($r['name'])]),
        ], $data['results'] ?? []);
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        $db = $this->em->getConnection();
        $states = $db->fetchAllKeyValue('SELECT state, count(*) FROM package GROUP BY state ORDER BY 2 DESC');
        $reasons = $db->fetchAllKeyValue("SELECT requirements_failure_reason, count(*) FROM package WHERE state = 'rejected' GROUP BY 1 ORDER BY 2 DESC");

        return [
            'packages' => array_sum($states),
            'byState' => $states,
            'symfonyBundles' => (int) $db->fetchOne("SELECT count(*) FROM package WHERE data->>'type' = 'symfony-bundle'"),
            'withReadmeVector' => (int) $db->fetchOne('SELECT count(*) FROM package WHERE embedding IS NOT NULL'),
            'rejectedBecause' => $reasons,
            'note' => 'documented = meets the PHP/Symfony requirements and its README was read; rejected = fails them (rejectedBecause).',
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Package $package): array
    {
        return [
            'name' => $package->name,
            'description' => $package->description,
            'type' => $package->type,
            'downloads' => $package->downloads,
            'stars' => $package->stars,
            'symfony' => $package->symfonyVersions ?: null,
            'php' => $package->phpVersions ?: null,
            'lastRelease' => $package->lastUpdatedOnPackagist?->format('Y-m-d'),
            'abandoned' => $package->isAbandoned,
            'status' => $package->state,
            'rejected' => 'rejected' === $package->state ? $package->rejectionLabel : null,
            'url' => $this->urls->generate('package_show', ['packageId' => $package->id], UrlGeneratorInterface::ABSOLUTE_URL),
            'packagist' => 'https://packagist.org/packages/'.$package->name,
        ];
    }

    /** @return list<string> document ids, best first */
    private function ids(string $index, array $body): array
    {
        $body = array_filter($body, static fn ($v) => null !== $v);
        if (isset($body['knn']['filter']) && null === $body['knn']['filter']) {
            unset($body['knn']['filter']);
        }
        $data = $this->http->request('POST', $this->es.'/'.$index.'/_search', ['json' => $body, 'timeout' => 10])->toArray();

        return array_map(static fn (array $hit) => (string) $hit['_id'], $data['hits']['hits'] ?? []);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, Package>
     */
    private function load(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $packages = [];
        foreach ($this->em->getRepository(Package::class)->findBy(['id' => $ids]) as $package) {
            $packages[$package->id] = $package;
        }

        return $packages;
    }
}
