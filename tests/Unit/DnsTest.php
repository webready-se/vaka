<?php

beforeEach(function () {
    $this->records = [
        'shop.example' => [
            DNS_MX => [['type' => 'MX', 'pri' => 20, 'target' => 'mx2.mail.example'], ['type' => 'MX', 'pri' => 10, 'target' => 'MX1.mail.example.']],
            DNS_TXT => [txt('google-site-verification=abc'), txt('v=spf1 include:_spf.mail.example -all')],
        ],
        '_dmarc.shop.example' => [DNS_TXT => [txt('v=DMARC1; p=reject; rua=mailto:dmarc@shop.example')]],
    ];
    $this->resolve = fake_resolver($this->records);
});

// =====================================================================
// Looking up and reading the records
// =====================================================================

test('lookup_mail_dns: MX by priority, the SPF and DMARC records, in one spelling', function () {
    expect(lookup_mail_dns('shop.example', $this->resolve))->toBe([
        'mx' => ['10 mx1.mail.example', '20 mx2.mail.example'],
        'spf' => ['v=spf1 include:_spf.mail.example -all'],
        'dmarc' => ['v=DMARC1; p=reject; rua=mailto:dmarc@shop.example'],
    ]);

    // A long SPF record comes in pieces; a failed lookup is not the same as no records
    $this->records['shop.example'][DNS_TXT] = [txt('v=spf1 ' . str_repeat('include:a.example ', 20) . '-all')];
    expect(lookup_mail_dns('shop.example', $this->resolve)['spf'][0])->toEndWith('include:a.example -all');
    $this->records['_dmarc.shop.example'][DNS_TXT] = false;
    expect(lookup_mail_dns('shop.example', $this->resolve))->toBeNull();
});

test('mail_domain keeps the domain it had while the domain check cannot say, as long as the site is still on it', function () {
    expect(mail_domain('https://www.shop.example.co.uk', null, 'shop.example.co.uk'))->toBe('shop.example.co.uk')
        ->and(mail_domain('https://www.shop.example.co.uk', 'shop.example.co.uk', 'example.co.uk'))->toBe('shop.example.co.uk')
        ->and(mail_domain('https://b.example', null, 'a.example'))->toBe('b.example');
});

test('a new mail domain is a new start, not a change, and its first look never alerts', function () {
    $a = evaluate_mail_dns('a.example', ['mx' => ['10 mx.a.example'], 'spf' => ['v=spf1 -all'], 'dmarc' => []]);
    $b = evaluate_mail_dns('b.example', ['mx' => ['10 mx.b.example'], 'spf' => ['v=spf1 -all'], 'dmarc' => []]);
    $first = confirm_mail_dns(null, $a);
    $moved = confirm_mail_dns($first['details'], $b);
    expect($moved['change'])->toBe('First check: mail for b.example: DMARC is missing')
        ->and($moved['wait'])->toBe(DNS_INTERVAL_SECONDS)
        ->and($moved['details']['confirmed_domain'])->toBe('b.example');

    $told = dns_notice(['status' => 'warn', 'details' => $first['details'], 'alerted_snapshot' => '[]'], null)['snapshot'];
    expect(dns_notice(['status' => 'warn', 'details' => $moved['details'], 'alerted_snapshot' => $told], ['policy' => 'immediate'])['action'])->toBe('mark');
});

test('mail_domain: the domain check\'s domain, else the domain the site is on', function () {
    expect(mail_domain('https://www.shop.example/path', 'shop.example'))->toBe('shop.example')
        ->and(mail_domain('https://app.shop.example', null))->toBe('shop.example')
        ->and(mail_domain('https://shop.example', null))->toBe('shop.example')
        ->and(mail_domain('http://192.0.2.10', null))->toBeNull();
});

