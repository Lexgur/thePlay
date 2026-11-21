<?php

declare(strict_types=1);

namespace ThePlay;

use InvalidArgumentException;
use RuntimeException;

final class Project
{
    public function __construct(private readonly string $dir)
    {
        self::ensureDir($this->dir);
        self::ensureDir($this->dir . '/projects');
    }

    public function open(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('File not found');
        }
        $id = hash_file('sha256', $path);
        if ($id === false) {
            throw new RuntimeException('Cannot read that file');
        }
        $existing = $this->load($id);
        if ($existing !== null) {
            $existing['path'] = $path;
            $this->write($existing);

            return $existing;
        }

        $data = [
            'id' => $id,
            'path' => $path,
            'paragraphs' => DocumentText::paragraphs($path),
            'cues' => [],
        ];
        $this->write($data);

        return $data;
    }

public function assertCue(string $projectId, array $cue): void
    {
        $this->checked($projectId, $cue);
    }

    public function saveCue(string $projectId, array $cue): array
    {
        [$data, $start, $end] = $this->checked($projectId, $cue);
        $stored = [
            'id' => $cue['id'],
            'paragraphId' => $cue['paragraphId'],
            'startOffset' => $start,
            'endOffset' => $end,
            'key' => $cue['key'],
            'youtubeUrl' => $cue['youtubeUrl'],
            'videoId' => $cue['videoId'],
            'start' => $cue['start'],
            'startSec' => $cue['startSec'],
            'end' => $cue['end'],
            'endSec' => $cue['endSec'],
            'fadeIn' => $cue['fadeIn'],
            'fadeOut' => $cue['fadeOut'],
            'volume' => $cue['volume'],
            'clip' => $cue['clip'],
        ];

        $replaced = false;
        foreach ($data['cues'] as $index => $existing) {
            if ($existing['id'] === $cue['id']) {
                $data['cues'][$index] = $stored;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $data['cues'][] = $stored;
        }
        $this->write($data);

        return $data;
    }

    public function deleteCue(string $projectId, string $cueId): array
    {
        $data = $this->requireProject($projectId);
        $kept = [];
        foreach ($data['cues'] as $existing) {
            if ($existing['id'] !== $cueId) {
                $kept[] = $existing;
            }
        }
        $data['cues'] = $kept;
        $this->write($data);

        return $data;
    }

    private function checked(string $projectId, array $cue): array
    {
        $data = $this->requireProject($projectId);
        $paragraph = null;
        foreach ($data['paragraphs'] as $item) {
            if ($item['id'] === $cue['paragraphId']) {
                $paragraph = $item;
                break;
            }
        }
        if ($paragraph === null) {
            throw new InvalidArgumentException('That paragraph is not in this script');
        }

        $start = $cue['startOffset'];
        $end = $cue['endOffset'];
        // Offsets are JavaScript UTF-16 indexes so emoji in the script line up.
        $length = self::utf16Length($paragraph['text']);
        if ($start < 0 || $end > $length || $start >= $end) {
            throw new InvalidArgumentException('Select some text in one paragraph');
        }

        foreach ($data['cues'] as $existing) {
            if ($existing['id'] === $cue['id']) {
                continue;
            }
            if ($existing['key'] === $cue['key']) {
                throw new InvalidArgumentException('That key is already used');
            }
            if ($existing['paragraphId'] !== $cue['paragraphId']) {
                continue;
            }
            if ($start < $existing['endOffset'] && $existing['startOffset'] < $end) {
                throw new InvalidArgumentException('That text already has a sound');
            }
        }

        return [$data, $start, $end];
    }

    private function requireProject(string $projectId): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $projectId) !== 1) {
            throw new InvalidArgumentException('Unknown script');
        }
        $data = $this->load($projectId);
        if ($data === null) {
            throw new InvalidArgumentException('Unknown script');
        }

        return $data;
    }

    private function load(string $id): ?array
    {
        $file = $this->file($id);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new RuntimeException('Saved script is unreadable');
        }

        return $data;
    }

    private function write(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($this->file($data['id']), $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot save script');
        }
    }

    private function file(string $id): string
    {
        return $this->dir . '/projects/' . $id . '.json';
    }

    private static function utf16Length(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $utf16 = iconv('UTF-8', 'UTF-16LE', $text);
        if ($utf16 === false) {
            throw new InvalidArgumentException('Select some text in one paragraph');
        }

        return intdiv(strlen($utf16), 2);
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create data directory');
        }
    }
}
