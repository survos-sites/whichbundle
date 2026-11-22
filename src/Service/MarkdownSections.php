<?php

declare(strict_types=1);

namespace App\Service;

/** Lossless ATX-heading sections; headings inside fenced code are not boundaries. */
final class MarkdownSections
{
    public const VERSION = 'markdown-sections-v1';

    public function split(string $text): array
    {
        $sections = [];
        $start = $offset = 0;
        $headings = [];
        $label = '';
        $fence = null;
        preg_match_all('/[^\n]*\n|[^\n]+$/u', $text, $lines);
        foreach ($lines[0] as $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/u', rtrim($line, "\r\n"), $m)) {
                if ($fence === null) {
                    $fence = $m[1];
                } elseif ($m[1][0] === $fence[0] && strlen($m[1]) >= strlen($fence) && trim($m[2]) === '') {
                    $fence = null;
                }
            } elseif ($fence === null && preg_match('/^ {0,3}(#{1,6})[\t ]+(.+?)\s*#*\s*$/u', $line, $m)) {
                if ($offset > $start) {
                    $sections[] = ['start' => $start, 'end' => $offset, 'heading' => $label];
                }
                $start = $offset;
                $level = strlen($m[1]);
                $headings = array_filter($headings, static fn ($key) => $key < $level, ARRAY_FILTER_USE_KEY);
                $headings[$level] = $m[2];
                $label = implode(' / ', $headings);
            }
            $offset += mb_strlen($line, 'UTF-8');
        }
        if ($offset > $start) {
            $sections[] = ['start' => $start, 'end' => $offset, 'heading' => $label];
        }
        return $sections;
    }
}
