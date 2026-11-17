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
    // RFC 7489 6.6.3: without a valid p= (or with an sp= that is not valid), a record that asks for reports counts as p=none, one that does not as no record at all
    'p= misspelt' => [['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=rejct']], 'warn', 'Mail for shop.example: DMARC is not in force (p=rejct is not a policy)', 'dmarc_no_policy'],
    'p= missing' => [['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; sp=reject']], 'warn', 'Mail for shop.example: DMARC is not in force (no p=)', 'dmarc_no_policy'],
    'p= with more after it' => [['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=reject-all']], 'warn', 'Mail for shop.example: DMARC is not in force (p=reject-all is not a policy)', 'dmarc_no_policy'],
    'p= missing, with reports' => [['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; rua=mailto:d@shop.example']], 'warn', 'Mail for shop.example: DMARC only monitors (no p=)', 'dmarc_no_policy'],
    'a rua that is no address asks for nothing' => [['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=rejct; rua=d@shop.example']], 'warn', 'Mail for shop.example: DMARC is not in force (p=rejct is not a policy)', 'dmarc_no_policy'],
    'sp= misspelt' => [['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=reject; sp=rejct']], 'warn', 'Mail for shop.example: DMARC is not in force (sp=rejct is not a policy)', 'dmarc_no_policy'],
    // DKIM's tag-list rules, which DMARC uses: tags apart with semicolons, and none twice
    'no semicolon between tags' => [['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1 p=reject']], 'warn', 'Mail for shop.example: DMARC is not in force (tags not separated by semicolons)', 'dmarc_no_policy'],
    'p= twice' => [['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=reject; p=none']], 'warn', 'Mail for shop.example: DMARC is not in force (p= is there twice)', 'dmarc_no_policy'],
    // 6.6.3 again: more than one record, and no DMARC is applied
    'two DMARC records' => [['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=none; rua=mailto:d@shop.example', 'v=DMARC1; p=reject']], 'warn', 'Mail for shop.example: DMARC is not in force (two DMARC records)', 'dmarc_twice'],
    'p= with spaces' => [['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p = reject ;']], 'ok', 'Mail for shop.example: SPF and DMARC in place', ''],
    // Both problems are named, so fixing the first does not look like the end of it
    'two SPF records and no DMARC' => [['mx' => [], 'spf' => ['v=spf1 -all', 'v=spf1 include:a.example -all'], 'dmarc' => []], 'warn', 'Mail for shop.example: two SPF records, which receivers treat as an error, and DMARC is missing', 'spf_twice'],
    'no SPF, and DMARC not in force' => [['mx' => [], 'spf' => [], 'dmarc' => ['v=DMARC1; p=rejct']], 'warn', 'Mail for shop.example: SPF is missing, and DMARC is not in force (p=rejct is not a policy)', 'no_spf'],
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
        // ~all while every sender is found: -all could bounce one that is not named yet, whatever DMARC says
        ->and($missing[1]['fix'])->toBe('v=spf1 include:<your mail provider> ~all')
        ->and($missing[2]['fix'])->toBe('v=DMARC1; p=none; rua=mailto:<your report address>')
        ->and($missing[1]['tip'])->toBe('The include: value is in the setup guide of whoever runs the mail server (MX above). ~all marks other senders as suspect without bouncing their email while you find them all. Does the site\'s own server send email too, such as a contact form? Name it with ip4: and the address it sends from, or the term a for the address the site answers on (not behind a CDN). Once the DMARC reports show that all your email passes, change ~all to -all.');

    // What is in place gets no line to add: an assistant that follows fix must not weaken it
    foreach ([['v=spf1 include:_spf.mail.example -all'], ['v=spf1 include:_spf.mail.example ~all']] as $spf) {
        foreach (['v=DMARC1; p=reject', 'v=DMARC1; p=quarantine; rua=mailto:d@shop.example'] as $dmarc) {
            $good = array_column(dns_items(evaluate_mail_dns('shop.example', ['mx' => ['10 mx1.mail.example'], 'spf' => $spf, 'dmarc' => [$dmarc]])['details']), null, 'key');
            expect([$good['spf']['fix'], $good['spf']['fix_label'], $good['spf']['tip'], $good['dmarc']['fix'], $good['dmarc']['fix_label'], $good['dmarc']['tip']])->toBe(['', '', '', '', '', '']);
        }
    }
});

