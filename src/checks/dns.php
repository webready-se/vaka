<?php

/** How often a domain's mail records are read; a change is confirmed by a second look after DNS_CONFIRM_SECONDS. */
const DNS_INTERVAL_SECONDS = 6 * 3600;
const DNS_CONFIRM_SECONDS = 15 * 60;
const DNS_RETRY_SECONDS = 3600;

/** The mail records, in the order they are shown, with what each does. */
const MAIL_RECORDS = [
    'mx' => ['label' => 'MX', 'what' => 'Where email to the domain is delivered'],
    'spf' => ['label' => 'SPF', 'what' => 'Which servers may send email in the domain\'s name'],
    'dmarc' => ['label' => 'DMARC', 'what' => 'What receivers do with email that pretends to come from the domain, and where they send reports'],
];

/** dns_get_record() without its warnings: false when the lookup failed, an empty list when there are no such records. */
function dns_lookup(string $name, int $type): array|false
{
    set_error_handler(fn () => true);
    try {
        return dns_get_record($name, $type);
    } finally {
        restore_error_handler();
    }
}

/**
 * The domain whose mail records matter for a site: the one the domain check
 * found ($checked, only when the registry answered), else the one it had
 * ($previous) while the site is still on it, else the domain its host is on.
 * Keeping the previous one means a failed registry lookup never flips
 * www.shop.example.co.uk to co.uk and back. Null for a site reached by its IP.
 */
function mail_domain(string $url, ?string $checked, ?string $previous = null): ?string
{
    if ($checked !== null && $checked !== '' && !filter_var($checked, FILTER_VALIDATE_IP)) return $checked;
    $candidates = registrable_candidates((string) parse_url($url, PHP_URL_HOST));
    if ($previous !== null && in_array($previous, $candidates, true)) return $previous;
    return $candidates[0] ?? null;
}

/**
 * A domain's MX, SPF and DMARC records in one spelling, so a lookup can be
 * compared with the last: MX as "priority host" by priority, TXT values
 * joined from their pieces and sorted. Null when a lookup failed: a
 * resolver that did not answer is not the same as no records.
 *
 * @param callable(string, int): (array|false) $resolve
 * @return ?array{mx: list<string>, spf: list<string>, dmarc: list<string>}
 */
function lookup_mail_dns(string $domain, callable $resolve): ?array
{
    $mx = $resolve($domain, DNS_MX);
    $txt = $resolve($domain, DNS_TXT);
    $dmarc = $resolve("_dmarc.{$domain}", DNS_TXT);
    if ($mx === false || $txt === false || $dmarc === false) return null;

    $hosts = array_map(fn (array $record) => [(int) $record['pri'], rtrim(strtolower($record['target']), '.')], $mx);
    sort($hosts);
    $values = function (array $records, string $prefix): array {
        $found = [];
        foreach ($records as $record) {
            $value = trim(implode('', $record['entries'] ?? [$record['txt'] ?? '']));
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\s|;|$)/i', $value)) $found[] = $value;
        }
        sort($found);
        return $found;
    };
    return [
        // A null MX (RFC 7505) has no host: "0 ."
        'mx' => array_map(fn (array $host) => $host[0] . ' ' . ($host[1] === '' ? '.' : $host[1]), $hosts),
        'spf' => $values($txt, 'v=spf1'),
        'dmarc' => $values($dmarc, 'v=DMARC1'),
    ];
}

/** The p= of a DMARC record: none, quarantine or reject, or null. */
function dmarc_policy(string $record): ?string
{
    return preg_match('/(?:^|;)\s*p\s*=\s*(none|quarantine|reject)\b/i', $record, $m) ? strtolower($m[1]) : null;
}

/**
 * A domain's mail records in plain words: ok when SPF and DMARC are in
 * place, warn when one is missing or SPF is there twice, unknown without a
 * domain or an answer. A domain without MX receives no email, which is fine
 * by itself.
 *
 * @return array{status: string, summary: string, details: array}
 */
function evaluate_mail_dns(?string $domain, ?array $records): array
{
    if ($domain === null) return ['status' => 'unknown', 'summary' => 'No domain to look up: the site is reached by its address', 'details' => ['domain' => null, 'problem' => '']];
    $shown = display_domain($domain);
    if ($records === null) return ['status' => 'unknown', 'summary' => "Could not look up the mail records for {$shown}", 'details' => ['domain' => $domain, 'problem' => 'lookup_failed']];

    $policy = dmarc_policy($records['dmarc'][0] ?? '');
    [$status, $words, $problem] = match (true) {
        count($records['spf']) > 1 => ['warn', 'two SPF records, which receivers treat as an error', 'spf_twice'],
        !$records['spf'] && !$records['dmarc'] => ['warn', 'SPF and DMARC are missing', 'no_spf_dmarc'],
        !$records['spf'] => ['warn', 'SPF is missing', 'no_spf'],
        !$records['dmarc'] => ['warn', 'DMARC is missing', 'no_dmarc'],
        default => ['ok', 'SPF and DMARC in place' . ($policy === 'none' ? ', DMARC only monitors (p=none)' : ''), ''],
    };
    return ['status' => $status, 'summary' => "Mail for {$shown}: {$words}", 'details' => ['domain' => $domain, 'records' => $records, 'dmarc_policy' => $policy, 'problem' => $problem]];
}

