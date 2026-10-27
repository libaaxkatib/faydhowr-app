import test from 'node:test';
import assert from 'node:assert/strict';

import {
  DEFAULT_LANGUAGE,
  LANGUAGES,
  LANGUAGE_OPTIONS,
  LANGUAGE_STORAGE_KEY,
  LANG_ATTRIBUTE,
  applyLanguageAttribute,
  createLanguageController,
  parseLanguage,
  readStoredLanguage,
  writeStoredLanguage,
  type Language,
  type LanguageStorageLike,
} from './language.ts';

/** In-memory stand-in for window.localStorage that records every key written. */
function memoryStorage(initial: Record<string, string> = {}): LanguageStorageLike & { data: Record<string, string>; writes: string[] } {
  const data = { ...initial };
  const writes: string[] = [];

  return {
    data,
    writes,
    getItem: (key) => (key in data ? data[key] : null),
    setItem: (key, value) => {
      writes.push(key);
      data[key] = value;
    },
  };
}

const brokenStorage: LanguageStorageLike = {
  getItem: () => {
    throw new Error('SecurityError');
  },
  setItem: () => {
    throw new Error('QuotaExceededError');
  },
};

// ------------------------------------------------------------------ the choices and their defaults

test('English is the default and the only other choice is Somali', () => {
  assert.equal(DEFAULT_LANGUAGE, 'en');
  assert.deepEqual([...LANGUAGES], ['en', 'so']);
  assert.deepEqual(LANGUAGE_OPTIONS.map((option) => option.value), ['en', 'so']);
  for (const language of LANGUAGES) assert.equal(parseLanguage(language), language);
  for (const bad of [null, undefined, '', 'EN', 'SO', 'so ', 'somali', 'fr', 0, 1, true, {}, [], ['so']]) assert.equal(parseLanguage(bad), 'en', String(bad));
});

test('the language key is its own and can never be the sign-in token key or the old theme key', () => {
  assert.equal(LANGUAGE_STORAGE_KEY, 'fayadhowr.ui.language');
  assert.notEqual(LANGUAGE_STORAGE_KEY, 'fayadhowr.ui.theme');
  assert.ok(!/token/i.test(LANGUAGE_STORAGE_KEY));
});

// ------------------------------------------------------------------ storage that may be missing, blocked or full

test('a stored choice is read back, and a missing, invalid or unreadable one gives English', () => {
  assert.equal(readStoredLanguage(memoryStorage({ [LANGUAGE_STORAGE_KEY]: 'so' })), 'so');
  assert.equal(readStoredLanguage(memoryStorage({ [LANGUAGE_STORAGE_KEY]: 'en' })), 'en');
  assert.equal(readStoredLanguage(memoryStorage()), 'en');
  assert.equal(readStoredLanguage(memoryStorage({ [LANGUAGE_STORAGE_KEY]: 'klingon' })), 'en');
  assert.equal(readStoredLanguage(brokenStorage), 'en');
  assert.equal(readStoredLanguage(null), 'en');
  assert.equal(readStoredLanguage(undefined), 'en');
});

test('saving reports whether it worked and never throws', () => {
  const storage = memoryStorage();
  assert.equal(writeStoredLanguage(storage, 'so'), true);
  assert.equal(storage.data[LANGUAGE_STORAGE_KEY], 'so');
  assert.equal(writeStoredLanguage(brokenStorage, 'so'), false);
  assert.equal(writeStoredLanguage(null, 'so'), false);
});

// ------------------------------------------------------------------ the controller

test('a first visit is English and nothing is written to storage', () => {
  const storage = memoryStorage();
  const controller = createLanguageController({ storage });

  assert.deepEqual(controller.getState(), { language: 'en' });
  assert.deepEqual(storage.data, {});
});

