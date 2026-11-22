<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Disposable projection; durable input/model/vector provenance lives in DuckDB. */
#[ORM\Entity]
#[ORM\Table(name: 'package_file_embedding')]
class PackageFileEmbedding
{
    #[ORM\Id, ORM\Column(length: 64)]
    public string $id;
    #[ORM\ManyToOne(targetEntity: PackageFile::class)]
    #[ORM\JoinColumn(name: 'file_id', nullable: false, onDelete: 'CASCADE')]
    public PackageFile $file;
    #[ORM\Column(length: 64)]
    public string $profileId;
    #[ORM\Column(length: 64)]
    public string $requestId;
    #[ORM\Column(length: 64)]
    public string $documentId;
    #[ORM\Column]
    public int $ordinal;
    #[ORM\Column]
    public int $charStart;
    #[ORM\Column]
    public int $charEnd;
    #[ORM\Column(type: Types::TEXT)]
    public string $heading;
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $vector;
}
