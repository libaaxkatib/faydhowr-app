// Messages added by the 2026-10-29 usability audit: names for icon-only buttons (screen readers and hover) and the confirmations that delete
// buttons were missing. Every message is `proposed` (written for this implementation); Super Admin can reword any of them in Translations.

import { defineMessages, message } from '../catalog.ts';

export const uxAudit = defineMessages({
  // ---------------------------------------------------------------- names for icon-only buttons
  'action.company.editCompanyNamed': message('Edit company {name}', 'Wax ka beddel shirkadda {name}'),
  'action.company.deleteCompanyNamed': message('Delete company {name}', 'Tirtir shirkadda {name}'),
  'action.company.editLocationNamed': message('Edit work location {name}', 'Wax ka beddel goobta shaqada {name}'),
  'action.company.deleteLocationNamed': message('Delete work location {name}', 'Tirtir goobta shaqada {name}'),
  'action.company.editDepartmentNamed': message('Edit department {name}', 'Wax ka beddel waaxda {name}'),
  'action.company.editPositionNamed': message('Edit position {name}', 'Wax ka beddel jagada {name}'),
  'action.employees.openEmployeeNamed': message('Open {name}', 'Fur {name}'),
  'action.marketing.deleteTeamNamed': message('Delete team {name}', 'Tirtir kooxda {name}'),
  'action.marketing.removeMemberNamed': message('Remove {name} from the team', 'Ka saar {name} kooxda'),
  'action.training.removeParticipantNamed': message('Remove {name} from the batch', 'Ka saar {name} koox-tababarka'),
  'action.dataIssue.removeEmployeeNamed': message('Remove {name} from the list', 'Ka saar {name} liiska'),

  // ---------------------------------------------------------------- pages
  'warning.shell.pageNotFoundTitle': message('Page not found', 'Bogga lama helin'),
  'warning.shell.pageNotFoundText': message('Check the address, or use the menu to open a page.', 'Hubi cinwaanka, ama isticmaal liiska si aad u furto bog.'),

  // ---------------------------------------------------------------- confirmations
  'confirm.company.deleteCompanyTitle': message('Delete company?', 'Ma tirtirtaa shirkadda?'),
  'confirm.company.deleteCompanyBody': message('Delete "{name}"? This cannot be undone.', 'Ma tirtirtaa "{name}"? Taas dib looma celin karo.'),
  'confirm.company.deleteCompanyButton': message('Delete company', 'Tirtir shirkadda'),
  'confirm.company.deleteLocationTitle': message('Delete work location?', 'Ma tirtirtaa goobta shaqada?'),
  'confirm.company.deleteLocationBody': message('Delete "{name}"? This cannot be undone.', 'Ma tirtirtaa "{name}"? Taas dib looma celin karo.'),
  'confirm.company.deleteLocationButton': message('Delete work location', 'Tirtir goobta shaqada'),
  'confirm.marketing.deleteTeamTitle': message('Delete team?', 'Ma tirtirtaa kooxda?'),
  'confirm.marketing.deleteTeamBody': message('Delete "{name}"? This cannot be undone.', 'Ma tirtirtaa "{name}"? Taas dib looma celin karo.'),
  'confirm.marketing.deleteTeamButton': message('Delete team', 'Tirtir kooxda'),
});
