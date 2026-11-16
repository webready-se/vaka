<?php

/** Run scripts/seed-demo.php with these arguments. */
function seed_demo(array $args): array
{
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/../../scripts/seed-demo.php', ...$args])) . ' 2>&1', $output, $exit);
    return ['exit' => $exit, 'out' => implode("\n", $output)];
}

test('the live demo seed keeps its link and its sites, fresh, when run again in place', function () {
    $path = temp_dir() . '/demo.sqlite';
    $token = 'vaka_' . str_repeat('ab', 32);

    expect(seed_demo([$path, "--token={$token}"])['exit'])->toBe(0);
    $db = get_db($path);
    $found = find_share_token($db, $token, time());
    $ids = $db->query('SELECT id, url FROM sites ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    expect($found)->not->toBeNull()
        ->and($found['label'])->toBe('Live demo')
        ->and($found['expires_at'])->toBeNull()
        // Every group, so the demo shows them all
        ->and(share_scope($found)['groups'])->toBe(['Customers', 'Nightly', 'Own sites']);

    // A second run without --refresh never overwrites a database
    expect(seed_demo([$path])['exit'])->toBe(1);

    // In place: the same token and site ids (an open #site-N still works), one of everything, and a run just now
    $db->exec("UPDATE meta SET value = '2000-01-01T00:00:00Z' WHERE key = 'last_run_at'");
    $r = seed_demo([$path, '--refresh', "--token={$token}"]);
    expect($r['exit'])->toBe(0, $r['out'])
        ->and(find_share_token($db, $token, time()))->not->toBeNull()
        ->and($db->query('SELECT id, url FROM sites ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR))->toBe($ids)
        ->and((int) $db->query('SELECT COUNT(*) FROM share_tokens')->fetchColumn())->toBe(1)
        ->and((int) $db->query('SELECT COUNT(*) FROM groups')->fetchColumn())->toBe(3)
        ->and(time() - strtotime(get_meta($db, 'last_run_at')))->toBeLessThan(120);

    // A token that is not one is refused before anything is written
    expect(seed_demo([$path, '--refresh', '--token=not-a-token'])['exit'])->toBe(1);
});