/**
 * Whether a domain sends email, as far as its records tell. An SPF record
 * that names senders (include:, ip4:, a, mx ...) says it does, and so does a
 * mail server, since the people who receive email there send it too. An SPF
 * record that names none (v=spf1 -all), or neither SPF nor a mail server,
 * says it sends none. When in doubt it sends: the advice for a sender is the
 * one that cannot stop the domain's own email.
 */
function mail_domain_sends(array $records): bool
{
    $spf = $records['spf'][0] ?? null;
    if ($spf !== null) {
        $terms = array_slice(preg_split('/\s+/', strtolower(trim($spf))) ?: [], 1);
        return (bool) array_filter($terms, fn (string $term) => !preg_match('/^[-~?]all$/', $term));
    }
    return $records['mx'] !== [] && $records['mx'] !== ['0 .'];
}

/**
 * MX, SPF and DMARC with what each does, what is there, how well it is in
 * place (good, weak, missing, or none for a domain without mail) and the
 * record to add, for the site detail: `fix_label` says where, `fix` is the
 * value to paste. Empty before the first answer.
 *
 * @return list<array{key: string, label: string, what: string, records: list<string>, level: string, note: string, fix_label: string, fix: string, tip: string}>
 */
function dns_items(array $details): array
{
    if (!isset($details['records'])) return [];
    $domain = $details['domain'];
    $shown = display_domain($domain);
    $records = $details['records'];
    $monitoring = $records['dmarc'] && $details['dmarc_policy'] === 'none';
    $nullMx = $records['mx'] === ['0 .'];
    // A domain that sends no email can reject everything in its name at once; one that sends starts with reports
    $sends = mail_domain_sends($records);
    $reports = $monitoring && preg_match('/(^|;)\s*rua\s*=/i', $records['dmarc'][0]);
    $sendsNone = $records['spf'] ? 'its SPF record allows no sender' : 'it has neither SPF nor a mail server';
    $item = fn (string $key, array $more) => ['key' => $key] + MAIL_RECORDS[$key] + ['records' => $records[$key]] + $more;

    return [
        $item('mx', [
            'level' => $records['mx'] && !$nullMx ? 'good' : 'none',
            'note' => match (true) {
                $nullMx => 'A null MX: the domain says it takes no email, as a domain without email should.',
                !$records['mx'] => 'No mail server: email to this domain bounces. Fine if it neither sends nor receives email.',
                default => '',
            },
            'fix_label' => '',
            'fix' => '',
            'tip' => '',
        ]),
        $item('spf', [
            'level' => match (count($records['spf'])) { 0 => 'missing', 1 => 'good', default => 'weak' },
            'note' => count($records['spf']) > 1 ? 'Two SPF records: receivers treat that as an error. Merge them into one.' : '',
            'fix_label' => count($records['spf']) > 1 ? "Keep one TXT record at {$shown}, such as:" : "Add a TXT record at {$shown}:",
            'fix' => $sends || count($records['spf']) > 1 ? 'v=spf1 include:<your mail provider> -all' : 'v=spf1 -all',
            'tip' => $sends || count($records['spf']) > 1
                ? 'Sends no email at all? Publish v=spf1 -all, so nobody can send in its name.'
                : 'Sends email after all, through a mail provider or the site itself? Use v=spf1 include:<your mail provider> -all instead.',
        ]),
        $item('dmarc', [
            'level' => !$records['dmarc'] ? 'missing' : ($monitoring ? 'weak' : 'good'),
            'note' => match (true) {
                !$monitoring => '',
                !$reports => 'p=none without rua: it neither stops email that pretends to come from the domain nor sends reports',
                default => 'p=none only monitors: email that fails the check is still delivered',
            },
            'fix_label' => $monitoring ? "Change the TXT record at _dmarc.{$shown} to:" : "Add a TXT record at _dmarc.{$shown}:",
            // A record that only monitors keeps its own reports and options: only p= changes
            'fix' => match (true) {
                $monitoring => preg_replace('/(^|;)(\s*)p\s*=\s*none\b/i', '$1$2p=' . ($sends ? 'quarantine' : 'reject'), $records['dmarc'][0], 1),
                $sends => 'v=DMARC1; p=none; rua=mailto:<your report address>',
                default => 'v=DMARC1; p=reject',
            },
            'tip' => match (true) {
                $monitoring && $sends => 'Do it once the reports show only your own servers sending; reject is the step after that.',
                $monitoring => "It looks like it sends no email ({$sendsNone}), so there is nothing of its own to stop: reject right away.",
                $sends => 'rua is optional, but with p=none the reports are all the record gives: summaries from receivers (zipped XML) of who sends in the domain\'s name. A free DMARC report service reads them for you and gives the address to use. Once only your own servers show up, after a few weeks, move to p=quarantine, then p=reject.',
                default => "It looks like it sends no email ({$sendsNone}), so reject everything that claims to come from it. Reports are optional. Sends email after all? Start with v=DMARC1; p=none; rua=mailto:<your report address> and read the reports first.",
            },
        ]),
    ];
}

