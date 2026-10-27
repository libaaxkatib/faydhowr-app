// Finds user-visible English text that does not go through the translation catalogue. Pure (no React, no `@/` imports): it parses source text with the
// TypeScript compiler that is already a dev dependency, so no package is added. Used by the coverage test and by `npm run i18n:scan`.
//
// What counts as "user-visible text": JSX text; string values of text-bearing JSX props (label, title, placeholder, aria-label, hint, ...); string
// values of text-bearing object properties (label, title, message, description, ...); string arguments of toast/alert/friendlyErrorMessage calls; and,
// as a catch-all, any string literal that reads like an English sentence (two or more words, starting with a capital letter). Anything legitimately not
// translated (proper names, technical terms) is listed with a reason in `untranslated-allowlist.json`.

import ts from 'typescript';

export interface Finding {
  file: string;
  line: number;
  kind: 'jsx-text' | 'jsx-prop' | 'object-prop' | 'call-arg' | 'sentence';
  text: string;
}

const TEXT_PROPS = new Set([
  'label', 'title', 'placeholder', 'aria-label', 'aria-description', 'alt', 'hint', 'description', 'emptyTitle', 'emptyMessage', 'subtitle', 'heading',
  'helpText', 'confirmLabel', 'cancelLabel', 'tooltip', 'message', 'error', 'header', 'text', 'body', 'caption', 'legend', 'summary',
]);
const CALLEES = new Set(['show', 'toast', 'alert', 'confirm', 'friendlyErrorMessage', 'setError', 'fail']);

/** Looks like words a person would read, not a code, class list, path, identifier or enum value. */
export function looksLikeText(value: string): boolean {
  const text = value.replace(/&[a-z]+;|&#\d+;/g, ' ').trim();
  if (!/[A-Za-z]{2,}/.test(text)) return false;
  if (/^[a-z0-9_.\-/:#@%[\]{}$()*+<>=!?,;|\\ ]+$/.test(text) && !/ [a-z]+ [a-z]+/.test(text)) return false; // lowercase code-like: classes, paths, ids
  if (/^[A-Z0-9_]{3,}$/.test(text)) return false; // CONSTANT_CODES
  if (/^(https?:|\/|\.\/|\.\.\/|@\/)/.test(text)) return false;

  return true;
}

const SENTENCE = /^[A-Z][A-Za-z]*(?:[ ,'’&/-]+[A-Za-z0-9.,'’&:;!?()—…/-]+)+$/;

function literalText(node: ts.Node): string | null {
  if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) return node.text;
  if (ts.isTemplateExpression(node)) return [node.head.text, ...node.templateSpans.map((span) => `\u0000${span.literal.text}`)].join('');

  return null;
}

function stringsIn(node: ts.Node): ts.Node[] {
  if (ts.isConditionalExpression(node)) return [...stringsIn(node.whenTrue), ...stringsIn(node.whenFalse)];
  if (ts.isParenthesizedExpression(node)) return stringsIn(node.expression);
  if (ts.isBinaryExpression(node) && (node.operatorToken.kind === ts.SyntaxKind.BarBarToken || node.operatorToken.kind === ts.SyntaxKind.QuestionQuestionToken)) {
    return [...stringsIn(node.left), ...stringsIn(node.right)];
  }
  if (literalText(node) !== null) return [node];

  return [];
}

function propName(name: ts.PropertyName | ts.JsxAttributeName): string {
  return ts.isIdentifier(name) || ts.isStringLiteral(name) ? name.text : ts.isJsxNamespacedName(name) ? name.getText() : '';
}

export function scanSource(file: string, source: string): Finding[] {
  const sf = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true, file.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
  const findings: Finding[] = [];
  const seen = new Set<string>();

  function add(node: ts.Node, kind: Finding['kind'], text: string) {
    const clean = text.split('\u0000').join('${}').replace(/\s+/g, ' ').trim();
    if (!looksLikeText(clean)) return;
    const line = sf.getLineAndCharacterOfPosition(node.getStart(sf)).line + 1;
    const key = `${line}|${clean}`;
    if (seen.has(key)) return;
    seen.add(key);
    findings.push({ file, line, kind, text: clean });
  }

  function visit(node: ts.Node) {
    if (ts.isImportDeclaration(node) || ts.isExportDeclaration(node) || ts.isTypeNode(node)) return;

    if (ts.isJsxText(node)) {
      add(node, 'jsx-text', node.text);
    } else if (ts.isJsxAttribute(node) && node.initializer) {
      const name = propName(node.name);
      if (TEXT_PROPS.has(name)) {
        const target = ts.isJsxExpression(node.initializer) ? node.initializer.expression : node.initializer;
        if (target) for (const s of stringsIn(target)) add(s, 'jsx-prop', literalText(s) ?? '');
      }
    } else if (ts.isJsxExpression(node) && node.expression && ts.isJsxElement(node.parent)) {
      // {'text'} or {cond ? 'a' : 'b'} used as a child
      for (const s of stringsIn(node.expression)) add(s, 'jsx-text', literalText(s) ?? '');
    } else if (ts.isPropertyAssignment(node) && TEXT_PROPS.has(propName(node.name))) {
      for (const s of stringsIn(node.initializer)) add(s, 'object-prop', literalText(s) ?? '');
    } else if (ts.isCallExpression(node)) {
      const callee = ts.isIdentifier(node.expression) ? node.expression.text : ts.isPropertyAccessExpression(node.expression) ? node.expression.name.text : '';
      if (CALLEES.has(callee)) for (const arg of node.arguments) for (const s of stringsIn(arg)) add(s, 'call-arg', literalText(s) ?? '');
    }

    const text = (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) ? node.text : null;
    if (text !== null && SENTENCE.test(text) && text.includes(' ')) {
      const parent = node.parent;
      const comparison = ts.isBinaryExpression(parent) && [ts.SyntaxKind.EqualsEqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsEqualsToken, ts.SyntaxKind.EqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsToken].includes(parent.operatorToken.kind);
      const isKey = ts.isPropertyAssignment(parent) && parent.name === node;
      const aCaseOrImport = ts.isCaseClause(parent) || ts.isImportDeclaration(parent) || ts.isLiteralTypeNode(parent) || ts.isElementAccessExpression(parent);
      const aClassList = ts.isJsxAttribute(parent) && ['className', 'id', 'htmlFor', 'type', 'name', 'data-testid'].includes(propName(parent.name));
      if (!comparison && !isKey && !aCaseOrImport && !aClassList) add(node, 'sentence', text);
    }

    ts.forEachChild(node, visit);
  }

  visit(sf);

  return findings;
}

export interface AllowEntry {
  file: string;
  text: string;
  reason: string;
}

/** Findings that are not in the allowlist. An allowlist entry matches by file and by exact text (or by `*` for a whole file). */
export function unallowed(findings: Finding[], allow: AllowEntry[]): Finding[] {
  return findings.filter((f) => !allow.some((a) => a.file === f.file && (a.text === '*' || a.text === f.text)));
}