test('dns_items: a domain that sends no email is told to reject at once; one that sends, or may, to start with reports', function () {
    $items = fn (array $records) => array_column(dns_items(evaluate_mail_dns('shop.example', $records + ['mx' => [], 'spf' => [], 'dmarc' => []])['details']), null, 'key');

    // No mail server and no SPF only suggests it sends nothing: the strict records come with the condition
    $unknown = $items([]);
    expect($unknown['spf']['fix_label'])->toBe('Only if nothing sends as @shop.example, add a TXT record at shop.example:')
        ->and($unknown['spf']['fix'])->toBe('v=spf1 -all')
        ->and($unknown['spf']['tip'])->toBe('Something sends after all, such as a contact form or order email? Then name it instead, with ~all: v=spf1 include:<your mail provider> ~all for a mail provider, or ip4: and the address the site sends from.')
        ->and($unknown['dmarc']['fix_label'])->toBe('Only if nothing sends as @shop.example, add a TXT record at _dmarc.shop.example:')
        ->and($unknown['dmarc']['fix'])->toBe('v=DMARC1; p=reject')
        ->and($unknown['dmarc']['tip'])->toBe('Something sends after all, or you are not sure? Start with v=DMARC1; p=none; rua=mailto:<your report address> instead and read the reports first: p=reject would stop that email, and a contact form the web server sends itself often fails DMARC whatever SPF says.');

    // Its own SPF record says nothing sends, and it takes no email: reject at once, as a fact
    $none = $items(['spf' => ['v=spf1 -all']]);
    expect($none['dmarc']['fix_label'])->toBe('Add a TXT record at _dmarc.shop.example:')
        ->and($none['dmarc']['fix'])->toBe('v=DMARC1; p=reject')
        ->and($none['dmarc']['tip'])->toBe('Its SPF record allows no sender, so reject everything that claims to come from it. Reports are optional.');

    // A mail server means people there send, whatever SPF says: reports first, never reject at once
    expect($items(['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 -all']])['dmarc']['fix'])->toBe('v=DMARC1; p=none; rua=mailto:<your report address>');

    // Reports already set up on a domain that may send: the safe way on, not reject on a guess
    $quiet = $items(['dmarc' => ['v=DMARC1; p=none; rua=mailto:reports@dmarc.example']]);
    expect($quiet['dmarc']['fix'])->toBe('v=DMARC1; p=quarantine; rua=mailto:reports@dmarc.example')
        ->and($quiet['dmarc']['tip'])->not->toContain('reject right away');

    // Two SPF records: SPF fails for all of its email today; the one to keep names every sender of both
    $twice = $items(['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 include:_spf.mail.example -all', 'v=spf1 include:send.newsletter.example ~all'], 'dmarc' => ['v=DMARC1; p=none; rua=mailto:reports@dmarc.example']]);
    expect($twice['spf']['note'])->toBe('Two SPF records: receivers treat that as an error, so SPF fails for all of this domain\'s email today.')
        ->and($twice['spf']['fix_label'])->toBe('Replace both with one TXT record at shop.example that names every sender in them:')
        ->and($twice['spf']['fix'])->toBe('v=spf1 include:_spf.mail.example include:send.newsletter.example ~all');
    // redirect= is ignored next to an all (RFC 7208 6.1), so it is named with include: instead; exp= could only be there once
    expect($items(['spf' => ['v=spf1 include:_spf.mail.example exp=explain.shop.example -all', 'v=spf1 redirect=_spf.other.example']])['spf']['fix'])->toBe('v=spf1 include:_spf.mail.example include:_spf.other.example ~all');
    // Even when the first of them allows no sender, the other one sends
    expect($items(['spf' => ['v=spf1 -all', 'v=spf1 include:_spf.mail.example ~all'], 'dmarc' => ['v=DMARC1; p=none; rua=mailto:r@dmarc.example']])['dmarc']['fix'])->not->toContain('p=reject');

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

