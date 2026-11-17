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

test('the demo agrees with itself: up since its last recovery, and a down site says the same in its card, history and samples', function () {
    $path = temp_dir() . '/demo.sqlite';
    expect(seed_demo([$path])['exit'])->toBe(0);
    $db = get_db($path);
    $states = $db->query("SELECT s.url, c.site_id, c.status, c.summary, c.changed_at, c.details FROM check_state c JOIN sites s ON s.id = c.site_id WHERE c.check_name = 'uptime'")->fetchAll();
    foreach ($states as $state) {
        $last = $db->query("SELECT status, message, created_at FROM events WHERE site_id = {$state['site_id']} AND check_name = 'uptime' ORDER BY created_at DESC, id DESC LIMIT 1")->fetch();
        if (!$last) continue;
        // "Up since" no earlier than the last time it came back
        if ($state['status'] === 'ok' && $last['status'] === 'ok') expect($state['changed_at'] >= $last['created_at'])->toBeTrue("{$state['url']} is up since {$state['changed_at']}, before it came back at {$last['created_at']}");
        if ($state['status'] === 'fail') {
            // The history says what the card says, and the samples answered as the card says
            $code = (int) $db->query("SELECT status_code FROM samples WHERE site_id = {$state['site_id']} ORDER BY checked_at DESC LIMIT 1")->fetchColumn();
            expect($last['message'])->toBe('Down: ' . lcfirst($state['summary']), $state['url'])
                ->and($code)->toBe(json_decode($state['details'], true)['status_code'], $state['url']);
        }
    }
});
