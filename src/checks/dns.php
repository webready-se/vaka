<?php

/** How often a domain's mail records are read; a change is confirmed by a second look after DNS_CONFIRM_SECONDS. */
const DNS_INTERVAL_SECONDS = 6 * 3600;
const DNS_CONFIRM_SECONDS = 15 * 60;
const DNS_RETRY_SECONDS = 3600;

/** How long DMARC reports get before quarantine is suggested: four weeks, so that senders who send once a month show up too. */
const DMARC_REPORTS_DAYS = 28;

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

/** Whether the domain's DMARC record asks for reports (rua). */
function dmarc_reports(array $records): bool
{
    return $records['dmarc'] !== [] && (bool) preg_match('/(^|;)\s*rua\s*=/i', $records['dmarc'][0]);
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
 * Whether a domain sends email, as far as its records tell: 'sends', 'none'
 * or 'unknown'. A mail server says it sends, since the people who receive
 * email there send it too, and so does an SPF record that names senders
 * (include:, ip4:, a, mx ...) or two SPF records, one of which may. One SPF
 * record that names none (v=spf1 -all), without a mail server, is the owner
 * saying it sends none. Neither SPF nor a mail server only suggests it: the
 * site itself may still send (a contact form), so the strict records come
 * with that question, and never as the step a guess leads to.
 */
function mail_sending(array $records): string
{
    if ($records['mx'] !== [] && $records['mx'] !== ['0 .']) return 'sends';
    if (count($records['spf']) > 1 || spf_senders($records['spf'][0] ?? '') !== []) return 'sends';
    return $records['spf'] ? 'none' : 'unknown';
}

/** The senders an SPF record names: every term but v=spf1 and its all. */
function spf_senders(string $spf): array
{
    $terms = array_slice(preg_split('/\s+/', trim($spf)) ?: [], 1);
    return array_values(array_filter($terms, fn (string $term) => $term !== '' && !preg_match('/^[-~?+]?all$/i', $term)));
}

/**
 * What to do about the mail records, for the site detail's lead and the read
 * API: a domain that sends no email is told why it still needs SPF and DMARC
 * (anyone can send in its name) and the records that say none of it is real,
 * since without MX it can look as if there is nothing to do. When that is
 * only a guess, it asks first, and what to do if something sends. Others get
 * the advice for their problem; '' with nothing to add.
 */
function mail_advice(array $details): string
{
    $problem = $details['problem'] ?? '';
    $records = $details['records'] ?? null;
    $sending = $records === null ? 'sends' : mail_sending($records);
    if (!in_array($problem, ['no_spf', 'no_dmarc', 'no_spf_dmarc'], true) || $sending === 'sends') return advice_for($problem, 0);
    $add = [
        ...(!$records['spf'] ? ['an SPF record of v=spf1 -all'] : []),
        ...(!$records['dmarc'] ? ['a DMARC record of v=DMARC1; p=reject'] : []),
    ];
    $say = count($add) > 1 ? ' say' : ' says';
    // Its own SPF record says nothing sends
    if ($sending === 'none') return 'This domain sends no email (its SPF record allows no sender), but anyone can still send email that claims to come from it. ' . ucfirst(list_words($add)) . "{$say} that none of it is real. Once " . (count($add) > 1 ? 'they are' : 'it is') . ' there, this warning goes away by itself.';
    // No mail server only suggests it, and if something sends, p=reject stops it even with the sender named in SPF
    return 'Does anything send email as @' . display_domain((string) $details['domain']) . ', such as a contact form or order email from the site? It has no mail server, so perhaps not. '
        . 'If nothing does, ' . list_words($add) . "{$say} that any email in its name is forged, and this warning goes away by itself. "
        . ($records['dmarc']
            ? 'If something does, name it in SPF with ~all instead, such as v=spf1 include:<your mail provider> ~all.'
            : "If something does, don't add these: they would stop its email. Start with v=DMARC1; p=none; rua=mailto:<your report address> and read the reports first.");
}

/**
 * MX, SPF and DMARC with what each does, what is there, how well it is in
 * place (good, weak, missing, or none for a domain without mail) and the
 * record to add, for the site detail: `fix_label` says where, and when the
 * record depends on an answer only the owner has, on what condition; `fix` is
 * the value to paste. A record in place gets none of them. Empty before the
 * first answer.
 *
 * @return list<array{key: string, label: string, what: string, records: list<string>, level: string, note: string, fix_label: string, fix: string, tip: string}>
 */
function dns_items(array $details, ?int $now = null, string $timezone = 'UTC'): array
{
    if (!isset($details['records'])) return [];
    $now ??= time();
    $domain = $details['domain'];
    $shown = display_domain($domain);
    $records = $details['records'];
    $monitoring = $records['dmarc'] && $details['dmarc_policy'] === 'none';
    $nullMx = $records['mx'] === ['0 .'];
    // A domain that says it sends no email can reject everything in its name at once; one that sends, or may, starts with reports
    $sending = mail_sending($records);
    $sends = $sending !== 'none';
    $ifNothing = $sending === 'unknown' ? "Only if nothing sends as @{$shown}, add" : 'Add';
    $reports = $monitoring && dmarc_reports($records);
    // Without rua no reports ever come, so on a domain that sends they are the step before quarantine
    $addReports = $monitoring && $sends && !$reports;
    // Reports that started lately have not had time to show every sender: the next step waits
    $since = isset($details['reports_since']) ? strtotime($details['reports_since']) : null;
    $waitForReports = $reports && $sends && $since !== null && $now < $since + DMARC_REPORTS_DAYS * 86400;
    $day = fn (int $time) => (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone($timezone))->format('j M');
    $item = fn (string $key, array $more) => ['key' => $key] + MAIL_RECORDS[$key] + ['records' => $records[$key]] + $more;
    $none = ['note' => '', 'fix_label' => '', 'fix' => '', 'tip' => ''];
    // Two records: the one to keep names every sender of both
    $merged = implode(' ', ['v=spf1', ...array_unique(array_merge(...array_map('spf_senders', $records['spf'] ?: ['']))), '~all']);
    $siteServer = "Does the site's own server send email too, such as a contact form? Name it with ip4: and the address it sends from, or the term a for the address the site answers on (not behind a CDN). Once the DMARC reports show that all your email passes, change ~all to -all.";

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
        // One SPF record is in place: nothing to add, so an assistant that follows fix leaves it alone
        $item('spf', match (true) {
            count($records['spf']) === 1 => ['level' => 'good'] + $none,
            count($records['spf']) > 1 => [
                'level' => 'weak',
                'note' => 'Two SPF records: receivers treat that as an error, so SPF fails for all of this domain\'s email today.',
                'fix_label' => "Replace both with one TXT record at {$shown} that names every sender in them:",
                'fix' => $merged,
                'tip' => '~all marks other senders as suspect without bouncing their email while you find them all. ' . $siteServer,
            ],
            // ~all while every sender is found: -all could bounce one not named yet, whatever DMARC says
            $sending === 'sends' => [
                'level' => 'missing',
                'note' => '',
                'fix_label' => "Add a TXT record at {$shown}:",
                'fix' => 'v=spf1 include:<your mail provider> ~all',
                'tip' => 'The include: value is in the setup guide of whoever runs the mail server (MX above). ~all marks other senders as suspect without bouncing their email while you find them all. ' . $siteServer,
            ],
            default => [
                'level' => 'missing',
                'note' => '',
                'fix_label' => "{$ifNothing} a TXT record at {$shown}:",
                'fix' => 'v=spf1 -all',
                'tip' => 'Something sends after all, such as a contact form or order email? Then name it instead, with ~all: v=spf1 include:<your mail provider> ~all for a mail provider, or ip4: and the address the site sends from.',
            ],
        }),
        $item('dmarc', $records['dmarc'] && !$monitoring ? ['level' => 'good'] + $none : [
            'level' => !$records['dmarc'] ? 'missing' : 'weak',
            'note' => match (true) {
                !$monitoring => '',
                !$reports => 'p=none without rua: it neither stops email that pretends to come from the domain nor sends reports',
                default => 'p=none only monitors: email that fails the check is still delivered',
            },
            'fix_label' => match (true) {
                $waitForReports => '',
                $monitoring => "Change the TXT record at _dmarc.{$shown} to:",
                default => "{$ifNothing} a TXT record at _dmarc.{$shown}:",
            },
            // A record that only monitors keeps its own reports and options: only p= changes
            'fix' => match (true) {
                $waitForReports => '',
                $addReports => rtrim($records['dmarc'][0], '; ') . (str_contains($records['dmarc'][0], '; ') ? '; ' : ';') . 'rua=mailto:<your report address>',
                $monitoring => preg_replace('/(^|;)(\s*)p\s*=\s*none\b/i', '$1$2p=' . ($sends ? 'quarantine' : 'reject'), $records['dmarc'][0], 1),
                $sending === 'sends' => 'v=DMARC1; p=none; rua=mailto:<your report address>',
                default => 'v=DMARC1; p=reject',
            },
            'tip' => match (true) {
                $waitForReports => 'Reports since ' . $day($since) . ': read them until about ' . $day($since + DMARC_REPORTS_DAYS * 86400) . ', so that senders who send once a month show up too. Then Vaka suggests p=quarantine.',
                $addReports => 'Read the reports for a few weeks: summaries from receivers (zipped XML) of every server that sends in the domain\'s name, and whether its email passes. A free DMARC report service reads them for you and gives the address to use. Once your own email passes, the mail provider\'s and the site\'s own (a contact form, say), change p=none to p=quarantine and keep rua, then p=reject.',
                $monitoring && $sends => 'Do it once the reports show that your own email passes, the mail provider\'s and the site\'s own (a contact form, say); p=reject is the step after that.',
                $monitoring => 'Its SPF record allows no sender, so there is nothing of its own to stop: reject right away.',
                $sending === 'sends' => 'rua is optional, but with p=none the reports are all the record gives: summaries from receivers (zipped XML) of who sends in the domain\'s name. A free DMARC report service reads them for you and gives the address to use. Once your own email passes in them, after a few weeks, move to p=quarantine, then p=reject.',
                $sending === 'none' => 'Its SPF record allows no sender, so reject everything that claims to come from it. Reports are optional.',
                default => 'Something sends after all, or you are not sure? Start with v=DMARC1; p=none; rua=mailto:<your report address> instead and read the reports first: p=reject would stop that email, and a contact form the web server sends itself often fails DMARC whatever SPF says.',
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
function confirm_mail_dns(?array $before, array $result, ?int $now = null): array
{
    $now ??= time();
    $confirmed = $before['confirmed'] ?? null;
    // When the confirmed record started asking for reports (`reports_since`), kept until rua goes; unknown on a first look
    $since = isset($before['reports_since']) ? ['reports_since' => $before['reports_since']] : [];
    if ($result['status'] === 'unknown') {
        $wait = $result['details']['problem'] === 'lookup_failed' ? DNS_RETRY_SECONDS : DNS_INTERVAL_SECONDS;
        return ['details' => $result['details'] + ['confirmed' => $confirmed, 'confirmed_domain' => $before['confirmed_domain'] ?? null] + $since, 'wait' => $wait, 'change' => null];
    }

    $records = $result['details']['records'];
    $counts = fn (?string $change, array $since = []) => ['details' => $result['details'] + ['confirmed' => $records, 'confirmed_domain' => $result['details']['domain']] + $since, 'wait' => DNS_INTERVAL_SECONDS, 'change' => $change];
    if ($confirmed === null || ($before['confirmed_domain'] ?? null) !== $result['details']['domain']) return $counts('First check: ' . lcfirst($result['summary']));
    if ($records === $confirmed) return $counts(null, $since);
    if (($before['unconfirmed'] ?? false) && $records === ($before['records'] ?? null)) {
        $started = !dmarc_reports($records) ? [] : (dmarc_reports($confirmed) ? $since : ['reports_since' => utc_now($now)]);
        return $counts('Mail DNS changed: ' . implode('; ', dns_changes($confirmed, $records)), $started);
    }
    return ['details' => $result['details'] + ['confirmed' => $confirmed, 'confirmed_domain' => $before['confirmed_domain'], 'unconfirmed' => true] + $since, 'wait' => DNS_CONFIRM_SECONDS, 'change' => null];
}

/**
 * The status of the records last confirmed, or null before the first. A
 * change counts at its second look, and the first look already stored the
 * new status, so this, not the stored status, is where a change came from.
 */
function dns_confirmed_status(?array $details): ?string
{
    return isset($details['confirmed'], $details['confirmed_domain']) ? evaluate_mail_dns($details['confirmed_domain'], $details['confirmed'])['status'] : null;
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
