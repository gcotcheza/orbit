// Judges `npm audit --json` on stdin against an allowlist: high, critical and unrated exit 1 unless listed by id, and
// so does a listed id once its holder is offered a fix; input it cannot judge exits 2. decision: npm-audit-allowlist
import { readFileSync } from 'node:fs'
import process from 'node:process'

const ID = /^GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}$/
const BLOCKING = new Set(['critical', 'high', 'unrated'])
const RATED = new Set(['critical', 'high', 'moderate', 'low', 'info'])

function readAllowlist(text, path) {
    const allowed = new Map()
    text.split('\n').forEach((raw, index) => {
        const line = raw.trim()
        if (line === '' || line.startsWith('#')) return
        const where = `line ${index + 1} of ${path}`
        const fields = /^(\S+) +(\S+) +(\d{4}-\d{2}-\d{2}) +(\S.*)$/.exec(line)
        if (!fields) throw new Error(`${where} is not \`<ecosystem> <advisory id> <YYYY-MM-DD> <reason>\`: ${line}`)
        const [, ecosystem, id, date, reason] = fields
        if (ecosystem !== 'npm') throw new Error(`${where} names ${ecosystem}; this step audits npm only.`)
        if (!ID.test(id)) {
            throw new Error(`${where} allows [${id}], which is not one GHSA advisory id. npm names an advisory only by its GHSA id, `
                + 'and a pattern, a package name or a blanket entry would accept advisories nobody has read.')
        }
        if (allowed.has(id)) throw new Error(`${where} lists ${id} a second time.`)
        allowed.set(id, { date, reason })
    })
    return allowed
}

function readReport(json, auditExit) {
    let report
    try {
        report = JSON.parse(json)
    } catch (error) {
        throw new Error(`npm audit printed no JSON this step can read (${error.message}). It printed: ${json.slice(0, 400) || '<nothing>'}`, { cause: error })
    }
    if (report === null || typeof report !== 'object' || !report.auditReportVersion) {
        throw new Error(`npm audit (exit ${auditExit}) printed JSON that is not an audit report: ${json.slice(0, 400)}`)
    }
    if (report.auditReportVersion !== 2) {
        throw new Error(`the audit report's auditReportVersion is ${JSON.stringify(report.auditReportVersion)}; this step reads version 2 only.`)
    }
    if (typeof report.vulnerabilities !== 'object' || report.vulnerabilities === null) {
        throw new Error(`the audit report's vulnerabilities is not an object: ${JSON.stringify(report.vulnerabilities)}`)
    }
    if (Array.isArray(report.vulnerabilities)) throw new Error("the audit report's vulnerabilities is a list, not an object keyed by package.")
    if (!Number.isInteger(report.metadata?.vulnerabilities?.total)) {
        throw new Error(`the audit report's metadata.vulnerabilities.total is not a count: ${JSON.stringify(report.metadata?.vulnerabilities?.total)}`)
    }
    const examined = report.metadata.dependencies?.total
    if (!Number.isInteger(examined) || examined < 1) {
        throw new Error(`npm audit examined no package, so it judged nothing (metadata.dependencies.total is ${JSON.stringify(examined)}).`)
    }
    if (auditExit !== 0 && report.metadata.vulnerabilities.total === 0) {
        throw new Error(`npm audit exited ${auditExit} and reported no vulnerability: the audit errored, and judged nothing.`)
    }
    return report
}

function fixOffered(holder, fixAvailable) {
    if (fixAvailable === true) return [`npm audit fix, via ${holder}`]
    if (fixAvailable !== null && typeof fixAvailable === 'object' && fixAvailable.isSemVerMajor !== true) {
        return [`${fixAvailable.name}@${fixAvailable.version}`]
    }
    return []
}

function advisoriesIn(report) {
    const advisories = new Map()
    for (const [name, entry] of Object.entries(report.vulnerabilities)) {
        if (!Array.isArray(entry?.via)) throw new Error(`the entry for ${name} carries no via list, so its advisories cannot be read.`)
        for (const via of entry.via) {
            // A name here is an effect, whose fixAvailable npm starts at true: only the holder's fix counts.
            if (typeof via === 'string') {
                if (!Object.hasOwn(report.vulnerabilities, via)) throw new Error(`the entry for ${name} points at ${via}, which the report does not list.`)
                continue
            }
            const url = typeof via?.url === 'string' ? via.url : ''
            const id = url.slice(url.lastIndexOf('/') + 1)
            if (!ID.test(id)) throw new Error(`the advisory on ${name} names no id this step can judge (url [${url}]).`)
            const severity = RATED.has(via.severity) ? via.severity : 'unrated'
            const fixes = [...(advisories.get(id)?.fixes ?? []), ...fixOffered(name, entry.fixAvailable)]
            advisories.set(id, { id, severity, name: typeof via.name === 'string' ? via.name : name, fixes })
        }
    }
    if (advisories.size === 0 && report.metadata.vulnerabilities.total > 0) {
        throw new Error(`the report counts ${report.metadata.vulnerabilities.total} vulnerabilities and no advisory was found under them.`)
    }
    return [...advisories.values()]
}

function judge(json, auditExit, allowlistText, allowlistPath) {
    const allowed = readAllowlist(allowlistText, allowlistPath)
    const lines = []
    let blocking = 0
    for (const { id, severity, name, fixes } of advisoriesIn(readReport(json, auditExit))) {
        const entry = allowed.get(id)
        let verdict = BLOCKING.has(severity) ? `BLOCKING: no entry in ${allowlistPath}` : 'reported, below the high threshold'
        if (entry) verdict = `allowed ${entry.date}: ${entry.reason}`
        if (entry && fixes.length > 0) verdict = `BLOCKING: ${id} is allowlisted but npm now offers a fix (${fixes.join('; ')}): take it and delete its line`
        if (verdict.startsWith('BLOCKING')) blocking += 1
        lines.push(`npm: ${id} ${severity} ${name} - ${verdict}`)
        allowed.delete(id)
    }
    for (const id of allowed.keys()) lines.push(`npm: ${id} is listed in ${allowlistPath} and no longer reported; remove its line.`)
    lines.push(`npm-audit: blocking=${blocking}`)
    return { lines, blocking }
}

const [allowlistPath, exitArg] = process.argv.slice(2)
try {
    if (!allowlistPath || !/^[0-9]+$/.test(exitArg ?? '')) throw new Error('usage: npm-audit-allowlist.mjs <allowlist> <npm audit exit code> < report.json')
    const { lines, blocking } = judge(readFileSync(0, 'utf8'), Number(exitArg), readFileSync(allowlistPath, 'utf8'), allowlistPath)
    process.stdout.write(`${lines.join('\n')}\n`)
    process.exitCode = blocking === 0 ? 0 : 1
} catch (error) {
    process.stderr.write(`npm-audit-allowlist: ${error.message}\n`)
    process.exitCode = 2
}
