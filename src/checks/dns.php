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

/** The policies a DMARC record's p= and sp= can name. */
const DMARC_POLICIES = ['none', 'quarantine', 'reject'];

/**
 * A DMARC record as receivers read it (RFC 7489 6.3 and 6.6.3, with DKIM's
 * tag-list rules): its tags by name, null when they cannot be read (not
 * separated by semicolons, or one there twice), and what keeps it from
 * applying as written, short for a summary and long for a note, '' for
 * nothing: no valid p=, or an sp= that is not valid.
 *
 * @return array{tags: ?array<string, string>, flaw: string, why: string}
 */
function dmarc_read(string $record): array
{
    $tags = [];
    foreach (explode(';', $record) as $part) {
        if (trim($part) === '') continue;
        // A tag's value never holds another tag: "v=DMARC1 p=reject" and "v=DMARC1, p=reject" lack a semicolon
        if (!preg_match('/^\s*([a-z]+)\s*=\s*(.*?)\s*$/is', $part, $m) || preg_match('/[\s,][a-z]+\s*=/i', $m[2])) {
            return ['tags' => null, 'flaw' => 'tags not separated by semicolons', 'why' => 'The record\'s tags are not separated by semicolons'];
        }
        $name = strtolower($m[1]);
        if (isset($tags[$name])) return ['tags' => null, 'flaw' => "{$name}= is there twice", 'why' => "{$name}= is there twice"];
        $tags[$name] = $m[2];
    }
    // The value itself when it is short enough to show: a typo is seen at once
    $bad = function (string $tag) use ($tags): ?array {
        if (!isset($tags[$tag])) return $tag === 'p' ? ['no p=', 'The record has no p= (none, quarantine or reject)'] : null;
        if (in_array(strtolower($tags[$tag]), DMARC_POLICIES, true)) return null;
        $value = $tags[$tag];
        return $value !== '' && strlen($value) <= 20
            ? ["{$tag}={$value} is not a policy", "{$tag}={$value} is not a policy (none, quarantine or reject)"]
            : ["no valid {$tag}=", "The record has no valid {$tag}= (none, quarantine or reject)"];
    };
    [$flaw, $why] = $bad('p') ?? $bad('sp') ?? ['', ''];
    return ['tags' => $tags, 'flaw' => $flaw, 'why' => $why];
}

/** A DMARC record's rua when it holds at least one address receivers can send to (mailto: or https:), else null. */
function dmarc_rua(?array $tags): ?string
{
    foreach (explode(',', $tags['rua'] ?? '') as $uri) {
        if (preg_match('/^\s*(mailto:[^\s@]+@[^\s@]+|https?:\/\/\S+)/i', $uri)) return $tags['rua'];
    }
    return null;
}

/** Whether the domain's DMARC record asks for reports at an address receivers can use. With two records receivers apply neither, so none come. */
function dmarc_reports(array $records): bool
{
    return count($records['dmarc']) === 1 && dmarc_rua(dmarc_read($records['dmarc'][0])['tags']) !== null;
}

/** The p= of a DMARC record that receivers apply as written: none, quarantine or reject; null when they do not (see dmarc_read()). */
function dmarc_policy(string $record): ?string
{
    $read = dmarc_read($record);
    return $read['flaw'] === '' ? strtolower($read['tags']['p']) : null;
}

/**
 * The policy receivers apply (RFC 7489 6.6.3): the record's p=, or none for a
 * record they cannot apply as written that asks for reports. Null when no
 * DMARC is in force: no record, two of them, or one that asks for no reports.
 */
function dmarc_in_force(array $records): ?string
{
    if (count($records['dmarc']) !== 1) return null;
    return dmarc_policy($records['dmarc'][0]) ?? (dmarc_reports($records) ? 'none' : null);
}

/**
 * A DMARC record built again from $tags (none: a record of its own) with p=
 * set to $policy, right after v=DMARC1 where RFC 7489 puts it, an sp= that is
 * not valid left out (subdomains then follow p=), and $rua in place of a rua
 * that is no address; its other tags stay as they were. Tags apart with "; ",
 * or ";" when the record was written that way.
 */
