// Messages of the Show / Hide password control (2026-11-02). Both are `proposed` (written for this feature, not reviewed by a native speaker); Super Admin can
// reword them in Translations. None of the 161 owner-approved messages is touched. The Somali word for "password" is the one the approved messages already use.

import { defineMessages, message } from '../catalog.ts';

export const passwordVisibility = defineMessages({
  'action.shell.showPassword': message('Show password', 'Muuji erayga sirta ah'),
  'action.shell.hidePassword': message('Hide password', 'Qari erayga sirta ah'),
});