test('evaluate_mail_dns says in plain words whether SPF and DMARC are in place', function (array $records, string $status, string $summary, string $problem) {
    $result = evaluate_mail_dns('shop.example', $records);
    expect($result['status'])->toBe($status)
        ->and($result['summary'])->toBe($summary)
        ->and($result['details']['problem'])->toBe($problem);
})->with([
    'all in place' => [['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=reject']], 'ok', 'Mail for shop.example: SPF and DMARC in place', ''],
    'DMARC only monitors' => [['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=none; rua=mailto:d@shop.example']], 'ok', 'Mail for shop.example: SPF and DMARC in place, DMARC only monitors (p=none)', ''],
    'no DMARC' => [['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 -all'], 'dmarc' => []], 'warn', 'Mail for shop.example: DMARC is missing', 'no_dmarc'],
    'no SPF' => [['mx' => ['10 mx1.mail.example'], 'spf' => [], 'dmarc' => ['v=DMARC1; p=reject']], 'warn', 'Mail for shop.example: SPF is missing', 'no_spf'],
    'neither' => [['mx' => [], 'spf' => [], 'dmarc' => []], 'warn', 'Mail for shop.example: SPF and DMARC are missing', 'no_spf_dmarc'],
    'two SPF records' => [['mx' => [], 'spf' => ['v=spf1 -all', 'v=spf1 include:a.example -all'], 'dmarc' => ['v=DMARC1; p=reject']], 'warn', 'Mail for shop.example: two SPF records, which receivers treat as an error', 'spf_twice'],
]);

test('evaluate_mail_dns is unknown without a domain or an answer', function () {
    expect(evaluate_mail_dns(null, null))->toMatchArray(['status' => 'unknown', 'summary' => 'No domain to look up: the site is reached by its address'])
        ->and(evaluate_mail_dns('shop.example', null))->toMatchArray(['status' => 'unknown', 'summary' => 'Could not look up the mail records for shop.example']);
});

test('dns_items: MX, SPF and DMARC with what each does and the record to add', function () {
    $items = dns_items(evaluate_mail_dns('shop.example', ['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=none']])['details']);
    expect(array_column($items, 'level', 'key'))->toBe(['mx' => 'none', 'spf' => 'good', 'dmarc' => 'weak'])
        ->and($items[0])->toMatchArray(['label' => 'MX', 'records' => [], 'note' => 'No mail server: email to this domain bounces. Fine if it neither sends nor receives email.'])
        ->and($items[1])->toMatchArray(['label' => 'SPF', 'records' => ['v=spf1 -all']])
        ->and($items[2]['note'])->toBe('p=none without rua: it neither stops email that pretends to come from the domain nor sends reports')
        ->and($items[2]['fix_label'])->toBe('Change the TXT record at _dmarc.shop.example to:')
        // SPF says it sends nothing, so there is nothing of its own to protect: reject at once
        ->and($items[2]['fix'])->toBe('v=DMARC1; p=reject');

    // A null MX (RFC 7505) says on purpose that the domain takes no email
    $this->records['shop.example'][DNS_MX] = [['type' => 'MX', 'pri' => 0, 'target' => '']];
    $null = dns_items(evaluate_mail_dns('shop.example', lookup_mail_dns('shop.example', $this->resolve))['details']);
    expect($null[0])->toMatchArray(['level' => 'none', 'records' => ['0 .'], 'note' => 'A null MX: the domain says it takes no email, as a domain without email should.']);

    $missing = dns_items(evaluate_mail_dns('shop.example', ['mx' => ['10 mx1.mail.example'], 'spf' => [], 'dmarc' => []])['details']);
    expect(array_column($missing, 'level', 'key'))->toBe(['mx' => 'good', 'spf' => 'missing', 'dmarc' => 'missing'])
        ->and($missing[1]['fix_label'])->toBe('Add a TXT record at shop.example:')
        ->and($missing[1]['fix'])->toBe('v=spf1 include:<your mail provider> -all')
        ->and($missing[2]['fix'])->toBe('v=DMARC1; p=none; rua=mailto:<your report address>')
        ->and($missing[1]['tip'])->toBe('Sends no email at all? Publish v=spf1 -all, so nobody can send in its name.');
});

