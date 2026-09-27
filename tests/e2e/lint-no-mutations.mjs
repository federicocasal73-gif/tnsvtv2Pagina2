#!/usr/bin/env node
/* eslint-disable */
/**
 * tests/e2e/lint-no-mutations.mjs
 *
 * Lint: e2e specs must NOT mutate state (Risk #9 in RISK_MITIGATION.md).
 *
 * Why this exists:
 *   E2E tests run against production (no staging env, see tests/e2e/README.md).
 *   A spec that accidentally calls `await request.delete(...)` or
 *   `page.click('button[data-delete]')` could wipe a real user's
 *   trade or account. This lint rejects PRs that introduce such
 *   patterns before the test ever runs.
 *
 * What it forbids (case-insensitive):
 *   - `request.post|put|delete|patch(` (Playwright APIRequestContext)
 *   - `.post(|.put(|.delete(|.patch(` on any fetch wrapper
 *   - Calls to known-mutating endpoint paths: /api/accounts POST/PATCH/DELETE,
 *     /api/journal POST/PUT/DELETE, /api/trades POST/PUT/DELETE,
 *     /api/admin/ POST/PUT/DELETE
 *   - Selector-based delete clicks: `[data-delete-id`, `[data-delete`,
 *     `button:has-text("Eliminar")`, `button:has-text("Delete")`
 *
 * Exit codes:
 *   0 → clean
 *   1 → one or more violations found
 *
 * Run: node tests/e2e/lint-no-mutations.mjs
 * CI:   invoked by `npm run lint:e2e` (see package.json)
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = process.cwd();
const E2E_DIR = join(ROOT, 'tests', 'e2e');

// Each rule is { id, label, pattern } — patterns are tested against
// the raw .ts file contents (no parsing, no AST). We accept false
// positives if a test really needs an exception — that's better
// than letting a real delete slip through.
//
// Note: rules are deliberately broad. A reviewer's job is to spot
// the false positive and add `/* lint-e2e-disable:rule-id */` near
// the offending line OR `/* lint-e2e-disable-file */` at the top of
// the file if the whole file is exempt.
const RULES = [
    {
        id: 'api-request-mutator',
        label: 'Playwright APIRequestContext mutator (post/put/delete/patch)',
        // Match: request.post / request.put / request.delete / request.patch
        // followed by an opening paren. Case-insensitive.
        pattern: /\brequest\.(post|put|delete|patch)\s*\(/i,
    },
    {
        id: 'fetch-mutator',
        label: 'Generic fetch/axios mutator (.post / .put / .delete / .patch)',
        // Match the dot-method form anywhere except for our
        // documented read-only helpers (apiFetch). apiFetch is
        // defined in tests/e2e/login.ts and is a GET-only helper.
        pattern: /\b(?!apiFetch\.[a-z]+\()(fetch|axios|api|apiFetch)\.(post|put|delete|patch)\s*\(/i,
    },
    {
        id: 'mutating-endpoint-path',
        label: 'Calls to mutating endpoint path (POST/PUT/DELETE/PATCH under /api/...)',
        // Method-verbed URLs like "/api/accounts" with POST/PUT/DELETE/PATCH
        // We only flag inside the test source — the fixture file
        // tests/e2e/login.ts defines read-only helpers and may
        // contain "POST" in comments, so we restrict to lines that
        // also include "/api/".
        pattern: /\.(post|put|delete|patch)\s*\(\s*['"`][^'"`]*\/api\/(accounts|journal|trades|diaries|admin)\b/i,
    },
    {
        id: 'delete-button-click',
        label: 'page.click on a delete selector',
        pattern: /\.click\s*\([^)]*(data-delete|data-delete-id|has-text\(['"`]?(Delete|Eliminar|Borrar))\b/i,
    },
    {
        id: 'mutation-helper-call',
        label: 'Direct call to a known-mutating helper (saveAccount, confirmDelete, etc.)',
        // These helpers exist on the account_switcher_controller
        // and would mutate state if invoked from e2e. We only
        // flag in test code (tests/e2e/*.spec.ts).
        pattern: /\.(saveAccount|confirmDelete|deleteTrade|createTrade|tradeForceDelete|userPurge)\s*\(/i,
    },
];

const SCAN_EXT = new Set(['.ts', '.js', '.mjs']);

/** @type {{file: string, line: number, snippet: string, ruleId: string, ruleLabel: string}[]} */
const violations = [];

function* walk(dir) {
    for (const entry of readdirSync(dir)) {
        const p = join(dir, entry);
        const s = statSync(p);
        if (s.isDirectory()) yield* walk(p);
        else if (SCAN_EXT.has(p.slice(p.lastIndexOf('.')))) yield p;
    }
}

let scanned = 0;
for (const file of walk(E2E_DIR)) {
    // Skip this lint script itself + the login fixture (defines the
    // apiFetch read-only helper, contains "POST" as part of the
    // implementation).
    const base = file.replace(/\\/g, '/');
    if (base.endsWith('/lint-no-mutations.mjs')) continue;

    const contents = readFileSync(file, 'utf8');
    scanned++;

    // File-level disable directive: /* lint-e2e-disable-file */
    if (/\/\*\s*lint-e2e-disable-file\s*\*\//.test(contents)) continue;

    const lines = contents.split(/\r?\n/);
    /** @type {Set<string>} */
    const disabledRules = new Set();
    lines.forEach((line, idx) => {
        const lineNo = idx + 1;
        // Per-line disable: /* lint-e2e-disable:rule-id[,rule-id2] */
        const m = line.match(/\/\*\s*lint-e2e-disable:([\w-]+(?:,[\w-]+)*)\s*\*\//);
        if (m) {
            for (const r of m[1].split(',')) disabledRules.add(r.trim());
            return;
        }
        // When a new /* lint-e2e-disable */ is encountered the
        // disabled rules apply to the FOLLOWING line(s). When the
        // line that introduces the disable is itself a no-op
        // (just the comment), we reset on the next non-comment
        // line — simplest is: clear the set after one line below.
        for (const rule of RULES) {
            if (disabledRules.has(rule.id)) continue;
            if (rule.pattern.test(line)) {
                violations.push({
                    file: base.replace(ROOT + '/', ''),
                    line: lineNo,
                    snippet: line.trim().slice(0, 120),
                    ruleId: rule.id,
                    ruleLabel: rule.label,
                });
            }
        }
        // Reset disabled set after processing each line.
        disabledRules.clear();
    });
}

if (violations.length === 0) {
    console.log(`[lint:e2e] OK — scanned ${scanned} file(s), no mutation patterns found.`);
    process.exit(0);
}

console.error(`[lint:e2e] FAIL — found ${violations.length} mutation pattern(s) in e2e specs:\n`);
for (const v of violations) {
    console.error(`  ${v.file}:${v.line}  [${v.ruleId}]  ${v.ruleLabel}`);
    console.error(`    > ${v.snippet}`);
}
console.error('');
console.error('To suppress a specific rule on a single line, add:');
console.error('    /* lint-e2e-disable:<rule-id> */');
console.error('To suppress the whole file, add at the top:');
console.error('    /* lint-e2e-disable-file */');
console.error('');
console.error('Reminder: e2e specs run against PRODUCTION. See RISK_MITIGATION.md § Risk #9.');
process.exit(1);