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

  // ---------------------------------------------------------------- page buttons under every list
  'option.common.pagination': message('Pages', 'Bogagga'),
  'option.common.pageNumber': message('Page {page}', 'Bogga {page}'),
  'option.common.goToPage': message('Go to page', 'U gudub bogga'),
  'action.common.go': message('Go', 'Tag'),

  // ---------------------------------------------------------------- search and filters (2026-10-29)
  'action.common.clearSearch': message('Clear search', 'Nadiifi raadinta'),
  'action.common.resetFilters': message('Reset filters', 'Nadiifi shaandhooyinka'),
  'action.common.resetFiltersCount': message('Reset filters ({count})', 'Nadiifi shaandhooyinka ({count})'),
  'option.common.resultsTotal': message('{count} records', '{count} diiwaan'),
  'option.common.resultsMatching': message('{count} matching records', '{count} diiwaan oo ku habboon'),
  'option.common.resultsFiltered': message('{count} of {total} match your search and filters', '{count} ka mid ah {total} ayaa ku habboon raadintaada iyo shaandhooyinkaaga'),
  'warning.common.noMatchTitle': message('No records match your search or filters', 'Diiwaan ku habboon raadintaada ama shaandhooyinkaaga ma jiro'),
  'warning.common.noMatchText': message('Change the search words, or reset the filters to see all records.', 'Beddel erayada raadinta, ama nadiifi shaandhooyinka si aad u aragto dhammaan diiwaannada.'),

  'option.company.searchCompaniesPlaceholder': message('Search companies and workplaces...', 'Ku raadi shirkadaha iyo goobaha shaqada...'),
  'option.company.allStatuses': message('All statuses', 'Dhammaan xaaladaha'),
  'option.company.workplacesAny': message('Workplaces: any', 'Goobaha shaqada: dhammaan'),
  'option.company.workplacesWith': message('With workplaces', 'Goobo shaqo leh'),
  'option.company.workplacesWithout': message('Without workplaces', "Goobo shaqo la'aan"),
  'option.company.filterByStatus': message('Filter by status', 'Ku shaandhee xaalad'),
  'option.company.filterByWorkplaces': message('Filter by workplaces', 'Ku shaandhee goobaha shaqada'),

  'option.payroll.searchLines': message('Search employee, number, company or workplace...', 'Ku raadi shaqaale, lambar, shirkad ama goob shaqo...'),
  'option.payroll.paymentAny': message('Payment: any', 'Bixinta: dhammaan'),
  'option.payroll.paymentPaid': message('Paid', 'La bixiyey'),
  'option.payroll.paymentNotPaid': message('Not paid yet', 'Weli lama bixin'),
  'warning.payroll.totalsWholeMonth': message('The totals above are for the whole month, not only the lines shown here.', 'Wadarta sare waa bishii oo dhan, ma aha kaliya khadadka halkan lagu tusay.'),

  'option.marketing.searchFollowUps': message('Search follow-ups: facility, company, person, phone, location, number...', 'Ku raadi la socodka: xarun, shirkad, qof, taleefan, goob, lambar...'),
  'option.marketing.searchMarketingStaff': message('Search by name, email or phone...', 'Ku raadi magac, iimayl ama taleefan...'),
  'option.marketing.filterByRole': message('Filter by role', 'Ku shaandhee door'),
  'option.marketing.filterByTeam': message('Filter by team', 'Ku shaandhee koox'),
  'option.marketing.allRoles': message('All roles', 'Dhammaan doorarka'),
  'option.marketing.allAccountStatuses': message('All accounts', 'Dhammaan akoonnada'),

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
