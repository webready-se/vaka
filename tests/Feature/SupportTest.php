<?php

test('a fixture server refuses a port another process holds, instead of testing against it', function () {
    $other = stream_socket_server('tcp://127.0.0.1:8789');
    try {
        expect(fn () => start_site('good', 8789))->toThrow(RuntimeException::class, 'Port 8789 is already taken by another process');
    } finally {
        fclose($other);
    }
});
