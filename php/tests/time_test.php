<?php

declare(strict_types=1);

use ThePlay\Clips;
use ThePlay\Time;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

check(Time::seconds('1:08') === 68, '1:08 should be 68 seconds');
check(Time::seconds('1:02:03') === 3723, '1:02:03 should be 3723 seconds');
check(Time::seconds('0:00') === 0, '0:00 should be 0 seconds');

$rejected = false;
try {
    Time::seconds('1:60');
} catch (InvalidArgumentException) {
    $rejected = true;
}
check($rejected, '1:60 should be rejected');

$rejected = false;
try {
    Time::assertRange(68, 68);
} catch (InvalidArgumentException) {
    $rejected = true;
}
check($rejected, 'equal times should be rejected');

$rejected = false;
try {
    Time::assertRange(103, 68);
} catch (InvalidArgumentException) {
    $rejected = true;
}
check($rejected, 'a reversed range should be rejected');

Time::assertRange(68, 103);

check(Clips::videoId('https://www.youtube.com/watch?v=dQw4w9WgXcQ') === 'dQw4w9WgXcQ', 'watch url');
check(Clips::videoId('https://youtu.be/dQw4w9WgXcQ?t=68') === 'dQw4w9WgXcQ', 'short url');
check(Clips::videoId('https://www.youtube.com/shorts/dQw4w9WgXcQ') === 'dQw4w9WgXcQ', 'shorts url');

$rejected = false;
try {
    Clips::videoId('https://example.com/watch?v=dQw4w9WgXcQ');
} catch (InvalidArgumentException) {
    $rejected = true;
}
check($rejected, 'a non-YouTube link should be rejected');

echo "ok\n";