test('dns_items: a DMARC record receivers do not apply as written is not in force, and its line never makes anything stricter', function () {
    $items = fn (array $records) => array_column(dns_items(evaluate_mail_dns('shop.example', $records + ['mx' => [], 'spf' => [], 'dmarc' => []])['details']), null, 'key');
    $sends = ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 include:_spf.mail.example ~all']];

    // Misspelt and no reports: as if there were no record, so a domain that sends starts at p=none with reports, whatever the record meant
    $misspelt = $items($sends + ['dmarc' => ['v=DMARC1; p=rejct; adkim=s']]);
    expect($misspelt['dmarc'])->toMatchArray([
        'level' => 'weak',
        'note' => 'p=rejct is not a policy (none, quarantine or reject), and there is no valid report address (rua), so receivers ignore the record: DMARC is not in force',
        'fix_label' => 'Change the TXT record at _dmarc.shop.example to:',
        'fix' => 'v=DMARC1; p=none; adkim=s; rua=mailto:<your report address>',
    ])->and($misspelt['dmarc']['tip'])->toStartWith('Start at p=none, whatever the record says: receivers ignore it today, so DMARC stops none of your email now, and p=quarantine or p=reject could stop some that fails. rua is optional');

    // A rua that is no address (mailto: or https:) asks for nothing: it is replaced, not kept next to a new one
    expect($items($sends + ['dmarc' => ['v=DMARC1; p=rejct; rua=dmarc@shop.example']])['dmarc']['fix'])->toBe('v=DMARC1; p=none; rua=mailto:<your report address>');

    // One that says it sends none rejects at once; one that may send gets the condition, and p= goes right after v=DMARC1
    expect($items(['spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=rejct']])['dmarc']['fix'])->toBe('v=DMARC1; p=reject');
    $unknown = $items(['dmarc' => ['v=DMARC1; sp=reject']]);
    expect($unknown['dmarc']['note'])->toBe('The record has no p= (none, quarantine or reject), and there is no valid report address (rua), so receivers ignore the record: DMARC is not in force')
        ->and($unknown['dmarc']['fix_label'])->toBe('Only if nothing sends as @shop.example, change the TXT record at _dmarc.shop.example to:')
        ->and($unknown['dmarc']['fix'])->toBe('v=DMARC1; p=reject; sp=reject')
        ->and($items(['spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1']])['dmarc']['fix'])->toBe('v=DMARC1; p=reject');

    // Tags receivers cannot read, or a tag twice: a record of its own, never one with two p=
    $semicolon = $items($sends + ['dmarc' => ['v=DMARC1 p=reject']])['dmarc'];
    expect($semicolon['note'])->toBe('The record\'s tags are not separated by semicolons, so receivers ignore the record: DMARC is not in force')
        ->and($semicolon['fix'])->toBe('v=DMARC1; p=none; rua=mailto:<your report address>')
        ->and($items($sends + ['dmarc' => ['v=DMARC1, p=reject']])['dmarc']['fix'])->toBe('v=DMARC1; p=none; rua=mailto:<your report address>')
        ->and($items($sends + ['dmarc' => ['v=DMARC1; p=reject; p=none']])['dmarc']['note'])->toBe('p= is there twice, so receivers ignore the record: DMARC is not in force');
    // An sp= that is no policy goes, and subdomains follow p=
    expect($items(['spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=reject; sp=rejct']])['dmarc']['fix'])->toBe('v=DMARC1; p=reject');

    // No valid p= but reports: receivers read it as p=none already, so p=none makes it valid without changing anything
    $monitors = $items($sends + ['dmarc' => ['v=DMARC1;rua=mailto:reports@dmarc.example']]);
    expect($monitors['dmarc'])->toMatchArray([
        'level' => 'weak',
        'note' => 'The record has no p= (none, quarantine or reject), so receivers treat the record as p=none: email that fails the check is still delivered',
        'fix_label' => 'Change the TXT record at _dmarc.shop.example to:',
        'fix' => 'v=DMARC1;p=none;rua=mailto:reports@dmarc.example',
    ])->and($monitors['dmarc']['tip'])->toStartWith('Receivers read the record as p=none already, so this changes nothing but makes it say what it does. Once the reports show');
    $quarntine = $items($sends + ['dmarc' => ['v=DMARC1; p=quarntine; rua=mailto:reports@dmarc.example']])['dmarc'];
    expect($quarntine['note'])->toBe('p=quarntine is not a policy (none, quarantine or reject), so receivers treat the record as p=none: email that fails the check is still delivered')
        ->and($quarntine['fix'])->toBe('v=DMARC1; p=none; rua=mailto:reports@dmarc.example')
        // Once valid, it is a record that only monitors, and the warning goes
        ->and(evaluate_mail_dns('shop.example', $sends + ['dmarc' => [$quarntine['fix']]])['status'])->toBe('ok');
    // One that may send keeps to p=none and hears what nothing sending would allow; one that sends none rejects
    $unknownMonitors = $items(['dmarc' => ['v=DMARC1; rua=mailto:reports@dmarc.example']])['dmarc'];
    expect($unknownMonitors['fix'])->toBe('v=DMARC1; p=none; rua=mailto:reports@dmarc.example')
        ->and($unknownMonitors['fix_label'])->toBe('Change the TXT record at _dmarc.shop.example to:')
        ->and($unknownMonitors['tip'])->toEndWith('If nothing sends email as @shop.example, p=reject instead.')
        ->and($items(['spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; rua=mailto:reports@dmarc.example']])['dmarc']['fix'])->toBe('v=DMARC1; p=reject; rua=mailto:reports@dmarc.example');

    // Two records: receivers ignore both. One replaces them, built from the one that asks for reports, so its address and options stay
    $two = $items($sends + ['dmarc' => ['v=DMARC1; p=none; rua=mailto:reports@dmarc.example; ruf=mailto:f@dmarc.example', 'v=DMARC1; p=reject']]);
    expect($two['dmarc'])->toMatchArray([
        'level' => 'weak',
        'note' => 'Two DMARC records: receivers ignore both, so DMARC is not in force',
        'fix_label' => 'Replace both with one TXT record at _dmarc.shop.example:',
        'fix' => 'v=DMARC1; p=none; rua=mailto:reports@dmarc.example; ruf=mailto:f@dmarc.example',
    ])->and($two['dmarc']['tip'])->toStartWith('Start at p=none, whatever the records say: receivers ignore both today');
    expect($items(['spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=none', 'v=DMARC1; p=reject']])['dmarc']['fix'])->toBe('v=DMARC1; p=reject')
        // A p=reject among them was never in force, so a domain that may send gets the condition
        ->and($items(['dmarc' => ['v=DMARC1; p=none', 'v=DMARC1; p=reject']])['dmarc']['fix_label'])->toBe('Only if nothing sends as @shop.example, replace both with one TXT record at _dmarc.shop.example:')
        ->and($items($sends + ['dmarc' => ['v=DMARC1; p=none', 'v=DMARC1; p=quarantine', 'v=DMARC1; p=reject']])['dmarc']['note'])->toBe('Three DMARC records: receivers ignore all of them, so DMARC is not in force');
});

