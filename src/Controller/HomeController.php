<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Package;
use Doctrine\DBAL\Connection;
use Survos\DocBundle\Attribute\Screenshot;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** What whichBundle is and what it runs on. The faceted search is at /search. */
final class HomeController extends AbstractController
{
    #[Screenshot('home', caption: 'About whichBundle')]
    #[Route('/', name: 'app_homepage', methods: ['GET'])]
    public function index(Connection $db): Response
    {
        $counts = $db->fetchAssociative(<<<'SQL'
            SELECT count(*) AS harvested,
                   count(*) FILTER (WHERE data->>'type' = 'symfony-bundle') AS bundles,
                   count(*) FILTER (WHERE state = 'documented' AND readme IS NOT NULL
                       AND (php_version_string IS NULL OR jsonb_exists_any(php_versions::jsonb, :php))
                       AND (symfony_version_string IS NULL OR jsonb_exists_any(symfony_versions::jsonb, :symfony))) AS modern
            FROM package
            SQL, ['php' => '{'.implode(',', Package::MODERN_PHP).'}', 'symfony' => '{'.implode(',', Package::MODERN_SYMFONY).'}'])
            ?: ['harvested' => 0, 'bundles' => 0, 'modern' => 0];

        return $this->render('home/index.html.twig', [
            'counts' => $counts,
            'modernPhp' => Package::MODERN_PHP,
            'modernSymfony' => Package::MODERN_SYMFONY,
        ]);
    }
}