test('dns_items: a domain that sends no email is told to reject at once; one that sends, or may, to start with reports', function () {
    $items = fn (array $records) => array_column(dns_items(evaluate_mail_dns('shop.example', $records + ['mx' => [], 'spf' => [], 'dmarc' => []])['details']), null, 'key');

    // No mail server and no SPF: it looks like it sends nothing
    $none = $items([]);
    expect($none['spf']['fix'])->toBe('v=spf1 -all')
        ->and($none['spf']['tip'])->toBe('Sends email after all, through a mail provider or the site itself? Use v=spf1 include:<your mail provider> -all instead.')
        ->and($none['dmarc']['fix'])->toBe('v=DMARC1; p=reject')
        ->and($none['dmarc']['tip'])->toContain('looks like it sends no email')
        ->and($none['dmarc']['tip'])->toContain('Reports are optional');

    // Takes email but SPF allows no sender: reject too
    expect($items(['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 -all']])['dmarc']['fix'])->toBe('v=DMARC1; p=reject');

    // SPF names senders: it sends, so reports first
    $sends = $items(['spf' => ['v=spf1 include:_spf.mail.example ~all']]);
    expect($sends['dmarc']['fix'])->toBe('v=DMARC1; p=none; rua=mailto:<your report address>')
        ->and($sends['dmarc']['tip'])->toContain('rua is optional')
        ->and($sends['dmarc']['tip'])->toContain('A free DMARC report service')
        ->and($sends['dmarc']['tip'])->toContain('Once your own email passes in them');

    // A mail server but no SPF: people there send too, and reject could stop their email, so the safe way
    expect($items(['mx' => ['10 mx1.mail.example']])['dmarc']['fix'])->toBe('v=DMARC1; p=none; rua=mailto:<your report address>');

    // A record that only monitors, on a domain that sends: quarantine next, once the reports look right
    $monitoring = $items(['spf' => ['v=spf1 include:_spf.mail.example -all'], 'dmarc' => ['v=DMARC1; p=none; rua=mailto:reports@dmarc.example']]);
    expect($monitoring['dmarc']['note'])->toBe('p=none only monitors: email that fails the check is still delivered')
        ->and($monitoring['dmarc']['fix'])->toBe('v=DMARC1; p=quarantine; rua=mailto:reports@dmarc.example')
        ->and($monitoring['dmarc']['tip'])->toContain('once the reports show that your own email passes');

    // Without rua no reports ever come, so there is nothing to wait for: rua first, p=none kept
    $silent = $items(['spf' => ['v=spf1 include:_spf.mail.example -all'], 'dmarc' => ['v=DMARC1; p=none; sp=none;']]);
    expect($silent['dmarc']['note'])->toBe('p=none without rua: it neither stops email that pretends to come from the domain nor sends reports')
        ->and($silent['dmarc']['fix'])->toBe('v=DMARC1; p=none; sp=none; rua=mailto:<your report address>')
        ->and($silent['dmarc']['tip'])->toContain('Read the reports for a few weeks')
        ->and($silent['dmarc']['tip'])->toContain('change p=none to p=quarantine and keep rua');

    // Written without spaces, it stays that way
    expect($items(['mx' => ['10 mx1.mail.example'], 'dmarc' => ['v=DMARC1;p=none']])['dmarc']['fix'])->toBe('v=DMARC1;p=none;rua=mailto:<your report address>');

    // A domain that sends nothing needs no reports to reject
    expect($items(['spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=none']])['dmarc']['fix'])->toBe('v=DMARC1; p=reject');
});

// =====================================================================
// Changes, confirmed by a second look
// =====================================================================

