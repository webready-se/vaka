<?php

test('a fixture server refuses a port another process holds, instead of testing against it', function () {
    $other = stream_socket_server('tcp://127.0.0.1:8788');
    try {
        expect(fn () => start_site('good', 8788))->toThrow(RuntimeException::class, 'Port 8788 is already taken by another process');
    } finally {
        fclose($other);
    }
});
