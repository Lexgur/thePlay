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
        if ($key === '' || strlen($key) > 32 || preg_match('/[\x00-\x1F]/', $key) === 1) {
            throw new InvalidArgumentException('Press a key for this sound');
        }
        if (!isset($body['fadeIn'], $body['volume']) || !is_numeric($body['fadeIn']) || !is_numeric($body['volume'])) {
            throw new InvalidArgumentException('Set a fade-in and a volume');
        }
        $fadeIn = (float) $body['fadeIn'];
        $volume = (int) $body['volume'];
        if ($fadeIn < 0 || $fadeIn > 120) {
            throw new InvalidArgumentException('Fade-in must be between 0 and 120 seconds');
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
        $clip = (new Clips($dataDir))->ensure($youtubeUrl, $startSec, $endSec);

        $project = (new Project($dataDir))->saveCue($projectId, [
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
            'volume' => $volume,
            'clip' => $clip,
        ]);
        respond($project);

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
