import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';

import { localizeFieldErrors, localizeServerMessage } from './serverMessages.ts';
import { CATALOGUE } from './messages/index.ts';

const so = (text: string) => localizeServerMessage('so', text);
const en = (text: string) => localizeServerMessage('en', text);

const BACKEND = new URL('../../../backend/', import.meta.url);
const LARAVEL_VALIDATION = new URL('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php', BACKEND);

// ------------------------------------------------------------------ Somali: representative sentences, exact text

test('common Laravel validation sentences are shown in Somali, with the field named in Somali', () => {
  assert.equal(so('The full name field is required.'), 'Magaca oo dhan waa loo baahan yahay.');
  assert.equal(so('The phone field is required.'), 'Taleefan waa loo baahan yahay.');
  assert.equal(so('The email field must be a valid email address.'), 'Iimayl waa inuu noqdaa ciwaan iimayl oo sax ah.');
  assert.equal(so('The selected employee category id is invalid.'), 'Qaybta shaqaalaha la doortay ma saxna.');
  assert.equal(so('The phone has already been taken.'), 'Taleefan horey ayaa loo isticmaalay.');
  assert.equal(so('The password field confirmation does not match.'), 'Xaqiijinta Erayga sirta ah isma waafaqayso.');
  assert.equal(so('The start date field must be a valid date.'), 'Taariikhda bilowga waa inuu noqdaa taariikh sax ah.');
  assert.equal(so('The password is incorrect.'), 'Erayga sirta ah ma saxna.');
});

test('length, number and date limits keep their numbers and dates', () => {
  assert.equal(so('The notes field must not be greater than 1000 characters.'), 'Qoraallo waa inuusan ka badnaan 1000 xaraf.');
  assert.equal(so('The password field must be at least 8 characters.'), 'Erayga sirta ah waa inuu ahaadaa ugu yaraan 8 xaraf.');
  assert.equal(so('The amount field must be at least 0.01.'), 'Qadarka waa inuu ahaadaa ugu yaraan 0.01.');
  assert.equal(so('The amount field must not be greater than 99999.99.'), 'Qadarka waa inuusan ka weynaan 99999.99.');
  assert.equal(so('The age field must be between 16 and 80.'), 'Da’da waa inuu u dhexeeyaa 16 iyo 80.');
  assert.equal(so('The end date field must be a date after or equal to 2026-10-27.'), 'Taariikhda dhammaadka waa inuu noqdaa taariikh ka dambeysa 2026-10-27 ama la mid ah.');
  assert.equal(so('The end date field must be a date after start date.'), 'Taariikhda dhammaadka waa inuu noqdaa taariikh ka dambeysa start date.');
  assert.equal(so('The file field must not be greater than 5120 kilobytes.'), 'Faylka waa inuusan ka weynaan 5120 kilobayt.');
  assert.equal(so('The file field must be a file of type: pdf, jpg, png.'), 'Faylka waa inuu noqdaa fayl nooca: pdf, jpg, png.');
  assert.equal(so('The phone field must be 12 digits.'), 'Taleefan waa inuu ahaadaa 12 lambar.');
  assert.equal(so('The permissions field must have at least 1 items.'), 'permissions waa inuu lahaadaa ugu yaraan 1 shay.');
});

test('conditional rules carry the other field and its value', () => {
  assert.equal(so('The reason field is required when status is voided.'), 'Sabab waa loo baahan yahay marka status uu yahay voided.');
  assert.equal(so('The salary currency field is required when salary amount is present.'), 'Lacagta mushaharka waa loo baahan yahay marka salary amount la geliyo.');
});

test('a field without a Somali name keeps the name the server used; a nested key is never guessed', () => {
  assert.equal(so('The weird thing field is required.'), 'weird thing waa loo baahan yahay.');
  assert.equal(so('The complaints.0.details field is required.'), 'complaints.0.details waa loo baahan yahay.');
});

test('the "(and N more errors)" tail that Laravel adds is translated and counted', () => {
  assert.equal(so('The email field is required. (and 1 more error)'), 'Iimayl waa loo baahan yahay. (iyo 1 khalad oo kale)');
  assert.equal(so('The email field is required. (and 2 more errors)'), 'Iimayl waa loo baahan yahay. (iyo 2 khalado oo kale)');
});

