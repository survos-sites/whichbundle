<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\PackageFile;
use App\Service\PackageFileEmbeddingService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostPersistEventArgs;

#[AsDoctrineListener(event: Events::postPersist)]
final readonly class PackageFileEmbeddingListener
{
    public function __construct(private PackageFileEmbeddingService $embeddings) {}
    public function postPersist(PostPersistEventArgs $event): void
    {
        if (($file = $event->getObject()) instanceof PackageFile) {
            $this->embeddings->ensure($file);
        }
    }
}
