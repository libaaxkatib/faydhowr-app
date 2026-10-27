// Messages for the colour-theme switcher (Light / Dark / System). Every message is `proposed` (written for this implementation; not reviewed by the owner).
// The Somali is new wording and does not reuse or alter any of the 161 locked messages. The names of the three modes are kept short: they sit on the
// accessible name of an icon button, not in the visible layout.

import { defineMessages, message } from '../catalog.ts';

export const theme = defineMessages({
  'option.theme.group': message('Colour theme', 'Muuqaalka midabka'),
  'option.theme.light': message('Light theme', 'Muuqaalka iftiinka'),
  'option.theme.dark': message('Dark theme', 'Muuqaalka mugdiga'),
  'option.theme.system': message('System theme', 'Muuqaalka nidaamka'),
});
