<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Entity\PackageFile;
use App\Service\MarkdownSections;
use PHPUnit\Framework\TestCase;
use Survos\AiEmbedBundle\Chunking\TokenChunker;

final class MarkdownSectionsTest extends TestCase
{
    public function testUnicodeOffsetsCoverOriginalTextAndIgnoreFencedHeadings(): void
    {
        $text = "Préface 日本語\n\n# Install\n\n```sh\n# not a section\ncomposer require demo\n```\n\n## Configuration\n\n".str_repeat('Paragraph about configuration. ', 600)."\n\n# Usage\nFin.\n";
        $sections = (new MarkdownSections())->split($text);
        self::assertSame(['', 'Install', 'Install / Configuration', 'Usage'], array_column($sections, 'heading'));
        $reconstructed = '';
        $chunks = [];
        foreach ($sections as $section) {
            $body = mb_substr($text, $section['start'], $section['end'] - $section['start'], 'UTF-8');
            $reconstructed .= $body;
            foreach ((new TokenChunker())->chunks('acme/demo README.md '.$section['heading'], $body, 'nomic-embed-text:v1.5', 1800) as $chunk) {
                self::assertLessThanOrEqual(1800, $chunk['tokenCount']);
                $chunks[] = $chunk;
            }
        }
        self::assertSame($text, $reconstructed);
        self::assertGreaterThan(count($sections), count($chunks), 'Long sections must still be token bounded.');
    }

    public function testSameTextInDifferentDocumentationPathsHasDifferentFileIdentity(): void
    {
        $package = new Package('acme/demo');
        $a = new PackageFile($package, PackageFile::DOCUMENT, 'docs/a.md', 'Same content');
        $b = new PackageFile($package, PackageFile::DOCUMENT, 'docs/b.md', 'Same content');
        self::assertNotSame($a->id, $b->id);
        self::assertSame($a->sha, $b->sha);
        $readme = new PackageFile($package, PackageFile::README, 'README.md', 'Same content');
        self::assertSame(PackageFile::idFor($package, PackageFile::README, $a->sha), $readme->id, 'Existing IDs stay valid.');
    }

    public function testRepositoryPathsCannotEscapeTheRepository(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PackageFile(new Package('acme/demo'), PackageFile::DOCUMENT, '../private.md', 'text');
    }
}