test('dns_items: with two SPF records, DMARC waits for SPF before it gets stricter', function () {
    $items = array_column(dns_items(evaluate_mail_dns('shop.example', ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 include:_spf.mail.example -all', 'v=spf1 include:send.newsletter.example ~all'], 'dmarc' => ['v=DMARC1; p=none; rua=mailto:reports@dmarc.example']])['details']), null, 'key');
    expect([$items['dmarc']['fix_label'], $items['dmarc']['fix']])->toBe(['', ''])
        ->and($items['dmarc']['tip'])->toBe('Merge the two SPF records first: while SPF gives an error, email that DKIM does not sign fails DMARC too, and p=quarantine would send it to spam.');
});

// =====================================================================
// Changes, confirmed by a second look
// =====================================================================

test('a domain that sends no email leads with why it still needs SPF and DMARC, and the records that say so', function () {
    $advice = fn (array $records) => mail_advice(evaluate_mail_dns('shop.example', $records + ['mx' => [], 'spf' => [], 'dmarc' => []])['details']);

    // No mail server only suggests it sends nothing: the site may still send (a contact form), so it asks first
    expect($advice([]))->toBe('Does anything send email as @shop.example, such as a contact form or order email from the site? It has no mail server, so perhaps not. If nothing does, an SPF record of v=spf1 -all and a DMARC record of v=DMARC1; p=reject say that any email in its name is forged, and this warning goes away by itself. If something does, don\'t add these: they would stop its email. Start with v=DMARC1; p=none; rua=mailto:<your report address> and read the reports first.')
        // A null MX says the same as none
        ->and($advice(['mx' => ['0 .']]))->toStartWith('Does anything send email as @shop.example')
        ->and($advice(['spf' => ['v=spf1 -all']]))->toBe('This domain sends no email (its SPF record allows no sender), but anyone can still send email that claims to come from it. A DMARC record of v=DMARC1; p=reject says that none of it is real. Once it is there, this warning goes away by itself.')
        ->and($advice(['dmarc' => ['v=DMARC1; p=reject']]))->toBe('Does anything send email as @shop.example, such as a contact form or order email from the site? It has no mail server, so perhaps not. If nothing does, an SPF record of v=spf1 -all says that any email in its name is forged, and this warning goes away by itself. If something does, name it in SPF with ~all instead, such as v=spf1 include:<your mail provider> ~all.')
        // A domain that sends, or may, gets the advice it has today, and one with nothing to add none
        ->and($advice(['mx' => ['10 mx1.mail.example']]))->toBe(advice_for('no_spf_dmarc', 0))
        ->and($advice(['spf' => ['v=spf1 -all', 'v=spf1 include:_spf.mail.example -all']]))->toBe(advice_for('spf_twice', 0))
        ->and($advice(['spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=reject']]))->toBe('');

    // A DMARC record receivers do not apply is named with SPF, so the warning does go away once both are done
    expect($advice(['dmarc' => ['v=DMARC1; p=rejct']]))->toBe('Does anything send email as @shop.example, such as a contact form or order email from the site? It has no mail server, so perhaps not. If nothing does, an SPF record of v=spf1 -all and p=reject in its DMARC record say that any email in its name is forged, and this warning goes away by itself. If something does, don\'t add these: they would stop its email. Set p=none in its DMARC record instead, with rua=mailto:<your report address>, and read the reports first.')
        ->and($advice(['dmarc' => ['v=DMARC1; rua=mailto:r@dmarc.example']]))->toEndWith('Set p=none in its DMARC record instead, and read the reports it asks for.')
        ->and($advice(['dmarc' => ['v=DMARC1; p=none', 'v=DMARC1; p=reject']]))->toContain('If nothing does, an SPF record of v=spf1 -all and one DMARC record of v=DMARC1; p=reject in place of the two say that')
        ->and($advice(['dmarc' => ['v=DMARC1; p=none', 'v=DMARC1; p=reject']]))->toEndWith('Start with one DMARC record of v=DMARC1; p=none; rua=mailto:<your report address> in place of the two, and read the reports first.');

    // SPF in place: the lead says what to do with the DMARC record, and why not simply the policy it meant
    $sends = ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 include:_spf.mail.example ~all']];
    expect($advice($sends + ['dmarc' => ['v=DMARC1; p=rejct']]))->toBe('Receivers ignore this DMARC record today (p=rejct is not a policy), so DMARC is not in force. Change it to p=none with reports, whatever it says: a stricter policy was never applied, and could stop some of your own email.')
        ->and($advice($sends + ['dmarc' => ['v=DMARC1; p=none; rua=mailto:r@dmarc.example', 'v=DMARC1; p=reject']]))->toBe('Receivers apply neither of the two DMARC records today, so DMARC is not in force. Replace them with one at p=none with reports, whatever they say: a stricter policy was never applied, and could stop some of your own email.')
        ->and($advice(['spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=rejct']]))->toBe('Receivers ignore this DMARC record today (p=rejct is not a policy), so DMARC is not in force. This domain sends no email (its SPF record allows no sender), so change it to p=reject.')
        ->and($advice(['spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; p=none', 'v=DMARC1; p=none', 'v=DMARC1; p=reject']]))->toStartWith('Receivers apply none of the three DMARC records today')
        ->and($advice($sends + ['dmarc' => ['v=DMARC1; rua=mailto:r@dmarc.example']]))->toBe('Receivers read this DMARC record as p=none today (no p=). Set p=none in it: nothing changes, but it says what it does, and from there it moves on as a record that only monitors.')
        ->and($advice(['spf' => ['v=spf1 -all'], 'dmarc' => ['v=DMARC1; rua=mailto:r@dmarc.example']]))->toBe('Receivers read this DMARC record as p=none today (no p=). This domain sends no email (its SPF record allows no sender), so change it to p=reject.');
});

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
