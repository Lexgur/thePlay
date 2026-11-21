<?php

declare(strict_types=1);

use ThePlay\Clips;
use ThePlay\Project;
use ThePlay\Time;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($uri) || $uri === '') {
    $uri = '/';
}

$ui = dirname(__DIR__, 2) . '/ui';
if ($uri === '/' || $uri === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($ui . '/index.html');

    return;
}
if ($uri === '/app.js') {
    header('Content-Type: text/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($ui . '/app.js');

    return;
}
if ($uri === '/app.css') {
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($ui . '/app.css');

    return;
}
if ($uri === '/display') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($ui . '/display.html');

    return;
}
if ($uri === '/display.js') {
    header('Content-Type: text/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($ui . '/display.js');

    return;
}

$dataDir = getenv('THEPLAY_DATA');
if (!is_string($dataDir) || $dataDir === '') {
    $dataDir = dirname(__DIR__, 2) . '/var';
}

try {
    if ($uri === '/api/open' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $body = requestBody();
        respond((new Project($dataDir))->open((string) ($body['path'] ?? '')));

        return;
    }

    if ($uri === '/api/cue' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        set_time_limit(0);
        $body = requestBody();
        $projectId = (string) ($body['projectId'] ?? '');
        $cueId = (string) ($body['id'] ?? '');
        if ($cueId === '') {
            $cueId = bin2hex(random_bytes(8));
        } elseif (preg_match('/^[a-f0-9]{16}$/', $cueId) !== 1) {
            throw new InvalidArgumentException('Unknown sound');
        }

        $key = (string) ($body['key'] ?? '');
        if ($key === ' ') {
            throw new InvalidArgumentException('Space stops all sounds, so it cannot be a group key');
        }
        if ($key === '' || strlen($key) > 32 || preg_match('/[\x00-\x1F]/', $key) === 1) {
            throw new InvalidArgumentException('Press a key for this sound');
        }
        if (!isset($body['fadeIn'], $body['volume']) || !is_numeric($body['fadeIn']) || !is_numeric($body['volume'])) {
            throw new InvalidArgumentException('Set a fade-in and a volume');
        }
        $fadeIn = (float) $body['fadeIn'];
        $fadeOut = isset($body['fadeOut']) && is_numeric($body['fadeOut']) ? (float) $body['fadeOut'] : $fadeIn;
        $volume = (int) $body['volume'];
        if ($fadeIn < 0 || $fadeIn > 120) {
            throw new InvalidArgumentException('Fade-in must be between 0 and 120 seconds');
        }
        if ($fadeOut < 0 || $fadeOut > 120) {
            throw new InvalidArgumentException('Fade-out must be between 0 and 120 seconds');
        }
        if ($volume < 0 || $volume > 100) {
            throw new InvalidArgumentException('Volume must be between 0 and 100');
        }

        $startLabel = trim((string) ($body['start'] ?? ''));
        $endLabel = trim((string) ($body['end'] ?? ''));
        $startSec = Time::seconds($startLabel);
        $endSec = Time::seconds($endLabel);
        Time::assertRange($startSec, $endSec);
        $youtubeUrl = trim((string) ($body['youtubeUrl'] ?? ''));
        $videoId = Clips::videoId($youtubeUrl);
        $projectStore = new Project($dataDir);
        $cue = [
            'id' => $cueId,
            'paragraphId' => (string) ($body['paragraphId'] ?? ''),
            'startOffset' => (int) ($body['startOffset'] ?? -1),
            'endOffset' => (int) ($body['endOffset'] ?? -1),
            'key' => $key,
            'youtubeUrl' => $youtubeUrl,
            'videoId' => $videoId,
            'start' => $startLabel,
            'startSec' => $startSec,
            'end' => $endLabel,
            'endSec' => $endSec,
            'fadeIn' => $fadeIn,
            'fadeOut' => $fadeOut,
            'volume' => $volume,
            'clip' => '',
        ];
        $projectStore->assertCue($projectId, $cue);
        $cue['clip'] = (new Clips($dataDir))->ensure($youtubeUrl, $startSec, $endSec);
        $projectStore->saveCue($projectId, $cue);
        respond($projectStore->saveGroup($projectId, visualFor($body, $dataDir, $key)));

        return;
    }

    if ($uri === '/api/cue' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'DELETE') {
        $body = requestBody();
        $project = (new Project($dataDir))->deleteCue(
            (string) ($body['projectId'] ?? ''),
            (string) ($body['id'] ?? '')
        );
        respond($project);

        return;
    }

    if (preg_match('#^/visual/([A-Za-z0-9_-]+\.(?:mp4|jpg|png|gif|webp))$#', $uri, $match) === 1) {
        sendFile($dataDir . '/visuals', $match[1], [
            'mp4' => 'video/mp4',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ]);

        return;
    }

    if (preg_match('#^/audio/([A-Za-z0-9_-]+\.m4a)$#', $uri, $match) === 1) {
        $clipsDir = realpath($dataDir . '/clips');
        $file = realpath($dataDir . '/clips/' . $match[1]);
        if ($clipsDir === false || $file === false || !str_starts_with($file, $clipsDir . DIRECTORY_SEPARATOR)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';

            return;
        }
        header('Content-Type: audio/mp4');
        header('Content-Length: ' . (string) filesize($file));
        header('Cache-Control: no-store');
        readfile($file);

        return;
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
} catch (InvalidArgumentException $exception) {
    respond(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    respond(['error' => $exception->getMessage()], 500);
}

function visualFor(array $body, string $dataDir, string $key): array
{
    $type = (string) ($body['visualType'] ?? '');
    $group = [
        'key' => $key,
        'visualType' => '',
        'visualFile' => '',
        'visualLoop' => false,
        'visualMuted' => false,
        'visualUrl' => '',
        'visualStart' => '',
        'visualEnd' => '',
    ];
    if ($type === '') {
        return $group;
    }
    if ($type === 'image') {
        $path = (string) ($body['imagePath'] ?? '');
        $existing = (string) ($body['visualFile'] ?? '');
        if ($path !== '') {
            $group['visualFile'] = storeImage($dataDir, $path);
        } elseif (preg_match('/^[a-f0-9]{64}\.(jpg|png|gif|webp)$/', $existing) === 1) {
            $group['visualFile'] = $existing;
        } else {
            throw new InvalidArgumentException('Choose an image');
        }
        $group['visualType'] = 'image';
        $group['visualLoop'] = true;

        return $group;
    }
    if ($type !== 'video') {
        throw new InvalidArgumentException('Unknown picture type');
    }

    $url = trim((string) ($body['visualUrl'] ?? ''));
    $startLabel = trim((string) ($body['visualStart'] ?? ''));
    $endLabel = trim((string) ($body['visualEnd'] ?? ''));
    $start = null;
    $end = null;
    if ($startLabel !== '' || $endLabel !== '') {
        if ($startLabel === '' || $endLabel === '') {
            throw new InvalidArgumentException('Set both video times, or leave both empty for the whole video');
        }
        $start = Time::seconds($startLabel);
        $end = Time::seconds($endLabel);
        Time::assertRange($start, $end);
    }
    $group['visualType'] = 'video';
    $group['visualFile'] = (new Clips($dataDir))->ensureVideo($url, $start, $end);
    $group['visualLoop'] = filter_var($body['visualLoop'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $group['visualMuted'] = !array_key_exists('visualMuted', $body) || filter_var($body['visualMuted'], FILTER_VALIDATE_BOOLEAN);
    $group['visualUrl'] = $url;
    $group['visualStart'] = $startLabel;
    $group['visualEnd'] = $endLabel;

    return $group;
}

function storeImage(string $dataDir, string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        throw new InvalidArgumentException('Image not found');
    }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === 'jpeg') {
        $extension = 'jpg';
    }
    if (!in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
        throw new InvalidArgumentException('Use a jpg, png, gif, or webp image');
    }
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException('Cannot read that image');
    }
    $name = $hash . '.' . $extension;
    $dir = $dataDir . '/visuals';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot store that image');
    }
    if (!is_file($dir . '/' . $name) && !copy($path, $dir . '/' . $name)) {
        throw new RuntimeException('Cannot store that image');
    }

    return $name;
}

function sendFile(string $directory, string $name, array $types): void
{
    $root = realpath($directory);
    $file = realpath($directory . '/' . $name);
    if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';

        return;
    }
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($file));
    header('Cache-Control: no-store');
    readfile($file);
}

function requestBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Expected JSON');
    }

    return $data;
}

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}