function dmarc_record(string $record, ?array $tags, string $policy, ?string $rua = null): string
{
    $sep = str_contains($record, ';') && !str_contains($record, '; ') ? ';' : '; ';
    $tags ??= [];
    unset($tags['v'], $tags['p']);
    if (isset($tags['sp']) && !in_array(strtolower($tags['sp']), DMARC_POLICIES, true)) unset($tags['sp']);
    if ($rua !== null && dmarc_rua($tags) === null) $tags['rua'] = $rua;
    return implode($sep, ['v=DMARC1', "p={$policy}", ...array_map(fn (string $name, string $value) => "{$name}={$value}", array_keys($tags), $tags)]);
}

/**
 * A domain's mail records in plain words: ok when SPF and DMARC are in
 * place, warn when one is missing, SPF is there twice, or receivers do not
 * apply the DMARC record as written (two of them, or one they cannot read or
 * without a valid policy); unknown without a domain or an answer. Each of
 * SPF and DMARC says what is wrong with it, so fixing one does not look like
 * the end of it. A domain without MX receives no email, which is fine by itself.
 *
 * @return array{status: string, summary: string, details: array}
 */
function evaluate_mail_dns(?string $domain, ?array $records): array
{
    if ($domain === null) return ['status' => 'unknown', 'summary' => 'No domain to look up: the site is reached by its address', 'details' => ['domain' => null, 'problem' => '']];
    $shown = display_domain($domain);
    if ($records === null) return ['status' => 'unknown', 'summary' => "Could not look up the mail records for {$shown}", 'details' => ['domain' => $domain, 'problem' => 'lookup_failed']];

    $count = count($records['dmarc']);
    $read = $count === 1 ? dmarc_read($records['dmarc'][0]) : null;
    $policy = $read !== null && $read['flaw'] === '' ? strtolower($read['tags']['p']) : null;
    $spf = match (true) {
        count($records['spf']) > 1 => ['two SPF records, which receivers treat as an error', 'spf_twice'],
        !$records['spf'] => ['SPF is missing', 'no_spf'],
        default => null,
    };
    // RFC 7489 6.6.3: with two records receivers apply neither; one they cannot apply as written counts as p=none when it asks for reports, as none at all when it does not
    $dmarc = match (true) {
        $count === 0 => ['DMARC is missing', 'no_dmarc'],
        $count > 1 => ['DMARC is not in force (' . count_words($count) . ' DMARC records)', 'dmarc_twice'],
        $policy === null => [(dmarc_reports($records) ? 'DMARC only monitors' : 'DMARC is not in force') . " ({$read['flaw']})", 'dmarc_no_policy'],
        default => null,
    };
    [$status, $words, $problem] = match (true) {
        $spf !== null && $spf[1] === 'no_spf' && $dmarc !== null && $dmarc[1] === 'no_dmarc' => ['warn', 'SPF and DMARC are missing', 'no_spf_dmarc'],
        $spf !== null => ['warn', $spf[0] . ($dmarc !== null ? ", and {$dmarc[0]}" : ''), $spf[1]],
        $dmarc !== null => ['warn', $dmarc[0], $dmarc[1]],
        default => ['ok', 'SPF and DMARC in place' . ($policy === 'none' ? ', DMARC only monitors (p=none)' : ''), ''],
    };
    return ['status' => $status, 'summary' => "Mail for {$shown}: {$words}", 'details' => ['domain' => $domain, 'records' => $records, 'dmarc_policy' => $policy, 'problem' => $problem]];
}