test('a DMARC record that only monitors keeps everything else when the hint says to quarantine', function () {
    $record = 'v=DMARC1; p=none; rua=mailto:dmarc@shop.example; ruf=mailto:dmarc@shop.example; fo=1';
    $items = dns_items(evaluate_mail_dns('shop.example', ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 include:_spf.mail.example -all'], 'dmarc' => [$record]])['details']);
    expect($items[2]['fix'])->toBe('v=DMARC1; p=quarantine; rua=mailto:dmarc@shop.example; ruf=mailto:dmarc@shop.example; fo=1');

    // Written without spaces, and with sp= for subdomains left alone
    $items = dns_items(evaluate_mail_dns('shop.example', ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 include:_spf.mail.example -all'], 'dmarc' => ['v=DMARC1;p=none;sp=none;pct=100;rua=mailto:d@shop.example']])['details']);
    expect($items[2]['fix'])->toBe('v=DMARC1;p=quarantine;sp=none;pct=100;rua=mailto:d@shop.example');
});

test('confirm_mail_dns: a change counts only when a second look agrees', function () {
    $first = evaluate_mail_dns('shop.example', ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=reject']]);
    $changed = evaluate_mail_dns('shop.example', ['mx' => ['10 mx9.other.example'], 'spf' => ['v=spf1 -all'], 'dmarc' => []]);

    $baseline = confirm_mail_dns(null, $first);
    expect($baseline['change'])->toBe('First check: mail for shop.example: SPF and DMARC in place')
        ->and($baseline['wait'])->toBe(DNS_INTERVAL_SECONDS);

    $same = confirm_mail_dns($baseline['details'], $first);
    expect($same['change'])->toBeNull()->and($same['wait'])->toBe(DNS_INTERVAL_SECONDS);

    $once = confirm_mail_dns($baseline['details'], $changed);
    expect($once['change'])->toBeNull()
        ->and($once['wait'])->toBe(DNS_CONFIRM_SECONDS)
        ->and($once['details']['confirmed'])->toBe($baseline['details']['confirmed']);

    // A blip: back to what was confirmed
    expect(confirm_mail_dns($once['details'], $first)['change'])->toBeNull();

    $twice = confirm_mail_dns($once['details'], $changed);
    expect($twice['change'])->toBe('Mail DNS changed: MX now 10 mx9.other.example (was 10 mx1.mail.example); DMARC removed (was v=DMARC1; p=reject)')
        ->and($twice['details']['confirmed'])->toBe(['mx' => ['10 mx9.other.example'], 'spf' => ['v=spf1 -all'], 'dmarc' => []]);

    // No answer: keep what was confirmed and look again in an hour
    $unknown = confirm_mail_dns($twice['details'], evaluate_mail_dns('shop.example', null));
    expect($unknown['change'])->toBeNull()
        ->and($unknown['wait'])->toBe(DNS_RETRY_SECONDS)
        ->and($unknown['details']['confirmed'])->toBe($twice['details']['confirmed']);
});

test('dns_changes: what was added, removed or changed, record by record', function () {
    $from = ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 -all'], 'dmarc' => []];
    $to = ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 include:new.example -all'], 'dmarc' => ['v=DMARC1; p=none']];
    expect(dns_changes($from, $to))->toBe(['SPF now v=spf1 include:new.example -all (was v=spf1 -all)', 'DMARC added: v=DMARC1; p=none'])
        ->and(dns_changes($to, ['mx' => [], 'spf' => $to['spf'], 'dmarc' => $to['dmarc']]))->toBe(['MX removed (was 10 mx1.mail.example)']);
});

test('dns_notice: any confirmed change alerts, a first look never does', function () {
    $records = ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 -all'], 'dmarc' => []];
    $snapshot = json_encode(['domain' => 'shop.example', 'records' => $records]);
    $state = ['status' => 'warn', 'details' => ['confirmed' => $records, 'confirmed_domain' => 'shop.example'], 'alerted_snapshot' => '[]'];

    expect(dns_notice($state, null))->toMatchArray(['action' => 'mark', 'kind' => null, 'snapshot' => $snapshot])
        ->and(dns_notice(['alerted_snapshot' => $snapshot] + $state, null)['action'])->toBe('none')
        ->and(dns_notice(['status' => 'unknown'] + $state, null)['action'])->toBe('none');

    $told = json_encode(['domain' => 'shop.example', 'records' => ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=reject']]]);
    $notice = dns_notice(['alerted_snapshot' => $told] + $state, ['policy' => 'immediate']);
    expect($notice)->toMatchArray(['action' => 'send', 'kind' => 'dns_changed', 'changes' => ['DMARC removed (was v=DMARC1; p=reject)']])
        ->and(dns_notice(['alerted_snapshot' => $told] + $state, ['policy' => 'digest'])['action'])->toBe('mark');
});

test('a mail DNS change reads plainly, with what to do if it was not on purpose', function () {
    $notice = notice(['kind' => 'dns_changed', 'domain' => 'shop.example', 'server_key' => null, 'server' => null, 'changes' => ['MX now 10 mx9.other.example (was 10 mx1.mail.example)']]);
    expect(alert_text(bundle_notices([$notice], [])[0]['items'][0]))->toBe([
        'headline' => 'shop.example (Customers): mail DNS changed',
        'details' => [
            'MX now 10 mx9.other.example (was 10 mx1.mail.example)',
            'If that was not you or your DNS provider, someone may have access to the domain: check who can change its DNS.',
        ],
    ])
        ->and(slack_payload(bundle_notices([$notice], [])[0], '')['text'])->toStartWith(':warning: *shop.example (Customers): mail DNS changed*');
});

// =====================================================================
// Reports first: quarantine only once they have had time
// =====================================================================

test('rua added: the quarantine step waits four weeks from when Vaka saw the reports start', function () {
    $sends = ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 include:_spf.mail.example -all']];
    $silent = evaluate_mail_dns('shop.example', $sends + ['dmarc' => ['v=DMARC1; p=none; sp=none;']]);
    $reporting = evaluate_mail_dns('shop.example', $sends + ['dmarc' => ['v=DMARC1; p=none; sp=none; rua=mailto:d@reports.example']]);
    $start = strtotime('2026-10-29 10:00:00 UTC');
    $confirmedAt = $start + DNS_CONFIRM_SECONDS;

    $baseline = confirm_mail_dns(null, $silent, $start - 86400);
    $once = confirm_mail_dns($baseline['details'], $reporting, $start);
    expect($once['details'])->not->toHaveKey('reports_since');
    $twice = confirm_mail_dns($once['details'], $reporting, $confirmedAt);
    expect($twice['details']['reports_since'])->toBe(utc_now($confirmedAt));

    // The next look keeps the day the reports started
    $later = confirm_mail_dns($twice['details'], $reporting, $confirmedAt + DNS_INTERVAL_SECONDS);
    expect($later['details']['reports_since'])->toBe(utc_now($confirmedAt));

    $dmarc = fn (array $details, int $now) => dns_items($details, $now, 'Europe/Stockholm')[2];
    $waiting = $dmarc($later['details'], $confirmedAt + 3600);
    expect($waiting['level'])->toBe('weak')
        ->and($waiting['fix'])->toBe('')
        ->and($waiting['fix_label'])->toBe('')
        ->and($waiting['tip'])->toBe('Reports since 29 Oct: read them until about 26 Nov, so that senders who send once a month show up too. Then Vaka suggests p=quarantine.');

    $ready = $dmarc($later['details'], $confirmedAt + DMARC_REPORTS_DAYS * 86400);
    expect($ready['fix'])->toBe('v=DMARC1; p=quarantine; sp=none; rua=mailto:d@reports.example')
        ->and($ready['tip'])->toContain('once the reports show that your own email passes');

    // rua taken away again: nothing to wait for
    $gone = confirm_mail_dns(confirm_mail_dns($later['details'], $silent, $start + 7 * 86400)['details'], $silent, $start + 7 * 86400 + DNS_CONFIRM_SECONDS);
    expect($gone['details']['reports_since'] ?? null)->toBeNull();
});

test('rua there from the first look: Vaka cannot tell how long the reports have come, so it does not wait', function () {
    $first = confirm_mail_dns(null, evaluate_mail_dns('shop.example', ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 include:_spf.mail.example -all'], 'dmarc' => ['v=DMARC1; p=none; rua=mailto:d@reports.example']]), time());
    expect($first['details']['reports_since'] ?? null)->toBeNull()
        ->and(dns_items($first['details'])[2]['fix'])->toBe('v=DMARC1; p=quarantine; rua=mailto:d@reports.example');
});
