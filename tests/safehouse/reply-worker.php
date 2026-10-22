<?php
declare(strict_types=1);
// Synthetic filesystem writer, no game or Discord bootstrap.
[$script, $path, $encoded] = $argv;
file_put_contents($path.'.pending', 'synthetic-publication');
file_put_contents($path, '{partial');
echo "partial\n"; fflush(STDOUT);
fgets(STDIN);
file_put_contents($path, base64_decode($encoded, true));
echo "complete-marked\n"; fflush(STDOUT);
fgets(STDIN);
unlink($path.'.pending');
echo "released\n"; fflush(STDOUT);
