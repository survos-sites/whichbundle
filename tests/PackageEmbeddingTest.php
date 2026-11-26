<?php

declare(strict_types=1);
namespace App\Tests;
use App\Entity\Package;
use App\Service\PackageEmbeddingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
final class PackageEmbeddingTest extends TestCase
{
    public function testFullReadmeIsChunkedCachedAndInvalidated(): void
    {
        $calls = 0;
        $input = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$calls, &$input) {
            self::assertSame('https://api.openai.com/v1/embeddings', $url);
            self::assertContains('Authorization: Bearer key', $options['headers']);
            ++$calls;
            $body = json_decode($options['body'], true);
            self::assertSame('model', $body['model']);
            self::assertSame(1536, $body['dimensions']);
            $input = $body['input'];
            $vector = array_fill(0, 1536, 0.0); $vector[0] = 1.0;
            // Returned out of order: the index, not the position, names the chunk.
            return new MockResponse(json_encode(['data' => array_reverse(array_map(static fn ($i) => ['index' => $i, 'embedding' => $vector], array_keys($input)))]));
        });
        $service = new PackageEmbeddingService($http, $this->createStub(EntityManagerInterface::class), 'key', 'model', 'rev');
        $p = new Package('acme/demo'); $p->state = 'documented'; $p->readme = str_repeat('Long documentation ', 400).'THE END';
        $service->vectorize($p);
        self::assertCount(1536, $p->embedding);
        self::assertCount(2, $input, 'A long README is sent whole, in chunks.');
        self::assertStringEndsWith('THE END', end($input));
        self::assertStringStartsWith('acme/demo', $input[0]);
        self::assertSame($p->embedding, $service->cachedVector($p));
        $service->vectorize($p); self::assertSame(1, $calls);
        $p->state = 'accepted'; self::assertTrue($service->isCurrent($p)); self::assertNull($service->cachedVector($p));
        $p->state = 'documented'; $p->readme .= ' Changed'; self::assertNull($service->cachedVector($p));
        $p->embedding = array_fill(0, 1536, 0.0); self::assertFalse(PackageEmbeddingService::validVector($p->embedding));
        $p->embedding[0] = INF; self::assertFalse(PackageEmbeddingService::validVector($p->embedding));
    }

    public function testAMissingKeyIsReportedBeforeAnyRequest(): void
    {
        $service = new PackageEmbeddingService(new MockHttpClient(), $this->createStub(EntityManagerInterface::class), '', 'model', 'rev');
        $this->expectExceptionMessage('OPENAI_API_KEY is not set');
        $service->embed('how do I log to a file');
    }
}
