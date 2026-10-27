// Messages for the customers area (Mobile App / Customers). Filled in during the Somali/English extension; every message is `proposed` (written for this
// implementation). Keys are `<category>.<area>.<name>`; the English text is exactly what the screen shows today.
//
// Reused from elsewhere: option.label.{phone,fullName,gender}, option.gender.{male,female}, option.shell.email, action.edit, action.cancel,
// action.saveChanges, and the nav.shell.{bookings,quotations,orders,payments} words for the summary card.

import { defineMessages, message } from '../catalog.ts';

export const customers = defineMessages({
  // Titles and breadcrumbs (also the sidebar item)
  'nav.customers.mobileApp': message('Mobile App', 'App-ka Mobaylka'),
  'nav.customers.customers': message('Customers', 'Macaamiisha'),

  // List
  'action.customers.add': message('Add Customer', 'Ku dar Macmiil'),
  'option.customers.searchPlaceholder': message('Search by name, phone, or email…', 'Ku raadi magac, taleefan, ama iimayl…'),
  'option.customers.allStatuses': message('All statuses', 'Dhammaan xaaladaha'),
  'warning.customers.noneFound': message('No customers found', 'Macmiil lama helin'),
  'warning.customers.adjustFilters': message('Try adjusting your search or filters.', 'Isku day inaad bedesho raadintaada ama shaandhooyinka.'),
  'option.customers.customer': message('Customer', 'Macmiil'),
  'option.customers.contact': message('Contact', 'Xiriir'),
  'option.customers.noEmail': message('No email', 'Iimayl ma jiro'),
  'option.customers.registered': message('Registered', 'La diiwaangeliyey'),

  // Customer status (the badge shows the stored value, the menus show it in sentence case)
  'option.customers.status.ACTIVE': message('Active', 'Firfircoon'),
  'option.customers.status.INACTIVE': message('Inactive', 'Aan firfircoonayn'),
  'option.customers.status.BLOCKED': message('Blocked', 'La xannibay'),
  'option.customers.status.DELETED': message('Deleted', 'La tirtiray'),

  // Classification (stored value)
  'status.customers.classification.lead': message('lead', 'Macmiil la filayo'),
  'status.customers.classification.active_customer': message('active_customer', 'Macmiil firfircoon'),

  // Detail
  'option.customers.loading': message('Loading customer…', 'Macmiilka waa la soo rarayaa…'),
  'action.customers.restore': message('Restore', 'Soo celi'),
  'action.customers.delete': message('Delete', 'Tirtir'),
  'option.customers.profile': message('Profile', 'Xogta macmiilka'),
  'option.customers.summary': message('Summary', 'Isku-soo-koob'),
  'option.customers.dateOfBirth': message('Date of birth', 'Taariikhda dhalashada'),
  'option.customers.preferredLanguage': message('Preferred language', 'Luqadda la door bidayo'),
  'option.customers.classification': message('Classification', 'Nooca macmiilka'),
  'option.customers.lastLogin': message('Last login', 'Galitaankii ugu dambeeyay'),
  'option.customers.changeStatus': message('Change status:', 'Bedel xaaladda:'),
  'option.customers.totalSpent': message('Total spent', 'Wadarta la bixiyey'),
  'notify.customers.statusUpdated': message('Customer status updated.', 'Xaaladda macmiilka waa la cusboonaysiiyey.'),
  'notify.customers.statusUpdateFailed': message('Could not update status.', 'Xaaladda lama cusboonaysiin karin.'),
  'notify.customers.deleted': message('Customer deleted.', 'Macmiilka waa la tirtiray.'),
  'notify.customers.deleteFailed': message('Could not delete customer.', 'Macmiilka lama tirtiri karin.'),
  'notify.customers.restored': message('Customer restored.', 'Macmiilka dib ayaa loo soo celiyey.'),
  'notify.customers.restoreFailed': message('Could not restore customer.', 'Macmiilka lama soo celin karin.'),
  'confirm.customers.deleteTitle': message('Delete customer', 'Tirtir macmiilka'),
  'confirm.customers.deleteBody': message(
    'This soft-deletes {name}. The record can be restored later.',
    'Tani waxay si ku-meel-gaar ah u tirtirtaa {name}. Diiwaanka dib ayaa loo soo celin karaa.',
  ),
  'confirm.customers.restoreTitle': message('Restore customer', 'Soo celi macmiilka'),
  'confirm.customers.restoreBody': message('Restore {name} to Active status.', 'U soo celi {name} xaaladda Firfircoon.'),

  // Add / edit form
  'action.customers.edit': message('Edit Customer', 'Wax ka beddel Macmiilka'),
  'action.customers.create': message('Create Customer', 'Samee Macmiil'),
  'option.customers.temporaryPassword': message('Temporary password', 'Erayga sirta ah ee ku-meel-gaarka'),
  'option.customers.notSpecified': message('Not specified', 'Lama sheegin'),
  'option.customers.language.so': message('Somali', 'Soomaali'),
  'option.customers.language.en': message('English', 'Ingiriisi'),
  'option.customers.language.ar': message('Arabic', 'Carabi'),
  'notify.customers.created': message('Customer created.', 'Macmiilka waa la sameeyey.'),
  'notify.customers.updated': message('Customer updated.', 'Macmiilka waa la cusboonaysiiyey.'),
});
