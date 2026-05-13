/**
 * Strict i18n loader for Playwright tests.
 *
 * Spawns PHP CLI to JSON-encode the language array from lang/{locale}.php
 * so we can do exact key→value comparisons against rendered HTML. Catches
 * typos, missing keys, fallback-leaks across all 3 supported locales.
 */
import { execSync } from 'child_process';
import * as path from 'path';

export type Locale = 'fi_FI' | 'en_GB' | 'sw_TZ';

export class I18n {
  private constructor(public readonly locale: Locale, private readonly map: Record<string, string>) {}

  static load(locale: Locale): I18n {
    const langFile = path.resolve(__dirname, '..', '..', '..', 'lang', `${locale}.php`).replace(/\\/g, '/');
    const code = `echo json_encode(require '${langFile}', JSON_UNESCAPED_UNICODE);`;
    const out = execSync(`php -r "${code}"`, { encoding: 'utf-8' });
    const parsed = JSON.parse(out) as Record<string, string>;
    return new I18n(locale, parsed);
  }

  t(key: string): string {
    const v = this.map[key];
    if (v === undefined) {
      throw new Error(`I18n key not found in ${this.locale}: ${key}`);
    }
    return v;
  }

  has(key: string): boolean {
    return key in this.map;
  }

  keys(): string[] {
    return Object.keys(this.map);
  }

  /** Returns keys present in this locale but missing from `other`. */
  diff(other: I18n): string[] {
    return this.keys().filter((k) => !other.has(k));
  }
}

/**
 * Quick sanity: scan the rendered HTML for any string that looks like an
 * un-translated i18n key (dotted lowercase, e.g. "backstage.governance.billing.kpi.pending").
 * False positives possible but rare; useful as a smoke check.
 */
export function findRawI18nKeysInHtml(html: string): string[] {
  // Match dot-separated lowercase ASCII identifiers that look like keys.
  // Trim the obvious file paths / e-mails first.
  const stripped = html
    .replace(/[a-z0-9_.-]+@[a-z0-9_.-]+/gi, '')
    .replace(/(?:src|href|action|data-[a-z-]+)=["'][^"']*["']/gi, '');
  const re = /(?:^|[\s>])((?:[a-z][a-z0-9_]+\.){2,}[a-z][a-z0-9_]+)(?=[\s<.,;!?]|$)/g;
  const found = new Set<string>();
  let m: RegExpExecArray | null;
  while ((m = re.exec(stripped)) !== null) {
    found.add(m[1]);
  }
  // Ignore obvious false positives (version strings, file extensions, etc.)
  return [...found].filter((k) => {
    if (/\.(php|js|css|html?|svg|png|jpg|json|sql|md|txt)$/i.test(k)) return false;
    if (/^\d/.test(k)) return false;
    return true;
  });
}
