<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use Doctrine\ORM\EntityManagerInterface;
use Survos\SearchBundle\Contract\EmbeddingProviderInterface;
use Survos\SearchBundle\Search\EmbeddingProfile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PackageEmbeddingService implements EmbeddingProviderInterface
{
    /** OpenAI text-embedding-3-small at its native size. */
    public const int DIMENSIONS = 1536;
    private const string ENDPOINT = 'https://api.openai.com/v1/embeddings';
    /** Characters per chunk: well under the model's 8,191-token input limit, even for dense code. */
    private const int CHUNK = 6000;
    public readonly EmbeddingProfile $profile;

    public function __construct(private HttpClientInterface $http, private EntityManagerInterface $em,
        #[\SensitiveParameter] #[Autowire(env: 'OPENAI_API_KEY')] private string $apiKey,
        #[Autowire(env: 'PACKAGE_EMBED_MODEL')] string $model,
        #[Autowire(env: 'PACKAGE_EMBED_REVISION')] string $revision)
    {
        // Changing the model, the size, the revision or the text recipe makes every stored vector stale.
        $this->profile = new EmbeddingProfile($model, self::DIMENSIONS, $revision, 'readme-v2-chunks-6000-mean');
    }

    public static function text(Package $package): string
    {
        return trim($package->name."\n".$package->description."\n".preg_replace('/\s+/u', ' ', $package->readme ?? ''));
    }

    public static function validVector(?array $vector): bool
    {
        return $vector !== null && array_is_list($vector) && count($vector) === self::DIMENSIONS
            && array_all($vector, static fn ($v): bool => (is_int($v) || is_float($v)) && is_finite((float) $v))
            && array_any($vector, static fn ($v): bool => $v != 0);
    }

    public function cachedVector(Package $package): ?array
    {
        return $package->state === 'documented' && $this->isCurrent($package) ? $package->embedding : null;
    }

    public function isCurrent(Package $package): bool
    {
        return $package->hasReadme
            && $package->embeddingFingerprint === $this->profile->fingerprint(self::text($package))
            && self::validVector($package->embedding);
    }

    public function embed(string $text): array
    {
        return $this->generate($text);
    }

    public function vectorize(Package $package): void
    {
        if (!$package->hasReadme) {
            $package->embedding = null;
            $package->embeddingFingerprint = null;
            return;
        }
        if ($this->isCurrent($package)) {
            return;
        }
        $text = self::text($package);
        $package->embedding = $this->generate($text);
        $package->embeddingFingerprint = $this->profile->fingerprint($text);
    }

    private function generate(string $text): array
    {
        if ('' === $this->apiKey) {
            throw new \RuntimeException('OPENAI_API_KEY is not set; README embeddings need it.');
        }
        // Include the entire README, in bounded chunks. No silent model truncation.
        $chunks = mb_str_split($text, self::CHUNK, 'UTF-8');
        $sum = array_fill(0, self::DIMENSIONS, 0.0);
        foreach (array_chunk($chunks, 32) as $batch) {
            $result = $this->http->request('POST', self::ENDPOINT, ['auth_bearer' => $this->apiKey, 'json' => [
                'model' => $this->profile->model, 'input' => $batch,
                'dimensions' => self::DIMENSIONS, 'encoding_format' => 'float',
            ], 'timeout' => 120, 'max_duration' => 180])->toArray();
            $vectors = [];
            foreach ($result['data'] ?? [] as $item) {
                $vectors[$item['index'] ?? count($vectors)] = $item['embedding'] ?? null;
            }
            if (count($vectors) !== count($batch)) {
                throw new \UnexpectedValueException('Embedding response did not cover every chunk.');
            }
            foreach ($batch as $i => $chunk) {
                $vector = $vectors[$i] ?? null;
                if (!self::validVector($vector)) {
                    throw new \UnexpectedValueException('Invalid embedding vector.');
                }
                $weight = mb_strlen($chunk);
                foreach ($vector as $j => $value) {
                    $sum[$j] += $value * $weight;
                }
            }
        }
        $norm = sqrt(array_sum(array_map(static fn ($v) => $v * $v, $sum)));
        if ($norm == 0 || !is_finite($norm)) {
            throw new \UnexpectedValueException('Invalid pooled embedding.');
        }
        return array_map(static fn ($v) => $v / $norm, $sum);
    }

    #[AsCommand('app:packages:embed', 'Generate missing/stale README vectors from stored content; safely resumable')]
    public function backfill(SymfonyStyle $io, #[Option('Generate at most this many vectors; zero means all')] int $limit = 0): int
    {
        if ($limit < 0) { throw new \InvalidArgumentException('Limit must be nonnegative.'); }
        $generated = $reused = 0;
        foreach ($this->em->createQuery("SELECT p FROM App\\Entity\\Package p WHERE p.state = 'documented' ORDER BY p.id")->toIterable() as $package) {
            if (!$package->hasReadme) { $this->em->detach($package); continue; }
            if ($this->cachedVector($package) !== null) {
                ++$reused;
            } else {
                $this->vectorize($package);
                $this->em->getConnection()->update('package', [
                    'embedding' => json_encode($package->embedding, JSON_THROW_ON_ERROR),
                    'embedding_fingerprint' => json_encode($package->embeddingFingerprint, JSON_THROW_ON_ERROR),
                ], ['id' => $package->id]);
                ++$generated;
                if ($generated % 100 === 0) { $io->writeln('Generated '.$generated); }
            }
            $this->em->detach($package);
            if ($limit && $generated >= $limit) { break; }
        }
        $io->success(sprintf('Generated %d; reused %d. Rebuild the semantic index to publish these vectors.', $generated, $reused));
        return 0;
    }
}
