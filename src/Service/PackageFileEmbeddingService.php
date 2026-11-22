<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PackageFile;
use Doctrine\ORM\EntityManagerInterface;
use Survos\AiEmbedBundle\EmbeddingService;

final readonly class PackageFileEmbeddingService
{
    public function __construct(private EntityManagerInterface $em, private MarkdownSections $sections,
        public ?EmbeddingService $embeddings = null)
    {
    }

    public static function path(PackageFile $file): string
    {
        return $file->path ?? match ($file->kind) {
            PackageFile::README => 'README.md', PackageFile::AGENTS => 'AGENTS.md',
            default => throw new \LogicException('Document path missing.'),
        };
    }

    /** Ensure never performs HTTP. Model-specific projections can be rebuilt at any time. */
    public function ensure(PackageFile $file): array
    {
        if (!$this->embeddings) {
            return ['enabled' => false, 'chunks' => 0, 'pending' => 0, 'cacheHits' => 0];
        }
        $profile = $this->embeddings->profile;
        $rows = [];
        $pending = 0;
        $path = self::path($file);
        foreach ($this->sections->split($file->content) as $section) {
            $text = mb_substr($file->content, $section['start'], $section['end'] - $section['start'], 'UTF-8');
            if (trim($text) === '') { continue; }
            // Bound repeated heading context; full original heading remains in the source text.
            $title = mb_substr($file->package->name.' / '.$path.' / '.$section['heading'], 0, 240, 'UTF-8');
            $reference = $file->package->name.'/'.$path.'#'.MarkdownSections::VERSION.':'.$section['start'];
            $doc = $this->embeddings->ensureDocumentEmbedding($reference, $title, $text);
            foreach ($this->embeddings->documentChunks($doc['documentId']) as $chunk) {
                $ordinal = count($rows);
                $vector = $this->embeddings->cache->vector($chunk['request_id'], $profile);
                $pending += $vector === null ? 1 : 0;
                $rows[] = ['id' => hash('sha256', $file->id.$profile->id().$ordinal), 'file_id' => $file->id,
                    'profile_id' => $profile->id(), 'request_id' => $chunk['request_id'], 'document_id' => $doc['documentId'],
                    'ordinal' => $ordinal, 'char_start' => $section['start'] + $chunk['char_start'],
                    'char_end' => $section['start'] + $chunk['char_end'], 'heading' => $section['heading'],
                    'vector' => $vector === null ? null : json_encode($vector, JSON_THROW_ON_ERROR)];
            }
        }
        $db = $this->em->getConnection();
        $db->transactional(function () use ($db, $file, $profile, $rows): void {
            $db->delete('package_file_embedding', ['file_id' => $file->id, 'profile_id' => $profile->id()]);
            foreach ($rows as $row) {
                if ($row['vector'] !== null) { $db->insert('package_file_embedding', $row); }
            }
        });
        return ['enabled' => true, 'chunks' => count($rows), 'pending' => $pending, 'cacheHits' => count($rows) - $pending];
    }
}
