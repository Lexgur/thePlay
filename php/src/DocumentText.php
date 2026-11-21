<?php

declare(strict_types=1);

namespace ThePlay;

use DOMDocument;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use Smalot\PdfParser\Parser;
use ZipArchive;

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
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('That .docx could not be opened');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            throw new InvalidArgumentException('That .docx has no text');
        }

        $dom = new DOMDocument();
        $errors = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        if ($loaded !== true) {
            throw new InvalidArgumentException('That .docx could not be read');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $blocks = [];
        $paragraphs = $xpath->query('/w:document/w:body//w:p');
        if ($paragraphs === false) {
            return [];
        }
        foreach ($paragraphs as $paragraph) {
            $blocks[] = self::docxParagraph($xpath, $paragraph);
        }

        return $blocks;
    }

    private static function docxParagraph(DOMXPath $xpath, DOMNode $paragraph): string
    {
        $parts = $xpath->query('.//w:t|.//w:tab|.//w:br', $paragraph);
        if ($parts === false) {
            return '';
        }
        $text = '';
        foreach ($parts as $part) {
            if ($part->localName === 't') {
                $text .= $part->textContent;
            } elseif ($part->localName === 'tab') {
                $text .= "\t";
            } else {
                $text .= "\n";
            }
        }

        return $text;
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
}
