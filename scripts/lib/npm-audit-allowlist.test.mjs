// node --test scripts/lib/npm-audit-allowlist.test.mjs, in the node container like the gate's npm step.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import process from 'node:process';
import { after, test } from 'node:test';
import { URL, fileURLToPath } from 'node:url';

const FILTER = fileURLToPath(new URL('./npm-audit-allowlist.mjs', import.meta.url));
const FIXTURES = mkdtempSync(join(tmpdir(), 'npm-audit-allowlist-'));
const LISTED = 'GHSA-fc5q-39xc-vjpf';
const UNLISTED = 'GHSA-aaaa-bbbb-cccc';
const ALLOWLIST = `# a comment\n\nnpm ${LISTED} 2026-11-17 fixture reason\n`;

after(() => rmSync(FIXTURES, { recursive: true, force: true }));

function audit(vulnerabilities, total = Object.keys(vulnerabilities).length) {
    return JSON.stringify({ auditReportVersion: 2, vulnerabilities, metadata: { vulnerabilities: { total } } });
}

function report(...advisories) {
    const vulnerabilities = {};
    for (const [id, severity, name] of advisories) {
        vulnerabilities[name] = { via: [{ name, severity, url: `https://github.com/advisories/${id}` }] };
        vulnerabilities[`${name}-parent`] = { via: [name] };
    }
    return audit(vulnerabilities);
}

let fixtures = 0;
function filter(input, allowlist = ALLOWLIST) {
    fixtures += 1;
    const path = join(FIXTURES, `allowlist-${fixtures}.txt`);
    writeFileSync(path, allowlist);
    return spawnSync(process.execPath, [FILTER, path], { input, encoding: 'utf8' });
}

function refused(run, reason) {
    assert.equal(run.status, 2, run.stdout + run.stderr);
    assert.match(run.stderr, reason);
}

test('an unlisted high advisory fails the step and is named', () => {
    const run = filter(report([LISTED, 'high', 'picomatch'], [UNLISTED, 'high', 'left-pad']));
    assert.equal(run.status, 1);
    assert.match(run.stdout, new RegExp(`npm: ${UNLISTED} high left-pad — BLOCKING`));
    assert.match(run.stdout, /npm-audit: advisories=2 allowed=1 blocking=1/);
});

test('an unlisted critical or unrated advisory fails the step too', () => {
    assert.equal(filter(report([UNLISTED, 'critical', 'left-pad'])).status, 1);
    assert.equal(filter(report([UNLISTED, 'severe?', 'left-pad'])).status, 1);
});

test('the listed advisory passes, with its allowlist line printed', () => {
    const run = filter(report([LISTED, 'high', 'picomatch'], [UNLISTED, 'moderate', 'left-pad']));
    assert.equal(run.status, 0, run.stdout + run.stderr);
    assert.match(run.stdout, new RegExp(`npm: ${LISTED} high picomatch — allowed 2026-11-17: fixture reason`));
    assert.match(run.stdout, /npm-audit: advisories=2 allowed=1 blocking=0/);
    assert.doesNotMatch(run.stdout, /allowlist: /);
});

test('an entry whose advisory is gone is reported', () => {
    const run = filter(report());
    assert.equal(run.status, 0);
    assert.match(run.stdout, new RegExp(`allowlist: ${LISTED} no longer appears in the audit`));
});

test('output that is not an audit report is refused, never a pass', () => {
    refused(filter('{"message":"request to https://registry.npmjs.org failed"}'), /no audit report/);
    assert.equal(filter('').status, 2);
});

test('an allowlist line that is malformed or not npm is refused', () => {
    const input = report([UNLISTED, 'high', 'left-pad']);
    refused(filter(input, `npm ${UNLISTED}\n`), /line 1 of .* is not `npm <advisory id>/);
    refused(filter(input, `pip ${UNLISTED} 2026-11-17 reason\n`), /line 1 of .* names pip; this step reads npm entries only/);
});

test('an allowlist entry naming a severity, package or pattern instead of one id is refused', () => {
    const input = report([UNLISTED, 'high', 'left-pad']);
    refused(filter(input, 'npm high 2026-11-17 reason\n'), /allows \[high\], which is not one advisory id/);
    refused(filter(input, 'npm GHSA-* 2026-11-17 reason\n'), /allows \[GHSA-\*\], which is not one advisory id/);
});

test('an advisory whose url names no id is refused, never judged', () => {
    const input = audit({ 'left-pad': { via: [{ name: 'left-pad', severity: 'high', url: 'https://example.test/advisories/' }] } });
    refused(filter(input), /the advisory on left-pad names no id this step can judge/);
});

test('an entry without a via list is refused', () => {
    refused(filter(audit({ 'left-pad': { via: null } })), /the entry for left-pad carries no via list/);
});

test('a via string naming a package the report does not list is refused', () => {
    refused(filter(audit({ 'left-pad': { via: ['ghost'] } })), /the entry for left-pad points at ghost, which the report does not list/);
});

test('a report whose vulnerabilities is null is refused, never read as empty', () => {
    refused(filter(JSON.stringify({ auditReportVersion: 2, vulnerabilities: null, metadata: { vulnerabilities: { total: 0 } } })),
        /vulnerabilities is not an object/);
});

test('a report whose total is not an integer is refused', () => {
    const vulnerabilities = { picomatch: { via: [{ name: 'picomatch', severity: 'high', url: `https://github.com/advisories/${LISTED}` }] } };
    refused(filter(audit(vulnerabilities, '1')), /metadata\.vulnerabilities\.total is not a count: "1"/);
    refused(filter(JSON.stringify({ auditReportVersion: 2, vulnerabilities })), /total is not a count/);
});

test('a report that counts vulnerabilities but carries no advisory is refused', () => {
    refused(filter(audit({}, 3)), /npm counts 3 vulnerabilities and this step found no advisory to judge/);
});