test('switching to Somali and back is saved and notifies once per real change', () => {
  const storage = memoryStorage();
  const controller = createLanguageController({ storage });
  let notified = 0;
  controller.subscribe(() => notified++);

  controller.setLanguage('so');
  assert.deepEqual(controller.getState(), { language: 'so' });
  assert.equal(storage.data[LANGUAGE_STORAGE_KEY], 'so');

  controller.setLanguage('so');
  assert.equal(notified, 1, 'choosing the same language again changes nothing');

  controller.setLanguage('en');
  assert.deepEqual(controller.getState(), { language: 'en' });
  assert.equal(storage.data[LANGUAGE_STORAGE_KEY], 'en');
  assert.equal(notified, 2);
});

test('the choice survives a refresh: a new controller on the same storage starts with it', () => {
  const storage = memoryStorage();
  createLanguageController({ storage }).setLanguage('so');

  assert.deepEqual(createLanguageController({ storage }).getState(), { language: 'so' });
});

test('switching the language never reads, writes or resets any other stored setting', () => {
  const OTHER_KEY = 'fayadhowr.admin.token';
  const storage = memoryStorage({ [OTHER_KEY]: 'abc' });
  const controller = createLanguageController({ storage });

  controller.setLanguage('so');
  controller.setLanguage('en');
  controller.setLanguage('so');
  controller.syncFromStorage();

  assert.equal(storage.data[OTHER_KEY], 'abc', 'the other setting is untouched');
  assert.deepEqual([...new Set(storage.writes)], [LANGUAGE_STORAGE_KEY], 'only the language key was ever written');
});

test('unreadable or unwritable storage never breaks the switch: the choice applies until the page is closed', () => {
  const controller = createLanguageController({ storage: brokenStorage });
  assert.deepEqual(controller.getState(), { language: 'en' });
  assert.doesNotThrow(() => controller.setLanguage('so'));
  assert.deepEqual(controller.getState(), { language: 'so' });

  const bare = createLanguageController({});
  assert.doesNotThrow(() => bare.setLanguage('so'));
  assert.deepEqual(bare.getState(), { language: 'so' });
});

test('an invalid value passed to setLanguage becomes English and an unchanged choice keeps the same state object', () => {
  const controller = createLanguageController({ storage: memoryStorage() });
  const before = controller.getState();

  controller.setLanguage('klingon' as unknown as Language);
  assert.equal(controller.getState(), before, 'it was already English: React does not re-render');
});

test('a change made in another tab is picked up without being written back', () => {
  const storage = memoryStorage();
  const controller = createLanguageController({ storage });
  let notified = 0;
  controller.subscribe(() => notified++);

  storage.data[LANGUAGE_STORAGE_KEY] = 'so';
  controller.syncFromStorage();
  assert.deepEqual(controller.getState(), { language: 'so' });
  assert.equal(notified, 1);

  delete storage.data[LANGUAGE_STORAGE_KEY];
  controller.syncFromStorage();
  assert.equal(controller.getState().language, 'en');
  assert.deepEqual(storage.writes, [], 'syncing never writes');
});

test('unsubscribing and disposing stop all notifications', () => {
  const controller = createLanguageController({ storage: memoryStorage() });
  let notified = 0;
  const stop = controller.subscribe(() => notified++);

  stop();
  controller.setLanguage('so');
  assert.equal(notified, 0);

  controller.subscribe(() => notified++);
  controller.dispose();
  controller.setLanguage('en');
  assert.equal(notified, 0);
});

// ------------------------------------------------------------------ <html lang>

test('the page language attribute is set to the given language', () => {
  const attributes: Record<string, string> = {};
  const root = { setAttribute: (name: string, value: string) => void (attributes[name] = value) };

  applyLanguageAttribute(root, 'so');
  assert.equal(attributes[LANG_ATTRIBUTE], 'so');
  applyLanguageAttribute(root, 'en');
  assert.equal(attributes[LANG_ATTRIBUTE], 'en');
  assert.doesNotThrow(() => applyLanguageAttribute(null, 'so'));
});
