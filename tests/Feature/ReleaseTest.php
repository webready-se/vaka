<?php

/** Git in a test's own folder: without what a git hook hands down, which points at the repository being pushed. */
const GIT_OWN_FOLDER = 'unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY; ';

/** A small git repository with what scripts/build-release.sh packs, and this CHANGELOG.md. */
function release_repo(string $changelog): string
{
    $dir = temp_dir();
    foreach (['scripts', 'src', 'public', 'docs'] as $folder) mkdir("{$dir}/{$folder}");
    copy(dirname(__DIR__, 2) . '/scripts/build-release.sh', "{$dir}/scripts/build-release.sh");
    file_put_contents("{$dir}/src/bootstrap.php", "<?php\nconst VAKA_VERSION = '1.2.3';\n");
    file_put_contents("{$dir}/CHANGELOG.md", $changelog);
    foreach (['vaka', 'config.php', '.env.example', 'public/index.php', 'LICENSE', 'README.md', 'docs/install.md', 'docs/configuration.md', 'docs/checks.md', 'docs/alerts.md', 'docs/api.md'] as $file) {
        file_put_contents("{$dir}/{$file}", "x\n");
    }
    $git = 'git -c user.name=Test -c user.email=test@example.com -c core.hooksPath=/dev/null';
    exec(GIT_OWN_FOLDER . "cd " . escapeshellarg($dir) . " && git init -q && git add . && {$git} commit -q -m test 2>&1", $output, $code);
    expect($code)->toBe(0, implode("\n", $output));
    return $dir;
}

function build_release(string $dir, string $version): array
{
    exec(GIT_OWN_FOLDER . 'bash ' . escapeshellarg("{$dir}/scripts/build-release.sh") . ' ' . escapeshellarg($version) . ' 2>&1', $output, $code);
    return ['code' => $code, 'out' => implode("\n", $output)];
}

test('the release build finds its section at the top of a changelog of any size', function () {
    // Bigger than any pipe's buffer: a reader that stops at the first match must not fail the check
    $changelog = "# Changelog\n\n## [Unreleased]\n\n## [1.2.3] - 2026-11-04\n\n" . str_repeat("- An older change, in a long changelog.\n", 30000);
    $dir = release_repo($changelog);

    $build = build_release($dir, '1.2.3');

    expect($build['code'])->toBe(0, $build['out'])
        ->and(file_exists("{$dir}/build/vaka-1.2.3.zip"))->toBeTrue();
});

test('the release build refuses a version without a changelog section', function () {
    $dir = release_repo("# Changelog\n\n## [Unreleased]\n\n## [1.2.2] - 2026-11-03\n");

    $build = build_release($dir, '1.2.3');

    expect($build['code'])->toBe(1)
        ->and($build['out'])->toContain('CHANGELOG.md has no [1.2.3] section');
});

test('the release tests never touch the repository a git hook runs them from', function () {
    // A pre-push hook in a linked worktree hands the tests an absolute GIT_DIR: git in a test's own
    // folder must still use that folder, or it makes the pushed branch's repository bare and commits to it
    $decoy = temp_dir();
    $git = fn (string $command) => trim((string) shell_exec('git -C ' . escapeshellarg($decoy) . " {$command} 2>&1"));
    $git('init -q');
    $git('-c user.name=Test -c user.email=test@example.com commit -q --allow-empty -m decoy');
    $head = $git('rev-parse HEAD');

    putenv("GIT_DIR={$decoy}/.git");
    try {
        $build = build_release(release_repo("# Changelog\n\n## [1.2.3] - 2026-11-04\n"), '1.2.3');
    } finally {
        putenv('GIT_DIR');
    }

    expect($build['code'])->toBe(0, $build['out'])
        ->and($git('rev-parse HEAD'))->toBe($head)
        ->and($git('config core.bare'))->toBe('false');
});

test('the release build refuses a version the code does not have', function () {
    $dir = release_repo("# Changelog\n\n## [1.2.4] - 2026-11-04\n");

    $build = build_release($dir, '1.2.4');

    expect($build['code'])->toBe(1)
        ->and($build['out'])->toContain("src/bootstrap.php says VAKA_VERSION = '1.2.3', not '1.2.4'");
});
