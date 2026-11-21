<?php

declare(strict_types=1);

namespace ThePlay;

use InvalidArgumentException;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

final class DocumentText
{
    public static function paragraphs(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $texts = match ($extension) {
            'docx' => self::docx($path),
            'pdf' => self::pdf($path),
            default => throw new InvalidArgumentException('Open a .docx or .pdf'),
        };

        $paragraphs = [];
        foreach ($texts as $text) {
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $paragraphs[] = [
                'id' => 'p' . count($paragraphs),
                'text' => $text,
            ];
        }

        if ($paragraphs === []) {
            throw new InvalidArgumentException('That file has no text');
        }

        return $paragraphs;
    }

    private static function docx(string $path): array
    {
        $blocks = [];
        foreach (IOFactory::load($path)->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                self::appendBlocks($element, $blocks);
            }
        }

        return $blocks;
    }

    private static function pdf(string $path): array
    {
        $text = (new Parser())->parseFile($path)->getText();
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $chunks = preg_split("/\n\s*\n/", $text) ?: [];
        $paragraphs = [];
        foreach ($chunks as $chunk) {
            $flat = trim(preg_replace("/[ \t]*\n[ \t]*/", ' ', $chunk) ?? '');
            if ($flat !== '') {
                $paragraphs[] = $flat;
            }
        }

        return $paragraphs;
    }

    private static function appendBlocks(object $element, array &$blocks): void
    {
        if (method_exists($element, 'getRows')) {
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $child) {
                        if (is_object($child)) {
                            self::appendBlocks($child, $blocks);
                        }
                    }
                }
            }

            return;
        }

        $text = trim(self::inlineText($element));
        if ($text !== '') {
            $blocks[] = $text;
        }
    }

    private static function inlineText(object $element): string
    {
        $name = (new \ReflectionClass($element))->getShortName();
        if ($name === 'TextBreak') {
            return "\n";
        }
        if (!method_exists($element, 'getElements')) {
            return self::leafText($element);
        }

        $text = '';
        foreach ($element->getElements() as $child) {
            if (is_object($child)) {
                $text .= self::inlineText($child);
            }
        }

        return $text;
    }

    private static function leafText(object $element): string
    {
        if (!method_exists($element, 'getText')) {
            return '';
        }
        $text = $element->getText();
        if (is_string($text)) {
            return $text;
        }
        if (!is_array($text)) {
            return '';
        }
        $joined = '';
        foreach ($text as $part) {
            if (is_string($part)) {
                $joined .= $part;
            }
        }

        return $joined;
    }
}
