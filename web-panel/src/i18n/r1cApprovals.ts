import { readFileSync } from 'node:fs';

// Which status a message of the 1A / 1B / 1C areas must have after the owner's approval of 2026-11-05: exactly the 86 keys of
// `baseline/approved-r1c-86.json` are `approved`, every other message of those areas is still a `proposed` one. Tests use this instead of hard-coding
// "proposed", so each assertion stays exact (it never accepts either status). The list is read from the committed, hash-pinned baseline.

const baseline = JSON.parse(readFileSync(new URL('./baseline/approved-r1c-86.json', import.meta.url), 'utf8')) as { messages: { key: string }[] };
const APPROVED = new Set(baseline.messages.map((m) => m.key));

/** `approved` for the 86 owner-approved R1C messages, `proposed` for everything else. */
export function r1cReviewOf(key: string): 'approved' | 'proposed' {
  return APPROVED.has(key) ? 'approved' : 'proposed';
}
