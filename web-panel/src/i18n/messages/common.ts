// Everyday words the HR screens share that the owner has not individually reviewed. They are written here once, from the approved vocabulary, so
// every screen uses the same Somali for the same word. All are `proposed`: not yet reviewed by the owner.
//
// Glossary used for proposed wording (consistent with the approved lists): Ku dar = add, Samee = create, Wax ka beddel = edit, Ka saar = remove,
// Diiwaangeli = register / record, Hubi = verify, Xaqiiji = confirm, Ansixi = approve, Sabab = reason, Shaqaale = employee, Shirkad = company,
// Goobta shaqada = workplace, Mushahar = salary, Lacag-bixin = payment, Taariikh = date / history, waajib = required, ikhtiyaari ah = optional.

import { defineMessages, message } from '../catalog.ts';

export const common = defineMessages({
  'action.close': message('Close', 'Xidh'),
  'action.edit': message('Edit', 'Wax ka beddel'),
  'action.download': message('Download', 'Soo deji'),
  'action.upload': message('Upload', 'Soo geli'),
  'action.open': message('Open', 'Fur'),
  'action.more': message('More', 'Wax dheeraad ah'),
  'action.history': message('History', 'Taariikh'),
  'action.create': message('Create', 'Samee'),
  'action.add': message('Add', 'Ku dar'),
  'action.remove': message('Remove', 'Ka saar'),
  'action.review': message('Review', 'Dib u eeg', 'The generic "Review" step button (for example in the Return dialog); the Replace dialog uses the approved action.reviewReplacement.'),
  'action.confirm': message('Confirm', 'Xaqiiji'),
  'action.record': message('Record', 'Diiwaangeli'),
  'option.yes': message('Yes', 'Haa'),
  'option.no': message('No', 'Maya'),

  // How long ago a date was ("Waiting 21 days"); the number is rounded, not exact
  'status.common.durationToday': message('Today', 'Maanta'),
  'status.common.durationDays.one': message('{n} day', '{n} maalin'),
  'status.common.durationDays.other': message('{n} days', '{n} maalmood'),
  'status.common.durationMonths.one': message('{n} month', '{n} bil'),
  'status.common.durationMonths.other': message('{n} months', '{n} bilood'),

  // Shared page furniture: table pager, loading and error states, the header search and the menu button
  'warning.common.showingRange': message('Showing {from}–{to} of {total}', 'Muujinaya {from}–{to} ee {total}'),
  'warning.common.pageOf': message('Page {page} of {pages}', 'Bogga {page} ee {pages}'),
  'warning.common.noAccessTitle': message("You don't have access to this", 'Marin uma haysatid tan'),
  'warning.common.noAccessText': message(
    "Your account doesn't have permission to view this page. Ask an administrator if you believe this is a mistake.",
    'Akoonkaagu ma haysto ogolaansho uu ku arko bogan. Weydiiso maamule haddii aad u malaynayso inay khalad tahay.',
  ),
  'notify.common.loadFailedGeneric': message('Something went wrong while loading this data.', 'Wax qalad ah ayaa dhacay markii xogtan la soo rarayay.'),
  'notify.common.couldNotLoadTitle': message("Couldn't load this data", 'Xogtan lama soo rari karin'),
  'action.common.tryAgain': message('Try again', 'Isku day mar kale'),
  'action.common.backToDashboard': message('Back to Dashboard', 'Ku noqo Dashboard'),
  'option.common.loadingRecords': message('Loading records…', 'Diiwaannada waa la soo rarayaa…'),
  'option.common.languageLabel': message('Language', 'Luqadda'),
  'option.common.languageTitle': message('Language: English or Somali', 'Luqadda: Ingiriis ama Soomaali'),
  'action.closeMenu': message('Close menu', 'Xidh liiska'),
  'action.openMenu': message('Open menu', 'Fur liiska'),
  'action.shell.fullscreen': message('Full screen', 'Shaashadda oo dhan'),
  'option.common.dataTable': message('Data table', 'Shaxda xogta'),
  'warning.common.searchNotWired': message('Search — not available yet', 'Raadinta — weli lama heli karo'),
  'option.common.searchAnything': message('Search anything…', 'Wax kasta raadi…'),
  'warning.common.notificationsNotWired': message('Notifications — not yet wired to a backend endpoint', 'Ogeysiisyada — weli lama xiriirin adeegga server-ka'),
  'warning.common.messagesNotWired': message('Messages — not yet wired to a backend endpoint', 'Fariimaha — weli lama xiriirin adeegga server-ka'),

  'option.common.perDay': message('{amount} per day', '{amount} maalintii'),

  // The date-range presets of the report filter bars
  'option.common.preset.today': message('Today', 'Maanta'),
  'option.common.preset.thisWeek': message('This Week', 'Toddobaadkan'),
  'option.common.preset.thisMonth': message('This Month', 'Bishan'),
  'option.common.preset.previousMonth': message('Previous Month', 'Bishii hore'),
  'option.common.preset.custom': message('Custom', 'Gaar ah'),

  // The shared "unsaved changes" dialog and the dialog close controls (Modal). English on every screen outside HR.
  'confirm.discard.title': message('Discard your changes?', 'Ma tuurayaa isbeddellada?'),
  'confirm.discard.text': message('You have unsaved changes. If you leave now they will be lost.', 'Isbeddello aan la keydin ayaad haysaa. Haddii aad hadda baxdo way lumi doonaan.'),
  'confirm.discard.keep': message('Keep editing', 'Sii wad wax-ka-beddelka'),
  'confirm.discard.discard': message('Discard changes', 'Tuur isbeddellada'),
  'action.closeDialog': message('Close dialog', 'Xidh daaqadda'),
  'action.closeLabel': message('Close', 'Xidh'),
});
