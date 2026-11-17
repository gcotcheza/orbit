// usage: npm audit --audit-level=high --json | node scripts/lib/npm-audit-allowlist.mjs <allowlist>
// Exit 1 on a high, critical or unrated advisory the allowlist does not name; exit 2 on input it cannot read.
import { readFileSync } from 'node:fs';
import process from 'node:process';

const ID = /^(GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}|CVE-\d{4}-\d{4,})$/;
const ENTRY = /^(\S+) +(\S+) +(\d{4}-\d{2}-\d{2}) +(\S.*)$/;
const BELOW_HIGH = new Set(['moderate', 'low', 'info']);

function readAllowlist(text, path) {
    const allowed = new Map();
    text.split('\n').forEach((raw, index) => {
        const line = raw.trim();
        if (line === '' || line.startsWith('#')) return;
        const fields = ENTRY.exec(line);
        if (!fields) throw new Error(`line ${index + 1} of ${path} is not \`npm <advisory id> <YYYY-MM-DD> <reason>\`: ${line}`);
        if (fields[1] !== 'npm') throw new Error(`line ${index + 1} of ${path} names ${fields[1]}; this step reads npm entries only`);
        if (!ID.test(fields[2])) {
            throw new Error(`line ${index + 1} of ${path} allows [${fields[2]}], which is not one advisory id; a severity, package or pattern is never listed`);
        }
        allowed.set(fields[2], { date: fields[3], reason: fields[4] });
    });
    return allowed;
}

function readAdvisories(report) {
    if (!report || typeof report !== 'object' || !('auditReportVersion' in report)) {
        throw new Error(`npm printed no audit report this step can read: ${JSON.stringify(report).slice(0, 400)}`);
    }
    const { vulnerabilities } = report;
    if (!vulnerabilities || typeof vulnerabilities !== 'object' || Array.isArray(vulnerabilities)) {
        throw new Error(`npm: the audit report's vulnerabilities is not an object: ${JSON.stringify(vulnerabilities)}`);
    }
    const counted = report.metadata?.vulnerabilities?.total;
    if (!Number.isInteger(counted)) {
        throw new Error(`npm: the audit report's metadata.vulnerabilities.total is not a count: ${JSON.stringify(counted)}`);
    }
    const advisories = new Map();
    for (const [name, entry] of Object.entries(vulnerabilities)) {
        if (!Array.isArray(entry?.via)) throw new Error(`npm: the entry for ${name} carries no via list`);
        for (const via of entry.via) {
            if (typeof via === 'string') {
                if (!Object.hasOwn(vulnerabilities, via)) throw new Error(`npm: the entry for ${name} points at ${via}, which the report does not list`);
                continue;
            }
            const id = String(via?.url ?? '').split('/').pop();
            if (!ID.test(id)) throw new Error(`npm: the advisory on ${name} names no id this step can judge (url [${via?.url}])`);
            const severity = typeof via.severity === 'string' ? via.severity.toLowerCase() : 'unrated';
            advisories.set(id, { id, severity, package: via.name ?? name });
        }
    }
    if (advisories.size === 0 && counted > 0) {
        throw new Error(`npm counts ${counted} vulnerabilities and this step found no advisory to judge`);
    }
    return [...advisories.values()].sort((a, b) => a.id.localeCompare(b.id));
}

function judge(advisories, allowed, path) {
    const lines = [];
    let blocking = 0;
    let allowedCount = 0;
    for (const advisory of advisories) {
        const head = `npm: ${advisory.id} ${advisory.severity} ${advisory.package}`;
        const entry = allowed.get(advisory.id);
        if (BELOW_HIGH.has(advisory.severity)) { lines.push(`${head} — reported, below high`); continue; }
        if (entry) { allowedCount += 1; lines.push(`${head} — allowed ${entry.date}: ${entry.reason}`); continue; }
        blocking += 1;
        lines.push(`${head} — BLOCKING, not in ${path}`);
    }
    const found = new Set(advisories.map((advisory) => advisory.id));
    for (const id of allowed.keys()) {
        if (!found.has(id)) lines.push(`allowlist: ${id} no longer appears in the audit — delete its entry in ${path}`);
    }
    lines.push(`npm-audit: advisories=${advisories.length} allowed=${allowedCount} blocking=${blocking}`);
    return { lines, blocking };
}

const [path] = process.argv.slice(2);
try {
    const allowed = readAllowlist(readFileSync(path, 'utf8'), path);
    let input = '';
    for await (const chunk of process.stdin) input += chunk;
    const { lines, blocking } = judge(readAdvisories(JSON.parse(input)), allowed, path);
    process.stdout.write(`${lines.join('\n')}\n`);
    process.exitCode = blocking > 0 ? 1 : 0;
} catch (refusal) {
    process.stderr.write(`npm-audit-allowlist: ${refusal.message}\n`);
    process.exitCode = 2;
}
