#!/bin/sh
# scripts/lib/npm-audit-allowlist.mjs against saved npm audit reports, no network. $1: the filter to test.
set -u

here=$(cd "$(dirname "$0")" && pwd -P)
filter=${1:-$here/../lib/npm-audit-allowlist.mjs}
fixtures=$here/fixtures/npm-audit
[ -r "$filter" ] || { printf 'npm-audit-allowlist: cannot read %s\n' "$filter" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
cp "$fixtures"/*.json "$work/" || exit 1
printf '%s\n' 'npm GHSA-fc5q-39xc-vjpf 2026-11-17 no fixed release' >"$work/listed"
printf '%s\n' 'npm GHSA-fc5q-39xc-vjpf 2026-11-17' >"$work/no-reason"
printf '%s\n' 'npm picomatch 2026-11-17 the whole package' >"$work/by-package"
printf '%s\n' 'npm GHSA-* 2026-11-17 everything' >"$work/blanket"
printf '%s\n' 'npm CVE-2026-12345 2026-11-17 npm never reports this id' >"$work/cve"
printf '%s\n' 'composer GHSA-fc5q-39xc-vjpf 2026-11-17 wrong ecosystem' >"$work/composer"
printf '%s\n' 'npm GHSA-fc5q-39xc-vjpf 2026-11-17 one' 'npm GHSA-fc5q-39xc-vjpf 2026-11-17 two' >"$work/twice"
printf '%s\n' 'not json at all' >"$work/garbage"
printf '%s\n' '{"auditReportVersion":2,"vulnerabilities":{"a":{"via":["b"]},"b":{"via":["a"]}},"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":2}}}' >"$work/no-advisory"
printf '%s\n' '{"auditReportVersion":2,"vulnerabilities":null,"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":0}}}' >"$work/vulnerabilities-null"
printf '%s\n' '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":"0"}}}' >"$work/total-text"
printf '%s\n' '{"auditReportVersion":2,"vulnerabilities":{"a":{"via":"b"},"b":{"via":[]}},"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":2}}}' >"$work/via-text"
printf '%s\n' '{"auditReportVersion":2,"vulnerabilities":{"a":{"via":[{"url":"https://example.test/x","severity":"high"}]}},"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":1}}}' >"$work/no-id"
printf '%s\n' '{"auditReportVersion":2,"vulnerabilities":{"a":{"severity":"high"}},"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":1}}}' >"$work/no-via"
printf '%s\n' '{"auditReportVersion":2,"vulnerabilities":{"a":{"via":[{"url":"https://github.com/advisories/GHSA-kkkk-mmmm-nnnn","severity":"severe"}]}},"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":1}}}' >"$work/unrated"
printf '%s\n' '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":0}}}' >"$work/clean"
printf '%s\n' '{"auditReportVersion":1,"vulnerabilities":{},"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":0}}}' >"$work/version-1"
printf '%s\n' '{"auditReportVersion":"2","vulnerabilities":{},"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":0}}}' >"$work/version-text"
printf '%s\n' '{"auditReportVersion":2,"vulnerabilities":[],"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":0}}}' >"$work/vulnerabilities-list"
printf '%s\n' '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{"dependencies":{"total":0},"vulnerabilities":{"total":0}}}' >"$work/examined-none"
printf '%s\n' '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{"vulnerabilities":{"total":0}}}' >"$work/examined-unsaid"
# $1 the picomatch entry's fixAvailable, $2 any entries after it, $3 its severity (high when absent).
holder() {
    printf '{"auditReportVersion":2,"vulnerabilities":{"picomatch":{"via":[{"name":"picomatch","url":"https://github.com/advisories/GHSA-fc5q-39xc-vjpf","severity":"%s"}],"fixAvailable":%s}%s},"metadata":{"dependencies":{"total":9},"vulnerabilities":{"total":2}}}\n' "${3:-high}" "$1" "$2"
}
holder true '' >"$work/holder-true"
holder '{"name":"knip","version":"9.9.9","isSemVerMajor":false}' '' >"$work/holder-minor"
holder '{"name":"knip","version":"9.9.9"}' '' >"$work/holder-unmarked"
holder '{"name":"knip","version":"3.13.2","isSemVerMajor":true}' '' >"$work/holder-major"
holder '{"name":"knip","version":"3.13.2","isSemVerMajor":true}' ',"vue-tsc":{"via":["picomatch"],"fixAvailable":true}' >"$work/effect-true"
holder '{"name":"knip","version":"3.13.2","isSemVerMajor":true}' ',"ghost":{"via":["nosuchpkg"],"fixAvailable":false}' >"$work/dangling-via"
holder true '' moderate >"$work/moderate-fix"
holder '{"name":"knip","version":"3.13.2","isSemVerMajor":true}' '' moderate >"$work/moderate-major"

ran=0 failed=0
# $1 case name, $2 wanted exit code, $3 text the output must hold, $4 report on stdin; the rest are the filter's arguments.
expect() {
    name=$1 want=$2 text=$3 report=$4
    shift 4
    out=$(node "$filter" "$@" <"$report" 2>&1)
    rc=$?
    ran=$((ran + 1))
    case $out in
        *"$text"*) said=1 ;;
        *) said=0 ;;
    esac
    if [ "$rc" -eq "$want" ] && [ "$said" -eq 1 ]; then
        printf 'ok   %s\n' "$name"
    else
        failed=$((failed + 1))
        printf 'FAIL %s: wanted exit %s and [%s], got exit %s:\n%s\n' "$name" "$want" "$text" "$rc" "$out"
    fi
}

expect 'an unlisted high fails beside a listed one' 1 'GHSA-aaaa-bbbb-cccc high invented-pkg - BLOCKING' "$work/unlisted-high.json" "$work/listed" 1
expect 'an unlisted critical fails' 1 'GHSA-dddd-eeee-ffff critical invented-pkg - BLOCKING' "$work/unlisted-critical.json" "$work/listed" 1
expect 'a severity npm does not rate fails' 1 'GHSA-kkkk-mmmm-nnnn unrated a - BLOCKING' "$work/unrated" "$work/listed" 1
expect 'a moderate is printed and passes' 0 'GHSA-gggg-hhhh-jjjj moderate invented-pkg - reported, below the high threshold' "$work/moderate.json" "$work/listed" 1
expect 'a listed id no longer reported is named' 0 'GHSA-fc5q-39xc-vjpf is listed' "$work/moderate.json" "$work/listed" 1
expect 'a clean report passes' 0 'npm-audit: blocking=0' "$work/clean" "$work/listed" 0
expect 'a line with no reason is refused' 2 'line 1 of' "$work/listed-high.json" "$work/no-reason" 1
expect 'a package name is refused as an id' 2 'not one GHSA advisory id' "$work/listed-high.json" "$work/by-package" 1
expect 'a blanket id is refused' 2 'not one GHSA advisory id' "$work/listed-high.json" "$work/blanket" 1
expect 'a CVE id is refused' 2 'not one GHSA advisory id' "$work/listed-high.json" "$work/cve" 1
expect 'an ecosystem this step does not audit is refused' 2 'audits npm only' "$work/listed-high.json" "$work/composer" 1
expect 'an id listed twice is refused' 2 'a second time' "$work/listed-high.json" "$work/twice" 1
expect 'an audit that errored is refused' 2 'not an audit report' "$work/errored.json" "$work/listed" 1
expect 'output that is not JSON is refused' 2 'no JSON this step can read' "$work/garbage" "$work/listed" 1
expect 'a failed audit with nothing reported is refused' 2 'the audit errored' "$work/clean" "$work/listed" 1
expect 'a counted vulnerability with no advisory under it is refused' 2 'no advisory was found' "$work/no-advisory" "$work/listed" 1
expect 'an advisory with no id is refused' 2 'names no id' "$work/no-id" "$work/listed" 1
expect 'an entry with no via list is refused' 2 'carries no via list' "$work/no-via" "$work/listed" 1
expect 'a via that is not a list is refused' 2 'the entry for a carries no via list' "$work/via-text" "$work/listed" 1
expect 'a via naming a package the report does not list is refused' 2 'the entry for ghost points at nosuchpkg, which the report does not list' "$work/dangling-via" "$work/listed" 1
expect 'a null vulnerabilities is refused' 2 "the audit report's vulnerabilities is not an object: null" "$work/vulnerabilities-null" "$work/listed" 0
expect 'a total that is not a count is refused' 2 'metadata.vulnerabilities.total is not a count: "0"' "$work/total-text" "$work/listed" 0
expect 'a report version other than 2 is refused' 2 "the audit report's auditReportVersion is 1; this step reads version 2 only" "$work/version-1" "$work/listed" 0
expect 'a report version written as text is refused' 2 "the audit report's auditReportVersion is \"2\"; this step reads version 2 only" "$work/version-text" "$work/listed" 0
expect 'a vulnerabilities list is refused' 2 "the audit report's vulnerabilities is a list, not an object keyed by package" "$work/vulnerabilities-list" "$work/listed" 0
expect 'an audit that examined no package is refused' 2 'npm audit examined no package, so it judged nothing (metadata.dependencies.total is 0)' "$work/examined-none" "$work/listed" 0
expect 'an audit that does not say what it examined is refused' 2 'npm audit examined no package, so it judged nothing (metadata.dependencies.total is undefined)' "$work/examined-unsaid" "$work/listed" 0
expect 'a missing allowlist argument is refused' 2 'usage:' "$work/clean"
expect 'an empty npm exit code is refused' 2 'usage:' "$work/clean" "$work/listed" ''
expect 'an npm exit code that is not a number is refused' 2 'usage:' "$work/clean" "$work/listed" x
expect 'a listed high whose holder npm can fix fails' 1 'GHSA-fc5q-39xc-vjpf is allowlisted but npm now offers a fix (npm audit fix, via picomatch): take it and delete its line' "$work/holder-true" "$work/listed" 1
expect 'a listed high with a non-major fix fails' 1 'npm now offers a fix (knip@9.9.9)' "$work/holder-minor" "$work/listed" 1
expect 'a listed high with an unmarked fix fails' 1 'npm now offers a fix (knip@9.9.9)' "$work/holder-unmarked" "$work/listed" 1
expect 'a listed high whose only fix is a major passes' 0 'GHSA-fc5q-39xc-vjpf high picomatch - allowed 2026-11-17' "$work/holder-major" "$work/listed" 1
expect 'a fix on an effect only is not a fix for the holder' 0 'npm-audit: blocking=0' "$work/effect-true" "$work/listed" 1
expect 'a listed moderate whose holder npm can fix fails' 1 'GHSA-fc5q-39xc-vjpf is allowlisted but npm now offers a fix (npm audit fix, via picomatch)' "$work/moderate-fix" "$work/listed" 1
expect 'a listed moderate with no fix is printed and passes' 0 'GHSA-fc5q-39xc-vjpf moderate picomatch - allowed 2026-11-17' "$work/moderate-major" "$work/listed" 1

printf '%s case(s), %s failed\n' "$ran" "$failed"
[ "$ran" -eq 37 ] || { printf 'expected 37 cases, ran %s\n' "$ran" >&2; exit 1; }
[ "$failed" -eq 0 ]