/** "two", "three", then figures: how many records there are. */
function count_words(int $count): string
{
    return [2 => 'two', 3 => 'three'][$count] ?? (string) $count;
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
    $shown = display_domain((string) ($details['domain'] ?? ''));
    if (in_array($problem, ['dmarc_twice', 'dmarc_no_policy'], true)) return dmarc_advice($records, $sending, $shown);
    if (!in_array($problem, ['no_spf', 'no_dmarc', 'no_spf_dmarc'], true) || $sending === 'sends') return advice_for($problem, 0);
    // A DMARC record receivers do not apply as written is named too, so the warning does go away once both are done
    $count = count($records['dmarc']);
    $valid = $count === 1 && dmarc_policy($records['dmarc'][0]) !== null;
    $add = [
        ...(!$records['spf'] ? ['an SPF record of v=spf1 -all'] : []),
        ...match (true) {
            $count === 0 => ['a DMARC record of v=DMARC1; p=reject'],
            $count > 1 => ['one DMARC record of v=DMARC1; p=reject in place of the ' . count_words($count)],
            !$valid => ['p=reject in its DMARC record'],
            default => [],
        },
    ];
    $say = count($add) > 1 ? ' say' : ' says';
    // Its own SPF record says nothing sends
    if ($sending === 'none') return 'This domain sends no email (its SPF record allows no sender), but anyone can still send email that claims to come from it. ' . ucfirst(list_words($add)) . "{$say} that none of it is real. Once " . (count($add) > 1 ? 'they are' : 'it is') . ' there, this warning goes away by itself.';
    // No mail server only suggests it, and if something sends, p=reject stops it even with the sender named in SPF
    return "Does anything send email as @{$shown}, such as a contact form or order email from the site? It has no mail server, so perhaps not. "
        . 'If nothing does, ' . list_words($add) . "{$say} that any email in its name is forged, and this warning goes away by itself. "
        . match (true) {
            $valid => 'If something does, name it in SPF with ~all instead, such as v=spf1 include:<your mail provider> ~all.',
            $count === 1 => "If something does, don't add these: they would stop its email. Set p=none in its DMARC record instead, "
                . (dmarc_reports($records) ? 'and read the reports it asks for.' : 'with rua=mailto:<your report address>, and read the reports first.'),
            $count > 1 => "If something does, don't add these: they would stop its email. Start with one DMARC record of v=DMARC1; p=none; rua=mailto:<your report address> in place of the " . count_words($count) . ', and read the reports first.',
            default => "If something does, don't add these: they would stop its email. Start with v=DMARC1; p=none; rua=mailto:<your report address> and read the reports first.",
        };
}

/**
 * What to do about a DMARC record receivers do not apply as written, for a
 * domain whose SPF is in place: what they do with it today, then the record
 * to put instead. Never the policy it meant: one that was never applied could
 * stop some of the domain's own email the day it is.
 */