test('a validation failure keeps its field keys and the number of messages per field', () => {
  const errors = { full_name: ['The full name field is required.'], phone: ['The phone field is required.', 'The phone has already been taken.'], odd: ['Something the catalogue does not know.'] };
  const localized = localizeFieldErrors('so', errors)!;
  assert.deepEqual(Object.keys(localized), ['full_name', 'phone', 'odd']);
  assert.deepEqual(
    localized.phone,
    ['Taleefan waa loo baahan yahay.', 'Taleefan horey ayaa loo isticmaalay.'],
  );
  assert.deepEqual(localized.odd, ['Something the catalogue does not know.']);
  assert.equal(localizeFieldErrors('so', undefined), undefined);
  assert.deepEqual(localizeFieldErrors('en', errors), errors, 'English is returned exactly as the server sent it');
});

// ------------------------------------------------------------------ English and unknown sentences are never touched

test('English mode returns the server sentence exactly as sent', () => {
  for (const text of ['The full name field is required.', 'Invalid email or password.', 'The notes field must not be greater than 1000 characters.', 'Anything at all, 3 times.']) {
    assert.equal(en(text), text);
  }
});

test('a sentence the catalogue does not know is returned unchanged, even in Somali', () => {
  const unknown = [
    'This employee (Amina Yusuf) already has an active assignment from 2026-10-01.', // a domain message with a name and a date
    'The salary of 300.00 USD cannot be changed after the run is finalized.',
    'The notes field must be uppercase.', // looks like a Laravel sentence, is not one of ours
    'The amount field must be 5 pieces.', // a "size" shape that is not one of ours: must not become "waa inuu ahaadaa 5"
    'The given data was invalid. Please retry.',
    '',
  ];
  for (const text of unknown) assert.equal(so(text), text);
});

test('names, dates and amounts inside a message that is not a validation sentence are never altered', () => {
  const text = 'Qa Employee One was paid 48.39 USD on 2026-10-27.';
  assert.equal(so(text), text);
});

// ------------------------------------------------------------------ Every rule in the catalogue works, generated from its own English sentence

const SAMPLE: Record<string, string> = { attribute: 'full name', other: 'the other field', value: '7', values: 'a, b', date: '2026-10-27', format: 'Y-m-d', min: '3', max: '9', size: '5', digits: '4', decimal: '2', n: '2' };

test('every validation and server rule: its English sentence becomes its own Somali sentence, with every value carried over', () => {
  const rules = Object.entries(CATALOGUE).filter(([key]) => (key.startsWith('validation.laravel.') || key.startsWith('notify.server.')) && !/\.(one|other)$/.test(key));
  assert.ok(rules.length > 70, `only ${rules.length} rules`);
  for (const [key, entry] of rules) {
    const sentence = entry.en.replace(/\{([a-z_]+)\}/g, (_, name: string) => SAMPLE[name] ?? name);
    const expected = entry.so.replace(/\{([a-z_]+)\}/g, (_, name: string) => (name === 'attribute' ? 'Magaca oo dhan' : (SAMPLE[name] ?? name)));
    assert.equal(so(sentence), expected, key);
    assert.equal(en(sentence), sentence, `${key}: English must not change`);
  }
});

test('the Somali sentence of a rule never loses a value that the English sentence carries', () => {
  for (const [key, entry] of Object.entries(CATALOGUE)) {
    if (!key.startsWith('validation.laravel.') && !key.startsWith('notify.server.')) continue;
    const names = (text: string) => [...text.matchAll(/\{([a-z_]+)\}/g)].map((m) => m[1]).sort();
    assert.deepEqual(names(entry.so), names(entry.en), key);
  }
});

// ------------------------------------------------------------------ The catalogue still matches the real sources

test('the English sentences are exactly the ones Laravel produces (compared with the installed framework)', { skip: !existsSync(LARAVEL_VALIDATION) && 'backend/vendor is not installed' }, () => {
  const php = readFileSync(LARAVEL_VALIDATION, 'utf8');
  const laravelSentences = new Set([...php.matchAll(/=>\s*'((?:[^'\\]|\\.)*)'/g)].map((m) => m[1].replace(/\\'/g, "'")));
  const missing: string[] = [];
  for (const [key, entry] of Object.entries(CATALOGUE)) {
    if (!key.startsWith('validation.laravel.') || /\.(one|other)$/.test(key)) continue;
    const template = entry.en.replace(/\{([a-z_]+)\}/g, ':$1');
    if (!laravelSentences.has(template)) missing.push(`${key}: ${template}`);
  }
  assert.deepEqual(missing, []);
});

