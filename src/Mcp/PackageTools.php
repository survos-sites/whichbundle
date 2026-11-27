<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Catalog\PackageFinder;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/** Read-only MCP tools over the package catalog. The chat (/ask) calls the same methods. */
final readonly class PackageTools
{
    public function __construct(private PackageFinder $finder)
    {
    }

    /**
     * Find packages for a need, in plain words, for example "TEI XML encoding", "stream large XML files
     * with low memory", "Meilisearch for Symfony" or "convert markdown to HTML". Ranks by keywords over
     * name, description and README, and by meaning when the embedding service is up ("semantic" says
     * which). Each result has name, description, type (symfony-bundle or library), downloads, stars,
     * supported Symfony and PHP versions, last release date, abandoned, status and links. Only packages
     * for PHP 8.4+ and Symfony 8.1+ are returned (Symfony-free libraries count when their PHP fits).
     *
     * @param string      $question        What the package should do, in plain words
     * @param int         $limit           Maximum number of packages (1 to 25)
     * @param bool        $bundlesOnly     Only Symfony bundles (type symfony-bundle), not plain libraries
     * @param bool        $includeRejected Also packages that fail the catalog's PHP/Symfony requirements (outdated)
     * @param string|null $symfony         Only packages supporting this Symfony version, "8.1" or "8.2"
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'find_packages', annotations: new ToolAnnotations(title: 'Find packages for a need', readOnlyHint: true, openWorldHint: false))]
    public function find(string $question, int $limit = 10, bool $bundlesOnly = false, bool $includeRejected = false, ?string $symfony = null): array
    {
        try {
            return $this->finder->find($question, $limit, $bundlesOnly, $includeRejected, $symfony);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $e) {
            throw new ToolCallException('The catalog search (Elasticsearch) is unavailable right now ('.$e->getMessage().'). search_packagist still works.', previous: $e);
        }
    }

    /**
     * Read one package: everything find_packages returns, plus keywords, PHP and Symfony constraints,
     * its require list, homepage, source repository, replacement when abandoned, and the start of its README.
     *
     * @param string $name Package name, e.g. "prewk/xml-string-streamer"
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_package', annotations: new ToolAnnotations(title: 'Get a package', readOnlyHint: true, openWorldHint: false))]
    public function get(string $name): array
    {
        return $this->finder->get($name) ?? throw new ToolCallException(sprintf('"%s" is not in the catalog. Try search_packagist, which searches packagist.org itself.', $name));
    }

    /**
     * Search packagist.org itself, for packages the catalog does not hold. The catalog covers Symfony
     * bundles and the libraries they use, not every PHP library, so use this when find_packages has no
     * good answer, or to check for well-known libraries. Results say whether the catalog has each one
     * (inCatalog); only catalogued packages have checked Symfony/PHP support.
     *
     * @param string      $query Words to search for on Packagist
     * @param string|null $type  Only this Composer type, e.g. "symfony-bundle" or "library"
     * @param int         $limit Maximum number of packages (1 to 30)
     *
     * @return list<array<string, mixed>>
     */
    #[McpTool(name: 'search_packagist', annotations: new ToolAnnotations(title: 'Search packagist.org', readOnlyHint: true, openWorldHint: true))]
    public function searchPackagist(string $query, ?string $type = null, int $limit = 10): array
    {
        try {
            return $this->finder->searchPackagist($query, $type, $limit);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $e) {
            throw new ToolCallException('packagist.org did not answer ('.$e->getMessage().').', previous: $e);
        }
    }

    /**
     * Exact catalog counts: packages by status, Symfony bundles, packages with README vectors, and why
     * packages were rejected. Use it for "how many" questions.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'catalog_stats', annotations: new ToolAnnotations(title: 'Catalog counts', readOnlyHint: true, openWorldHint: false))]
    public function stats(): array
    {
        return $this->finder->stats();
    }
}
