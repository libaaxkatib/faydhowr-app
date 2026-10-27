// `npm run i18n:register` rewrites docs/HR_SOMALI_TRANSLATION_REGISTER.md from the catalogue (see registerBuild.ts).

import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { buildRegister } from './registerBuild.ts';

const target = fileURLToPath(new URL('../../../docs/HR_SOMALI_TRANSLATION_REGISTER.md', import.meta.url));
writeFileSync(target, `${buildRegister()}\n`);
console.log(`register written: ${target}`);