test('the backend sentences the panel recognises are still in the backend source, word for word', () => {
  const read = (path: string) => readFileSync(new URL(path, BACKEND), 'utf8');
  const sources = [
    read('app/Http/Controllers/Api/V1/Admin/Auth/AdminAuthController.php'),
    read('app/Http/Middleware/EnsureAdminAuthentication.php'),
    read('app/Http/Middleware/EnsureAdminPermission.php'),
    read('app/Http/Middleware/EnsureSuperAdmin.php'),
    read('app/Http/Controllers/Api/V1/Admin/AdminController.php'),
    read('app/Http/Requests/Api/V1/Admin/Hr/Facility/UpdateFacilityInspectionRequest.php'),
    read('app/Http/Requests/Api/V1/Admin/Accounting/StoreAccountingPeriodRequest.php'),
    read('app/Support/Hr/Payroll/PayrollSummaryPresenter.php'),
    read('app/Models/HrPolicy.php'),
  ].join('\n');
  const framework = existsSync(new URL('vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php', BACKEND))
    ? read('vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php') + read('vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php')
    : null;
  for (const [key, entry] of Object.entries(CATALOGUE)) {
    if (!key.startsWith('notify.server.')) continue;
    const found = sources.includes(`'${entry.en}'`) || (framework !== null && framework.includes(entry.en));
    if (framework === null && ['notify.server.tooManyAttempts', 'notify.server.serverError'].includes(key)) continue;
    assert.ok(found, `${key}: "${entry.en}" is not in the backend source`);
  }
});

test('every validation rule the backend requests use is either translated or deliberately has no message', () => {
  const requests = new URL('app/Http/Requests/', BACKEND);
  const files = (dir: URL): URL[] => readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? files(new URL(`${e.name}/`, dir)) : e.name.endsWith('.php') ? [new URL(e.name, dir)] : []));
  const used = new Set<string>();
  const vocabulary = 'accepted|after|after_or_equal|alpha_dash|array|before|before_or_equal|between|boolean|confirmed|current_password|date|date_format|decimal|different|digits|distinct|email|exists|file|filled|gt|gte|image|in|integer|lt|lte|max|mimes|mimetypes|min|not_in|numeric|prohibited|present|regex|required|required_if|required_unless|required_with|required_with_all|required_without|required_without_all|same|size|string|unique|url|uuid';
  const rule = new RegExp(`'(${vocabulary})(?::[^']*)?'`, 'g');
  for (const file of files(requests)) for (const match of readFileSync(file, 'utf8').matchAll(rule)) used.add(match[1]);

  const translated = new Set(
    Object.keys(CATALOGUE)
      .filter((key) => key.startsWith('validation.laravel.'))
      .map((key) => key.split('.')[2].replace(/([A-Z])/g, '_$1').toLowerCase()),
  );
  // Rules whose sentence is covered by another rule's entry: in / exists / not_in use "The selected X is invalid." (selected_invalid); the
  // password complexity sentences come from Password::min(8) rather than a rule word.
  const coveredBy: Record<string, string> = { in: 'selected_invalid', exists: 'selected_invalid', not_in: 'selected_invalid', mimetypes: 'mimes' };
  // Rules where only some variants are translated: the rest keep the server's sentence (safe).
  const partial = new Set(['gt', 'gte', 'lt', 'lte']);
  const uncovered = [...used].filter((name) => !translated.has(name) && !(coveredBy[name] && translated.has(coveredBy[name])) && !partial.has(name));
  assert.deepEqual(uncovered, [], 'a rule is used by the backend but has no Somali sentence: add it to messages/serverErrors.ts or list why it is not needed');
  assert.ok(used.size > 25, `only ${used.size} rules found`);
});

test('every new message is a proposal: none is marked owner-approved, and none reuses a review-list id', () => {
  for (const [key, entry] of Object.entries(CATALOGUE)) {
    if (!/^(validation\.laravel\.|validation\.field\.|notify\.server\.|notify\.auth\.)/.test(key)) continue;
    assert.equal(entry.review, 'proposed', key);
    assert.equal(entry.id, undefined, key);
  }
});
