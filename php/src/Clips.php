<?php

declare(strict_types=1);

namespace ThePlay;

use InvalidArgumentException;
use RuntimeException;

final class Clips
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Cannot create data directory');
        }
    }

    public static function videoId(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new InvalidArgumentException('Paste a YouTube link');
        }

        $host = strtolower((string) preg_replace('/^www\./', '', $parts['host']));
        $id = '';
        if ($host === 'youtu.be') {
            $id = explode('/', trim((string) ($parts['path'] ?? ''), '/'))[0];
        } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (isset($query['v']) && is_string($query['v'])) {
                $id = $query['v'];
            } elseif (preg_match('#^/(?:embed|shorts|live)/([^/?]+)#', (string) ($parts['path'] ?? ''), $match) === 1) {
                $id = $match[1];
            }
        } else {
            throw new InvalidArgumentException('Paste a YouTube link');
        }

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $id) !== 1) {
            throw new InvalidArgumentException('That YouTube link has no video id');
        }

        return $id;
    }

    public function ensure(string $youtubeUrl, int $start, int $end): string
    {
        Time::assertRange($start, $end);
        $videoId = self::videoId($youtubeUrl);
        $name = $videoId . '_' . $start . '_' . $end . '.m4a';
        $destination = $this->dir . '/clips/' . $name;
        if (is_file($destination) && filesize($destination) > 1000) {
            return $name;
        }

        // shortcut: downloads the whole video's audio once; switch to section download if a video is hours long.
        $source = $this->source($videoId);
        $duration = $this->duration($source);
        if ($start >= $duration) {
            throw new InvalidArgumentException('Start time is past the end of the video');
        }
        if ($end > $duration + 0.25) {
            throw new InvalidArgumentException('End time is past the end of the video');
        }

        $this->ensureClipDir();
        $this->run([
            $this->bin('ffmpeg'),
            '-y',
            '-i',
            $source,
            '-ss',
            (string) $start,
            '-to',
            (string) $end,
            '-vn',
            '-c:a',
            'aac',
            $destination,
        ]);
        if (!is_file($destination) || filesize($destination) < 1000) {
            throw new RuntimeException('Could not cut that time range');
        }

        return $name;
    }

    public function ensureVideo(string $youtubeUrl, ?int $start, ?int $end): string
    {
        $videoId = self::videoId($youtubeUrl);
        $ranged = $start !== null && $end !== null;
        if ($ranged) {
            Time::assertRange((int) $start, (int) $end);
        }
        $source = $this->videoSource($videoId);
        if (!$ranged) {
            return $videoId . '_full.mp4';
        }

        $name = $videoId . '_' . $start . '_' . $end . '.mp4';
        $destination = $this->dir . '/visuals/' . $name;
        if (is_file($destination) && filesize($destination) > 1000) {
            return $name;
        }
        $duration = $this->duration($source);
        if ($start >= $duration) {
            throw new InvalidArgumentException('Start time is past the end of the video');
        }
        if ($end > $duration + 0.25) {
            throw new InvalidArgumentException('End time is past the end of the video');
        }
        $this->ensureVisualDir();
        $this->run([
            $this->bin('ffmpeg'),
            '-y',
            '-ss',
            (string) $start,
            '-to',
            (string) $end,
            '-i',
            $source,
            '-c',
            'copy',
            $destination,
        ]);
        if (!is_file($destination) || filesize($destination) < 1000) {
            throw new RuntimeException('Could not cut that video');
        }

        return $name;
    }

    private function videoSource(string $videoId): string
    {
        $destination = $this->dir . '/visuals/' . $videoId . '_full.mp4';
        if (is_file($destination) && filesize($destination) > 1000) {
            return $destination;
        }
        $this->ensureVisualDir();
        // shortcut: caps the download at 720p; raise the format if a projector looks soft.
        $this->run([
            $this->bin('yt-dlp'),
            '--js-runtimes',
            'node:' . $this->bin('node'),
            '-f',
            'bv*[height<=720]+ba/b[height<=720]/b',
            '--merge-output-format',
            'mp4',
            '--no-playlist',
            '--no-progress',
            '-o',
            $destination,
            'https://www.youtube.com/watch?v=' . $videoId,
        ]);
        if (!is_file($destination) || filesize($destination) < 1000) {
            throw new RuntimeException('Could not download that YouTube video');
        }

        return $destination;
    }

    private function source(string $videoId): string
    {
        $destination = $this->dir . '/sources/' . $videoId . '.m4a';
        if (is_file($destination) && filesize($destination) > 1000) {
            return $destination;
        }

        $this->ensureSourceDir();
        $this->run([
            $this->bin('yt-dlp'),
            '--js-runtimes',
            'node:' . $this->bin('node'),
            '-f',
            'bestaudio',
            '-x',
            '--audio-format',
            'm4a',
            '--no-playlist',
            '--no-progress',
            '-o',
            $this->dir . '/sources/' . $videoId . '.%(ext)s',
            'https://www.youtube.com/watch?v=' . $videoId,
        ]);
        if (!is_file($destination) || filesize($destination) < 1000) {
            throw new RuntimeException('Could not download that YouTube audio');
        }

        return $destination;
    }

    private function duration(string $file): float
    {
        $output = $this->run([
            $this->bin('ffprobe'),
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $file,
        ]);
        $duration = (float) trim($output);
        if ($duration <= 0) {
            throw new RuntimeException('Could not read the audio length');
        }

        return $duration;
    }

    private function ensureSourceDir(): void
    {
        $dir = $this->dir . '/sources';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create audio cache');
        }
    }

    private function ensureVisualDir(): void
    {
        $dir = $this->dir . '/visuals';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create video cache');
        }
    }

    private function ensureClipDir(): void
    {
        $dir = $this->dir . '/clips';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create audio cache');
        }
    }

    private function bin(string $name): string
    {
        $home = getenv('HOME') ?: '';
        $dirs = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
        $dirs[] = $home . '/.local/bin';
        $dirs[] = $home . '/.local/node/bin';
        $dirs[] = '/usr/local/bin';
        $dirs[] = '/usr/bin';
        foreach ($dirs as $dir) {
            if ($dir === '') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_executable($path)) {
                return $path;
            }
        }

        throw new RuntimeException($name . ' is not installed');
    }

    private function run(array $command): string
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not run ' . $command[0]);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            $tail = trim(($stderr === false ? '' : $stderr) . "\n" . ($stdout === false ? '' : $stdout));
            $lines = preg_split("/\r\n|\n|\r/", $tail) ?: [];
            $errors = [];
            foreach ($lines as $line) {
                if (str_contains($line, 'ERROR:')) {
                    $errors[] = trim($line);
                }
            }
            $message = $errors !== [] ? implode("\n", $errors) : trim(implode("\n", array_slice($lines, -12)));
            throw new RuntimeException(basename((string) $command[0]) . ' failed' . ($message === '' ? '' : ': ' . $message));
        }

        return $stdout === false ? '' : $stdout;
    }
}