/**
 * Confirm a change before it counts: records that differ from the last
 * confirmed ones are looked up again after DNS_CONFIRM_SECONDS, and count
 * only when that look agrees (resolvers can disagree for a while after a
 * change). Another domain (the site moved) is a new start, not a change.
 * Returns the details to store, with the `confirmed` records and
 * `confirmed_domain`, when to look next, and the change to record.
 *
 * @return array{details: array, wait: int, change: ?string}
 */
function confirm_mail_dns(?array $before, array $result): array
{
    $confirmed = $before['confirmed'] ?? null;
    if ($result['status'] === 'unknown') {
        $wait = $result['details']['problem'] === 'lookup_failed' ? DNS_RETRY_SECONDS : DNS_INTERVAL_SECONDS;
        return ['details' => $result['details'] + ['confirmed' => $confirmed, 'confirmed_domain' => $before['confirmed_domain'] ?? null], 'wait' => $wait, 'change' => null];
    }

    $records = $result['details']['records'];
    $counts = fn (?string $change) => ['details' => $result['details'] + ['confirmed' => $records, 'confirmed_domain' => $result['details']['domain']], 'wait' => DNS_INTERVAL_SECONDS, 'change' => $change];
    if ($confirmed === null || ($before['confirmed_domain'] ?? null) !== $result['details']['domain']) return $counts('First check: ' . lcfirst($result['summary']));
    if ($records === $confirmed) return $counts(null);
    if (($before['unconfirmed'] ?? false) && $records === ($before['records'] ?? null)) {
        return $counts('Mail DNS changed: ' . implode('; ', dns_changes($confirmed, $records)));
    }
    return ['details' => $result['details'] + ['confirmed' => $confirmed, 'confirmed_domain' => $before['confirmed_domain'], 'unconfirmed' => true], 'wait' => DNS_CONFIRM_SECONDS, 'change' => null];
}

/** What changed between two sets of records, one line per record type: "MX now 10 b.example (was 10 a.example)". */
function dns_changes(array $from, array $to): array
{
    $changes = [];
    foreach (MAIL_RECORDS as $key => $record) {
        $was = $from[$key] ?? [];
        $now = $to[$key] ?? [];
        if ($was === $now) continue;
        $changes[] = match (true) {
            !$now => "{$record['label']} removed (was " . implode(', ', $was) . ')',
            !$was => "{$record['label']} added: " . implode(', ', $now),
            default => "{$record['label']} now " . implode(', ', $now) . ' (was ' . implode(', ', $was) . ')',
        };
    }
    return $changes;
}

/**
 * The alert due for a domain's mail records, if any. Any confirmed change
 * from what was last told alerts, since an attacker who changes MX or SPF
 * can read or send the domain's email; a first look is noted without a
 * message, so missing records never alert by themselves, and neither do the
 * records of another domain (the site moved). `snapshot` is the domain and
 * records to store as told.
 *
 * @return array{action: 'send'|'mark'|'none', kind: ?string, snapshot: string, changes: list<string>}
 */
function dns_notice(array $state, ?array $group): array
{
    $records = $state['details']['confirmed'] ?? null;
    $domain = $state['details']['confirmed_domain'] ?? null;
    $told = json_decode($state['alerted_snapshot'] ?: '[]', true) ?: [];
    $result = fn (string $action, ?string $kind = null) => [
        'action' => $action,
        'kind' => $kind,
        'snapshot' => json_encode(['domain' => $domain, 'records' => $records]),
        'changes' => isset($told['records']) && $records ? dns_changes($told['records'], $records) : [],
    ];

    if (in_array($state['status'], ['pending', 'unknown'], true) || $records === null || $told == ['domain' => $domain, 'records' => $records]) return $result('none');
    if (($told['domain'] ?? null) !== $domain || !isset($told['records'])) return $result('mark');
    if (in_array($group['policy'] ?? '', ['silent', 'digest'], true)) return $result('mark');
    return $result('send', 'dns_changed');
}