function dmarc_advice(array $records, string $sending, string $shown): string
{
    $count = count($records['dmarc']);
    $none = 'This domain sends no email (its SPF record allows no sender), so ';
    // Read as p=none, with reports: p=none makes it say so, and changes nothing
    if (dmarc_in_force($records) === 'none') {
        $lead = 'Receivers read this DMARC record as p=none today (' . dmarc_read($records['dmarc'][0])['flaw'] . '). ';
        return $lead . ($sending === 'none' ? "{$none}change it to p=reject." : 'Set p=none in it: nothing changes, but it says what it does, and from there it moves on as a record that only monitors.');
    }
    $to = fn (string $policy) => $count > 1 ? "replace them with one at {$policy}" : "change it to {$policy}";
    $lead = $count > 1
        ? 'Receivers apply ' . ($count === 2 ? 'neither of the two' : 'none of the ' . count_words($count)) . ' DMARC records today, so DMARC is not in force. '
        : 'Receivers ignore this DMARC record today (' . dmarc_read($records['dmarc'][0])['flaw'] . '), so DMARC is not in force. ';
    return $lead . match ($sending) {
        'sends' => ucfirst($to('p=none with reports')) . ', whatever ' . ($count > 1 ? 'they say' : 'it says') . ': a stricter policy was never applied, and could stop some of your own email.',
        'none' => $none . $to('p=reject') . '.',
        default => "If nothing sends email as @{$shown}, " . $to('p=reject') . '; if something does, ' . $to('p=none with reports') . '.',
    };
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
    // What receivers apply, which is not always what the record says: p=none for one they cannot apply as written that asks for reports
    $force = dmarc_in_force($records);
    $monitoring = $force === 'none';
    // Two records, or one they cannot apply that asks for no reports: receivers ignore it, as if there were none
    $ignored = $records['dmarc'] && $force === null;
    $count = count($records['dmarc']);
    $all = $count === 2 ? 'both' : 'all of them';
    $record = $records['dmarc'][0] ?? '';
    $read = dmarc_read($record);
    $valid = $count === 1 && $read['flaw'] === '';
    // A record of p=none as written, the one that steps on to quarantine; one only read as p=none is made valid first
    $stepOn = $monitoring && $valid;
    // Of two records, the one that asks for reports is the one to keep, with its address and options
    $base = null;
    foreach ($records['dmarc'] as $one) {
        $tags = dmarc_read($one)['tags'];
        if ($base === null && dmarc_rua($tags) !== null) $base = [$one, $tags];
    }
    $placeholder = 'mailto:<your report address>';
    $nullMx = $records['mx'] === ['0 .'];
    $nullMx = $records['mx'] === ['0 .'];
    // A domain that says it sends no email can reject everything in its name at once; one that sends, or may, starts with reports
    $sending = mail_sending($records);
    $sends = $sending !== 'none';
    $only = fn (string $do) => $sending === 'unknown' ? "Only if nothing sends as @{$shown}, {$do}" : ucfirst($do);
    $reports = $monitoring && dmarc_reports($records);
    // Without rua no reports ever come, so on a domain that sends they are the step before quarantine
    $addReports = $stepOn && $sends && !$reports;
    // Reports that started lately have not had time to show every sender: the next step waits
    $since = isset($details['reports_since']) ? strtotime($details['reports_since']) : null;
    $waitForReports = $stepOn && $reports && $sends && $since !== null && $now < $since + DMARC_REPORTS_DAYS * 86400;
    // While SPF gives an error, DMARC passes on DKIM alone: a stricter policy waits for SPF
    $waitForSpf = $stepOn && $sends && !$addReports && !$waitForReports && count($records['spf']) > 1;
    $day = fn (int $time) => (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone($timezone))->format('j M');
    $item = fn (string $key, array $more) => ['key' => $key] + MAIL_RECORDS[$key] + ['records' => $records[$key]] + $more;
    $none = ['note' => '', 'fix_label' => '', 'fix' => '', 'tip' => ''];
    // Two records: the one to keep names every sender of both. A redirect= is ignored next to an all (RFC 7208 6.1), so it becomes an include:, and exp= could only be there once
    $senders = array_map(fn (string $term) => (string) preg_replace('/^redirect=/i', 'include:', $term), array_merge(...array_map('spf_senders', $records['spf'] ?: [''])));
    $merged = implode(' ', ['v=spf1', ...array_unique(array_filter($senders, fn (string $term) => !preg_match('/^exp=/i', $term))), '~all']);
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
                'fix_label' => $only("add a TXT record at {$shown}:"),
                'fix' => 'v=spf1 -all',
                'tip' => 'Something sends after all, such as a contact form or order email? Then name it instead, with ~all: v=spf1 include:<your mail provider> ~all for a mail provider, or ip4: and the address the site sends from.',
            ],
        }),
        $item('dmarc', in_array($force, ['quarantine', 'reject'], true) ? ['level' => 'good'] + $none : [
            'level' => !$records['dmarc'] ? 'missing' : 'weak',
            'note' => match (true) {
                $count > 1 => ucfirst(count_words($count)) . " DMARC records: receivers ignore {$all}, so DMARC is not in force",
                // Tags receivers cannot read say nothing at all, reports or not
                $ignored && $read['tags'] === null => "{$read['why']}, so receivers ignore the record: DMARC is not in force",
                $ignored => "{$read['why']}, and there is no valid report address (rua), so receivers ignore the record: DMARC is not in force",
                $monitoring && !$valid => "{$read['why']}, so receivers treat the record as p=none: email that fails the check is still delivered",
                !$monitoring => '',
                !$reports => 'p=none without rua: it neither stops email that pretends to come from the domain nor sends reports',
                default => 'p=none only monitors: email that fails the check is still delivered',
            },
            'fix_label' => match (true) {
                $waitForReports, $waitForSpf => '',
                $monitoring => "Change the TXT record at _dmarc.{$shown} to:",
                $count > 1 => $only("replace {$all} with one TXT record at _dmarc.{$shown}:"),
                $ignored => $only("change the TXT record at _dmarc.{$shown} to:"),
                default => $only("add a TXT record at _dmarc.{$shown}:"),
            },
            // The record keeps its own reports and options: p= changes, and rua comes in where it is needed
            'fix' => match (true) {
                $waitForReports, $waitForSpf => '',
                $addReports => dmarc_record($record, $read['tags'], 'none', $placeholder),
                // Read as p=none already: p=none makes it valid and changes nothing
                $monitoring && !$valid => dmarc_record($record, $read['tags'], $sends ? 'none' : 'reject'),
                $monitoring => dmarc_record($record, $read['tags'], $sends ? 'quarantine' : 'reject'),
                $ignored && $count === 1 => dmarc_record($record, $read['tags'], $sending === 'sends' ? 'none' : 'reject', $sending === 'sends' ? $placeholder : null),
                $base !== null => dmarc_record($base[0], $base[1], $sending === 'sends' ? 'none' : 'reject'),
                $sending === 'sends' => "v=DMARC1; p=none; rua={$placeholder}",
                default => 'v=DMARC1; p=reject',
            },
            'tip' => match (true) {
                $waitForReports => 'Reports since ' . $day($since) . ': read them until about ' . $day($since + DMARC_REPORTS_DAYS * 86400) . ', so that senders who send once a month show up too. Then Vaka suggests p=quarantine.',
                $waitForSpf => 'Merge the two SPF records first: while SPF gives an error, email that DKIM does not sign fails DMARC too, and p=quarantine would send it to spam.',
                $addReports => 'Read the reports for a few weeks: summaries from receivers (zipped XML) of every server that sends in the domain\'s name, and whether its email passes. A free DMARC report service reads them for you and gives the address to use. Once your own email passes, the mail provider\'s and the site\'s own (a contact form, say), change p=none to p=quarantine and keep rua, then p=reject.',
                $monitoring && !$valid && $sends => 'Receivers read the record as p=none already, so this changes nothing but makes it say what it does. '
                    . ($sending === 'sends'
                        ? 'Once the reports show that your own email passes, the mail provider\'s and the site\'s own (a contact form, say), move to p=quarantine, then p=reject.'
                        : "If nothing sends email as @{$shown}, p=reject instead."),
                $monitoring && $sends => 'Do it once the reports show that your own email passes, the mail provider\'s and the site\'s own (a contact form, say); p=reject is the step after that.',
                $monitoring => 'Its SPF record allows no sender, so there is nothing of its own to stop: reject right away.',
                // A record that was never applied stops nothing today, so a stricter one is a change, whatever it meant
                $sending === 'sends' => ($ignored ? ($count > 1 ? "Start at p=none, whatever the records say: receivers ignore {$all} today" : 'Start at p=none, whatever the record says: receivers ignore it today') . ', so DMARC stops none of your email now, and p=quarantine or p=reject could stop some that fails. ' : '')
                    . 'rua is optional, but with p=none the reports are all the record gives: summaries from receivers (zipped XML) of who sends in the domain\'s name. A free DMARC report service reads them for you and gives the address to use. Once your own email passes in them, after a few weeks, move to p=quarantine, then p=reject.',
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
