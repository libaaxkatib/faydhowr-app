// Messages for the employee list, registration and edit form, move-stage, restore and status-override dialogs, office-staff registration, quick-create dialogs and their utils.
// Keys are `<category>.employees.<name>`; every entry holds its English text (exactly as the screen shows it today) and its Somali text.

import { defineMessages, message } from '../catalog.ts';

export const employees = defineMessages({
  // Employees list: filters, banners and buttons
  'action.employees.addOfficeStaff': message('Add Office Staff', 'Ku dar Shaqaalaha Xafiiska'),
  'action.employees.moreFilters': message('More filters', 'Shaandhooyin dheeraad ah'),
  'action.employees.clearFilter': message('Clear filter', 'Nadiifi shaandhada'),
  'action.employees.clearFilters': message('Clear {count} filters', 'Nadiifi {count} shaandhooyin'),
  'action.employees.showCurrent': message('Show current employees', 'Muuji shaqaalaha hadda jira'),
  'action.employees.showAll': message('Show all employees', 'Muuji dhammaan shaqaalaha'),
  'option.employees.archivedEmployees': message('Archived employees', 'Shaqaale la dhigay kaydka', 'Archived is rendered as "la dhigay kaydka" (put in the archive); the owner has not fixed a word for it yet.'),
  'option.employees.currentEmployees': message('Current employees', 'Shaqaalaha hadda jira'),
  'option.employees.allStatuses': message('All statuses', 'Dhammaan xaaladaha'),
  'option.employees.allCategories': message('All categories', 'Dhammaan qaybaha'),
  'option.employees.allDepartments': message('All departments', 'Dhammaan waaxaha'),
  'option.employees.categoryArchived': message('{name} (archived)', '{name} (la dhigay kaydka)'),
  'option.employees.profileAny': message('Profile: any', 'Faylka: mid kasta'),
  'option.employees.damiinAny': message('Damiin: any', 'Damiin: mid kasta'),
  'option.employees.supervisorAny': message('Supervisor: any', 'Kormeere: mid kasta'),
  'status.employees.archived': message('archived', 'la dhigay kaydka'),
  'status.employees.archivedRecord': message('archived record', 'diiwaan la dhigay kaydka'),
  'warning.employees.archivedBanner': message(
    'Showing {archived} employees: people removed from the working lists. You can open them and correct their details; their status, company assignment and history are not changed from here.',
    'Waxaa la muujinayaa shaqaale {archived}: dad laga saaray liisaska shaqada. Waad furi kartaa oo hagaajin kartaa faahfaahintooda; xaaladdooda, qoondaynta shirkadda iyo taariikhdooda halkan lagama beddelo.',
  ),
  'warning.employees.historicalRejectedBanner': message(
    "These employees were migrated as already cancelled/rejected from historical Excel records (CANCELED sheet / RED REGISTRATION rows). This list is separate from the live recruitment workflow's own {rejected} queue and never affects it.",
    'Shaqaalahan waxaa laga soo wareejiyey diiwaanno Excel oo hore, iyagoo horey loo joojiyey/loo diiday (xaashida CANCELED / safafka RED REGISTRATION). Liiskan wuu ka gaar yahay safka {rejected} ee habka qorista ee hadda socda, marnaba ma saameeyo.',
  ),
  'warning.employees.clientCompanyOnlyBanner': message(
    'Showing only employees with an active assignment to a client company.',
    'Waxaa la muujinayaa kaliya shaqaale leh qoondayn shaqeynaysa oo shirkad macmiil ah.',
  ),

  // Move to stage and status override dialogs
  'warning.employees.moveStageIntro': message(
    '{name} was registered before the registration workflow, so you can place them in the stage they need. Their guarantor, contract, uniform, training and practice records are kept as they are. Your name, the time, the old and new stage and your reason are recorded.',
    '{name} waa la diiwaangeliyey ka hor habka diiwaangelinta, sidaas darteed waxaad u dhigi kartaa marxaladda uu u baahan yahay. Diiwaannada Damiinka, Hishiiska, Uniformka, Tababarka iyo Baraatikada waa sidoodii loo ilaalinayaa. Magacaaga, waqtiga, marxaladdii hore iyo tan cusub iyo sababtaada waa la diiwaangeliyaa.',
  ),
  'notify.employees.stageMoved': message('Moved to the new stage. The reason was recorded.', 'Waxaa loo wareejiyey marxaladda cusub. Sababta waa la diiwaangeliyey.'),
  'notify.employees.stageMoveFailed': message('Could not move the employee.', 'Shaqaalaha lama wareejin karin.'),
  'validation.employees.reasonMin': message('Write a reason of at least {min} characters.', 'Qor sabab ugu yaraan {min} xaraf ah.'),
  'validation.employees.reasonMax': message('The reason may not be longer than {max} characters.', 'Sababtu ma dheeraan karto {max} xaraf.'),
  'validation.employees.reasonCounter': message('{count}/{max}, at least 10 characters.', '{count}/{max}, ugu yaraan 10 xaraf.'),
  'confirm.employees.overrideTitle': message('Override employee status (Super Admin)', 'Beddel xaaladda shaqaalaha (Super Admin)'),
  'warning.employees.overrideIntro': message(
    'Normally an employee can only move one step along the lifecycle. This skips those rules. Your name, the time, the old and new status and your reason are recorded in the status history and the audit log.',
    'Caadi ahaan shaqaalaha wuxuu u gudbi karaa hal tillaabo oo keliya wareegga xaaladaha shaqaalaha. Tani waxay ka boodaysaa xeerarkaas. Magacaaga, waqtiga, xaaladdii hore iyo tan cusub iyo sababtaada waxaa lagu diiwaangeliyaa taariikhda xaaladda iyo diiwaanka hubinta.',
  ),
  'notify.employees.statusOverridden': message(
    'Status changed by Super Admin override. The reason was recorded.',
    'Xaaladda waxaa lagu beddelay Beddelka Super Admin (override). Sababta waa la diiwaangeliyey.',
  ),
  'notify.employees.statusChangeFailed': message('Could not change the status.', 'Xaaladda lama beddeli karin.'),

  // Restore dialog
  'confirm.employees.restoreBody': message('Restore {name} ({number}) to the working lists?', 'Ma soo celinaysaa {name} ({number}) liisaska shaqada?'),
  'warning.employees.restoreEffect': message(
    'They keep their current status ({status}) and will appear in {landing}. Their assignments, documents and history are not changed, and nobody else is affected. The restore is recorded in the audit log.',
    'Qofku wuu haynayaa xaaladdiisa hadda ({status}) wuxuuna ka muuqan doonaa {landing}. Qoondayntiisa, dukumentiyadiisa iyo taariikhdiisa lama beddelayo, qof kalena lama saameynayo. Soo celinta waxaa lagu diiwaangelinayaa diiwaanka hubinta.',
  ),
  'option.employees.noteOptional': message('Note (optional)', 'Qoraal (ikhtiyaari ah)'),
  'warning.employees.keptInAuditLog': message('Kept in the audit log.', 'Waxaa lagu xafidayaa diiwaanka hubinta.'),
  'warning.employees.restorePhoneInUse': message('A current employee already uses this phone number.', 'Shaqaale hadda jira ayaa horey u isticmaalaya lambarkan taleefanka.'),
  'option.employees.differentPerson': message('This is a different person', 'Kani waa qof kale'),
  'option.employees.whyDifferentPerson': message('Why is this a different person?', 'Sababta uu kani qof kale u yahay waa maxay?'),
  'warning.employees.differentPersonReasonHint': message('At least 10 characters. Kept in the audit log.', 'Ugu yaraan 10 xaraf. Waxaa lagu xafidayaa diiwaanka hubinta.'),
  'action.employees.openProfile': message('Open profile', 'Fur faylka shaqaalaha'),
  'notify.employees.restored': message('{name} was restored.', '{name} waa la soo celiyey.'),
  'notify.employees.alreadyRestored': message('This employee is already restored.', 'Shaqaalahan horey ayaa loo soo celiyey.'),
  'notify.employees.restoreFailed': message('Could not restore this employee.', 'Shaqaalahan lama soo celin karin.'),

  // Register / edit employee form
  'action.employees.register': message('Register', 'Diiwaangeli'),
  'action.employees.registerAsDifferent': message('Register as a different person', 'Diiwaangeli isagoo qof kale ah'),
  'action.employees.saveAsDifferent': message('Save as a different person', 'Keydi isagoo qof kale ah'),
  'notify.employees.employeeRegistered': message('Employee registered.', 'Shaqaalaha waa la diiwaangeliyey.'),
  'notify.employees.employeeUpdated': message('Employee updated.', 'Shaqaalaha waa la cusboonaysiiyey.'),
  'notify.employees.somethingWentWrong': message('Something went wrong.', 'Wax khalad ah ayaa dhacay.'),
  'option.employees.selectGender': message('Select gender', 'Dooro jinsiga'),
  'option.employees.selectCategory': message('Select category', 'Dooro qayb'),
  'option.employees.notSet': message('Not set', 'Lama dejin'),
  'action.employees.createCategoryLink': message('+ Create Category', '+ Samee Qayb'),
  'action.employees.createPositionLink': message('+ Create Position', '+ Samee Jago'),
  'warning.employees.paymentMismatch': message(
    'This imported number differs from the registration phone. Tick to confirm that this is intended (the number is kept either way).',
    'Lambarkan la soo geliyey wuu ka duwan yahay taleefanka diiwaangelinta. Calaamadee si aad u xaqiijiso in tani ula jeeddo ahayd (lambarka waa la ilaalinayaa labadaba).',
  ),
  'warning.employees.phoneOnRecord': message('This phone number is already on record.', 'Lambarkan taleefanka horey ayaa loo diiwaangeliyey.'),
  'warning.employees.phoneBelongsTo': message('This phone number already belongs to {description}.', 'Lambarkan taleefanka waxaa horey u leh {description}.'),
  'warning.employees.duplicatePersonWarning': message(
    'Registering the same person twice causes problems. Continue only if this really is a different person who shares the number.',
    'In isla qofka laba jeer la diiwaangeliyo waxay keentaa dhibaato. Sii wad kaliya haddii runtii uu yahay qof kale oo lambarka la wadaaga.',
  ),
  'warning.employees.differentPersonFormHint': message(
    'At least 10 characters. This is kept in the audit log.',
    'Ugu yaraan 10 xaraf. Tan waxaa lagu hayaa diiwaanka hubinta.',
  ),
  'warning.employees.fixNumberOrConfirm': message(
    'Fix the number, or save and then confirm that this is a different person.',
    'Hagaaji lambarka, ama keydi ka dibna xaqiiji in kani yahay qof kale.',
  ),
  'warning.employees.sameNameRegistered': message('Someone with the same name is already registered.', 'Qof isla magacaas leh horey ayaa loo diiwaangeliyey.'),
  'warning.employees.sameNameNote': message(
    'Different people can share a name, so this will not stop you from saving.',
    'Dad kala duwan ayaa magac la wadaagi kara, sidaas darteed tani kuma joojinayso inaad keydiso.',
  ),

  // Quick-create dialogs
  'action.employees.createPosition': message('Create position', 'Samee jago'),
  'action.employees.createCategory': message('Create category', 'Samee qayb'),
  'option.employees.noDepartment': message('No department', "Waax la'aan"),
  'notify.employees.positionCreated': message('Position "{name}" created.', 'Jagada "{name}" waa la sameeyey.'),
  'notify.employees.positionCreateFailed': message('Could not create the position.', 'Jagada lama samayn karin.'),
  'notify.employees.categoryCreated': message('Category "{name}" created.', 'Qaybta "{name}" waa la sameeyey.'),
  'notify.employees.categoryCreateFailed': message('Could not create the category.', 'Qaybta lama samayn karin.'),

  // Office Staff registration
  'option.employees.howToAddOfficeStaff': message('How to add Office Staff', 'Sida loogu daro Shaqaalaha Xafiiska'),
  'option.employees.addExistingEmployee': message('Add existing employee', 'Ku dar shaqaale hore u jiray'),
  'option.employees.registerNewOfficeStaff': message('Register new Office Staff', 'Diiwaangeli Shaqaale Xafiis cusub'),
  'status.employees.alreadyOfficeStaff': message('Already Office Staff', 'Horey ayuu u yahay Shaqaale Xafiis'),
  'option.employees.choosePosition': message('Choose a position…', 'Dooro jago…'),
  'action.employees.createPositionLinkLower': message('+ Create position', '+ Samee jago'),
  'option.employees.overwriteEmergencyContact': message('emergency contact', 'xiriirka degdegga ah'),
  'option.employees.overwriteGuarantor': message('guarantor', 'Damiinka'),
  'option.employees.overwritePosition': message('position', 'jagada'),
  'warning.employees.replaceEmergencyContact': message(
    'Replace the recorded emergency contact ({name}, {phone}).',
    'Beddel xiriirka degdegga ah ee diiwaangashan ({name}, {phone}).',
  ),
  'warning.employees.replacePosition': message('Replace the recorded position ({name}).', 'Beddel jagada diiwaangashan ({name}).'),
  'warning.employees.replaceGuarantor': message('Replace the recorded guarantor ({name}, {phone}).', 'Beddel Damiinka diiwaangashan ({name}, {phone}).'),
  'warning.employees.keptUnlessTicked': message('The existing value is kept unless you tick this.', 'Qiimaha jira waa la ilaalinayaa haddii aadan tan calaamadayn.'),
  'warning.employees.chosenFilesNote': message(
    'Chosen files are uploaded right after the employee is saved. Documents can also be added later.',
    'Faylasha la doortay waxaa la soo gelinayaa isla markii shaqaalaha la keydiyo. Dukumentiyada dambe ayaa sidoo kale lagu dari karaa.',
  ),
  'warning.employees.alreadyAssigned': message(
    'This employee already has an active assignment: {assignments}.',
    'Shaqaalahan qoondayn shaqeynaysa ayuu horey u leeyahay: {assignments}.',
  ),
  'warning.employees.secondAssignmentNote': message(
    'Adding Office Staff creates a second assignment with its own salary, and payroll pays both.',
    'Ku darista Shaqaalaha Xafiiska waxay abuuraysaa qoondayn labaad oo mushahar u gaar ah, mushaharkuna wuxuu bixiyaa labadaba.',
  ),
  'warning.employees.understandAdditional': message(
    'I understand - add Office Staff as an additional assignment.',
    'Waan fahamsanahay - ku dar Shaqaalaha Xafiiska qoondayn dheeraad ah.',
  ),
  'warning.employees.phoneExistsList': message('An employee with this phone number already exists:', 'Shaqaale leh lambarkan taleefanka horey ayuu u jiraa:'),
  'action.employees.addThisInstead': message('Add this employee instead', 'Ku dar shaqaalahan halkii'),
  'warning.employees.differentPersonReason': message(
    'If this really is a different person, give a reason (at least 10 characters) to register anyway.',
    'Haddii runtii kani yahay qof kale, sheeg sabab (ugu yaraan 10 xaraf) si aad ugu diiwaangeliso si kastaba.',
  ),
  'option.employees.reasonDifferentPerson': message('Reason this is a different person', 'Sababta uu kani qof kale u yahay'),
  'validation.employees.requiredMissing': message(
    'Some required details are missing or invalid - see the highlighted fields.',
    'Faahfaahin loo baahan yahay ayaa maqan ama aan sax ahayn - eeg goobaha la calaamadeeyey.',
  ),
  'validation.employees.confirmReplacement': message(
    'Confirm the replacement of the existing {fields} or change the value back.',
    'Xaqiiji beddelka {fields} jira ama qiimaha dib ugu celi.',
  ),
  'action.employees.registerOfficeStaff': message('Register Office Staff', 'Diiwaangeli Shaqaalaha Xafiiska'),
  'action.employees.addToOfficeStaff': message('Add to Office Staff', 'Ku dar Shaqaalaha Xafiiska'),
  'warning.employees.searchToContinue': message('Search for an employee above to continue.', 'Kor ka raadi shaqaale si aad u sii wadato.'),
  'action.employees.createAndSelect': message('Create and select', 'Samee oo dooro'),
  'notify.employees.officeStaffRegistered': message('Office Staff registered.', 'Shaqaalaha Xafiiska waa la diiwaangeliyey.'),
  'notify.employees.officeStaffAdded': message('Employee added to Office Staff.', 'Shaqaalaha waa lagu daray Shaqaalaha Xafiiska.'),
  'notify.employees.overwriteConflict': message(
    'Some {fields} details are already recorded and differ. Nothing was changed - confirm each replacement below, or keep the existing value.',
    'Faahfaahin ka mid ah {fields} ayaa horey loo diiwaangeliyey wayna ka duwan yihiin. Waxba lama beddelin - xaqiiji beddel kasta hoose, ama ilaali qiimaha jira.',
  ),
  'notify.employees.additionalAssignmentNeeded': message(
    'This employee already has an assignment. Tick the confirmation below to add Office Staff as a second assignment.',
    'Shaqaalahan qoondayn ayuu horey u leeyahay. Calaamadee xaqiijinta hoose si aad Shaqaalaha Xafiiska ugu darto qoondayn labaad.',
  ),
  'notify.employees.phoneAlreadyExists': message('An employee with this phone number already exists.', 'Shaqaale leh lambarkan taleefanka horey ayuu u jiraa.'),
  'notify.employees.couldNotSave': message('Could not save.', 'Lama keydin karin.'),
  'notify.employees.couldNotUpload': message('could not be uploaded', 'lama soo gelin karin'),
  'notify.employees.positionCreatedSelected': message('Position "{name}" created and selected.', 'Jagada "{name}" waa la sameeyey oo la doortay.'),
  'notify.employees.positionExistsSelected': message('"{name}" already exists - it has been selected.', '"{name}" horey ayuu u jiray - waa la doortay.'),
  'status.employees.onFile': message('On file', 'Diiwaan ku jira'),
  'status.employees.missing': message('Missing', 'Ka maqan'),
  'action.employees.viewFile': message('View', 'Eeg'),
  'action.employees.replaceFile': message('Replace file…', 'Beddel faylka…'),
  'action.employees.replaceDocument': message('Replace {label}', 'Beddel {label}'),
  'action.employees.uploadDocument': message('Upload {label}', 'Soo geli {label}'),
  'warning.employees.replaceFileNote': message(
    'The new file becomes the current one; the previous file is kept in the history.',
    'Faylka cusub wuxuu noqonayaa kan hadda; faylkii hore waxaa lagu hayaa taariikhda.',
  ),
  'warning.employees.readyToUpload': message('Ready to upload: {name}', 'Diyaar loogu soo geliyo: {name}'),
  'notify.employees.documentUploaded': message('{label} uploaded.', '{label} waa la soo geliyey.'),
  'notify.employees.fileUploadFailed': message('Could not upload the file.', 'Faylka lama soo gelin karin.'),
  'notify.employees.fileOpenFailed': message('Could not open the file.', 'Faylka lama furi karin.'),
  'notify.employees.officeStaffSavedTitle': message('Office Staff saved', 'Shaqaalaha Xafiiska waa la keydiyey'),
  'notify.employees.savedCrumb': message('Saved', 'La keydiyey'),
  'warning.employees.uploadFailuresIntro': message(
    'The employee was saved, but some documents could not be uploaded - add them below:',
    'Shaqaalaha waa la keydiyey, laakiin dukumenti qaar lama soo gelin karin - ku dar hoose:',
  ),
  'action.employees.addAnother': message('Add another', 'Ku dar mid kale'),
  'action.employees.goToOfficeStaff': message('Go to Office Staff', 'Tag Shaqaalaha Xafiiska'),

  // Office Staff form validation (utils/officeStaff.ts)
  'validation.employees.salaryRequired': message('Enter the monthly salary.', 'Geli mushaharka bille.'),
  'validation.employees.salaryInvalid': message(
    'Enter a valid amount such as 350 or 350.50 (no negative numbers, at most two decimals).',
    'Geli qadar sax ah sida 350 ama 350.50 (lambarro taban ma ahaan karaan, ugu badnaan labo jajab).',
  ),
  'validation.employees.salaryTooLarge': message('That salary is too large.', 'Mushaharkaas aad ayuu u weyn yahay.'),
  'validation.employees.phoneRequired': message('Enter a phone number.', 'Geli lambarka taleefanka.'),
  'validation.employees.phoneInvalid': message('Enter a valid phone number (at least 6 digits).', 'Geli lambar taleefan oo sax ah (ugu yaraan 6 lambar).'),
  'validation.employees.positionRequired': message('Choose a position.', 'Dooro jago.'),
  'validation.employees.startDateRequired': message('Choose a start date.', 'Dooro taariikhda bilowga.'),
  'validation.employees.emergencyNameRequired': message('Enter the emergency contact name.', 'Geli magaca qofka xiriirka degdegga ah.'),
  'validation.employees.guarantorNameRequired': message('Enter the guarantor name.', 'Geli magaca Damiinka.'),
  'validation.employees.fullNameRequired': message('Enter the full name.', 'Geli magaca oo dhan.'),
  'validation.employees.fileTypeNotAllowed': message(
    '"{name}" is not an allowed file type (allowed: {allowed}).',
    '"{name}" ma aha nooc fayl oo la ogol yahay (la ogol yahay: {allowed}).',
  ),
  'validation.employees.fileTooLarge': message('"{name}" is larger than 10 MB.', '"{name}" wuu ka weyn yahay 10 MB.'),
  'validation.employees.fileEmpty': message('"{name}" is empty.', '"{name}" waa madhan yahay.'),
  'status.employees.documentSummary': message(
    '{onFile} of {required} required documents on file',
    '{onFile} ka mid ah {required} dukumenti oo loo baahan yahay ayaa diiwaan ku jira',
  ),

  // Employees list: column headers, filters and empty state
  'option.employees.unassigned': message('Unassigned', 'Aan la qoondayn'),
  'option.employees.companyNotRecorded': message('Company not recorded', 'Shirkad lama diiwaangelin'),
  'status.employees.notNeeded': message('Not needed', 'Looma baahna'),
  'option.employees.applied': message('Applied', 'La codsaday'),
  'option.employees.joined': message('Joined', 'Ku biiray'),
  'option.employees.dateTo': message('to', 'ilaa'),
  'option.employees.searchEmployees': message('Search by name, phone, or employee number…', 'Ku raadi magac, taleefan, ama lambarka shaqaalaha…'),
  'option.employees.whichEmployeesToList': message('Which employees to list', 'Shaqaalaha la muujinayo'),
  'option.employees.locationPlaceholder': message('Location…', 'Goobta…'),
  'option.employees.noEmployeesFound': message('No employees found', 'Shaqaale lama helin'),
  'option.employees.noEmployeesFoundHint': message('Try adjusting your search or filters.', 'Isku day inaad bedesho raadintaada ama shaandhooyinka.'),

  // Former employees
  'option.employees.separationReason': message('Separation Reason', 'Sababta joojinta'),
  'option.employees.separationDate': message('Separation Date', 'Taariikhda joojinta'),
  'option.employees.rehireEligible': message('Rehire Eligible', 'Dib loogu shaqaalayn karo'),
  'option.employees.noFormerEmployees': message('No former employees', 'Shaqaalah hore u shaqeyn jiray ma jiraan'),
  'option.employees.noFormerEmployeesHint': message('Employees appear here once marked Inactive.', 'Shaqaaluhu halkan ayay ka muuqdaan marka lagu calaamadiyo Aan Shaqeynayn.'),

  // Register / edit employee form: labels and hints
  'option.employees.alternatePhone': message("Alternate phone (employee's own)", 'Taleefan kale (kan shaqaalaha laftiisa)'),
  'option.employees.referenceGuarantorName': message('Reference / guarantor name', 'Magaca tixraaca / Damiinka'),
  'option.employees.paymentAccountNumber': message('Payment / account number (optional)', 'Lambarka lacag-bixinta / akoonka (ikhtiyaari ah)'),
  'warning.employees.paymentAccountHint': message(
    'Used only to pay this employee. It is not their phone number and is never used to find or match them.',
    'Waxaa lagu isticmaalaa oo keliya in lagu bixiyo lacagta shaqaalahan. Ma aha lambarka taleefankiisa, waxaana marnaba lagu isticmaali doonin in lagu helo ama lagu isbarbar dhigo.',
  ),
  'option.employees.secondaryContactName': message('Secondary/emergency contact name', 'Magaca xiriirka labaad/degdegga ah'),
  'option.employees.secondaryContactPhone': message('Secondary/emergency contact phone', 'Taleefanka xiriirka labaad/degdegga ah'),
  'warning.employees.phoneBlankHint': message(
    'May be left blank — some migrated employees have no phone on record.',
    'Waa laga tagi karaa madhan — shaqaale qaar oo la soo wareejiyey ma laha taleefan diiwaan ku jira.',
  ),
  'warning.employees.locationCreateHint': message(
    'Residential/home location — used for future Waiting-list matching.',
    'Goobta deegaanka/guriga — waxaa loo isticmaalayaa isku-habaynta Liiska Sugitaanka ee mustaqbalka.',
  ),
  'warning.employees.locationBlankHint': message(
    'May be left blank — some migrated employees have no location on record.',
    'Waa laga tagi karaa madhan — shaqaale qaar oo la soo wareejiyey ma laha goob diiwaan ku jirta.',
  ),
  'warning.employees.genderUnsetHint': message(
    'May be left unset — some migrated employees have no gender on record.',
    'Waa laga tagi karaa iyadoo aan la dooran — shaqaale qaar oo la soo wareejiyey ma laha jinsi diiwaan ku jira.',
  ),
  'option.employees.maritalStatus': message('Marital status', 'Xaaladda guurka'),
  'option.employees.livesWith': message('Lives with', 'La nool'),
  'option.employees.categorySpecialization': message('Category specialization', 'Takhasuska qaybta'),
  'warning.employees.specializationHint': message(
    'Home Team: Work Type (e.g. Full Time – Jiif). Cooking: Specialization (e.g. Cook).',
    'Home Team: Nooca Shaqada (tusaale: Full Time – Jiif). Cooking: Takhasus (tusaale: Cook).',
  ),
  'option.employees.applicationDate': message('Application date', 'Taariikhda codsiga'),
  'warning.employees.applicationDateBlankHint': message(
    'May be left blank — some migrated employees have no application date on record.',
    'Waa laga tagi karaa madhan — shaqaale qaar oo la soo wareejiyey ma laha taariikhda codsiga diiwaan ku jirta.',
  ),
  'option.employees.joiningDate': message('Joining date', 'Taariikhda ku biirista'),
  'option.employees.experience': message('Experience', 'Khibrad'),
  'option.employees.trainingFeeAmount': message('Training fee amount', 'Qadarka lacagta tababarka'),
  'option.employees.trainingFeeStatus': message('Training fee status', 'Xaaladda lacagta tababarka'),
  'option.employees.employmentType': message('Employment type', 'Nooca shaqaaleynta'),
  'warning.employees.employmentTypeHint': message(
    "Temporary employees can cover a Permanent employee's assignment.",
    'Shaqaalaha Ku-meel-gaarka ah wuu buuxin karaa qoondaynta shaqaale Joogto ah.',
  ),

  // Quick-create dialogs and the category / department / position pages
  'option.employees.positionName': message('Position name', 'Magaca jagada'),
  'option.employees.categoryName': message('Category name', 'Magaca qaybta'),
  'option.employees.departmentOptional': message('Department (optional)', 'Waaxda (ikhtiyaari ah)'),
  'option.employees.descriptionOptional': message('Description (optional)', 'Sharaxaad (ikhtiyaari ah)'),
  'option.employees.loadingCategories': message('Loading categories…', 'Waa la soo rarayaa qaybaha…'),
  'option.employees.noCategories': message('No categories', 'Qayb ma jirto'),
  'option.employees.categoryEmployeeCount.one': message('{n} employee', '{n} shaqaale'),
  'option.employees.categoryEmployeeCount.other': message('{n} employees', '{n} shaqaale'),
  'option.employees.loadingDepartments': message('Loading departments…', 'Waa la soo rarayaa waaxaha…'),
  'option.employees.noDepartmentsYet': message('No departments yet', 'Waaxo weli ma jiraan'),
  'option.employees.loadingPositions': message('Loading positions…', 'Waa la soo rarayaa jagooyinka…'),
  'option.employees.noPositionsYet': message('No positions yet', 'Jagooyin weli ma jiraan'),

  // Attendance
  'option.employees.loadingRoster': message('Loading roster…', 'Waa la soo rarayaa liiska xaadirinta…'),
  'option.employees.noOfficeStaffYet': message('No Office Staff yet', 'Shaqaalaha Xafiiska weli ma jiro'),
  'option.employees.noOfficeStaffHint': message('Assign an employee to Fayadhowr Office to see them here.', 'U qoondee shaqaale Xafiiska Fayadhowr si aad halkan u aragto.'),

  // Office capacity card
  'option.employees.officeCapacityTitle': message('{name} - approved capacity', '{name} - tirada la ansixiyey'),
  'option.employees.officeNoLimitSet': message('No limit set', 'Xad lama dejin'),
  'option.employees.officeCapacity': message('Capacity {capacity}', 'Tirada la ogol yahay {capacity}'),
  'option.employees.officeAssigned': message('{count} assigned', '{count} la qoondeeyey'),
  'option.employees.officeFree': message('{count} free', '{count} bannaan'),
  'option.employees.officeApprovedCapacity': message('Approved employee capacity', 'Tirada shaqaalaha la ansixiyey'),

  // Office Staff registration: headings, labels and hints
  'option.employees.findTheEmployee': message('Find the employee', 'Raadi shaqaalaha'),
  'option.employees.searchOfficeStaff': message('Search by name, phone number or employee number…', 'Ku raadi magac, lambarka taleefanka ama lambarka shaqaalaha…'),
  'warning.employees.noEmployeeFound': message(
    'No employee found. Check the spelling, or use "Register new Office Staff".',
    'Shaqaale lama helin. Hubi higaadda, ama isticmaal "Diiwaangeli Shaqaale Xafiis cusub".',
  ),
  'option.employees.noPhone': message('No phone', 'Taleefan ma jiro'),
  'option.employees.noPosition': message('No position', 'Jago ma jirto'),
  'option.employees.salaryPerMonth': message('{amount} {currency}/month', '{amount} {currency}/bishii'),
  'option.employees.noSalaryRecorded': message('No salary recorded', 'Mushahar lama diiwaangelin'),
  'option.employees.stepEmployeeInfo': message('1. Employee information', '1. Macluumaadka shaqaalaha'),
  'option.employees.employeeNumber': message('Employee number', 'Lambarka shaqaalaha'),
  'warning.employees.employeeNumberNewHint': message(
    'Assigned automatically when you save, using the usual EMP-000000 numbering.',
    'Si toos ah ayaa loo siinayaa marka aad keydiso, iyadoo la adeegsanayo nidaamka caadiga ah ee EMP-000000.',
  ),
  'option.employees.assignedOnSave': message('Assigned on save', 'Waxaa la siinayaa marka la keydiyo'),
  'option.employees.employeeCategory': message('Employee category', 'Qaybta shaqaalaha'),
  'warning.employees.categoryNewHint': message('Set automatically for everyone registered as Office Staff.', 'Si toos ah ayaa loo dejiyaa qof kasta oo loo diiwaangeliyo Shaqaale Xafiis.'),
  'warning.employees.employeeNumberKeptHint': message('The existing number is kept.', 'Lambarka jira waa la ilaalinayaa.'),
  'warning.employees.categoryUnchangedHint': message(
    "Unchanged: adding Office Staff never changes an employee's category.",
    'Lama beddelayo: ku darista Shaqaalaha Xafiiska marnaba ma beddesho qaybta shaqaalaha.',
  ),
  'option.employees.positionJobTitle': message('Position / job title', 'Jagada / magaca shaqada'),
  'warning.employees.positionCurrentHint': message('Currently recorded: {name}', 'Hadda diiwaan ku jira: {name}'),
  'warning.employees.positionsManagedHint': message(
    'Positions are managed under Human Resources > Positions.',
    'Jagooyinka waxaa laga maamulaa Maamulka shaqaalaha > Jagooyinka.',
  ),
  'option.employees.monthlySalaryUsd': message('Monthly salary (USD)', 'Mushaharka bille (USD)'),
  'warning.employees.salaryCurrentHint': message(
    'Current salary on record: {amount} {currency}. Confirm or change it. Saving does not pay anyone or create payroll.',
    'Mushaharka hadda diiwaanka ku jira: {amount} {currency}. Xaqiiji ama beddel. Keydintu qofna ma bixinayso mana abuurayso mushahar.',
  ),
  'warning.employees.salarySavingHint': message(
    'Saving a salary does not pay anyone or create payroll.',
    'Keydinta mushaharku qofna ma bixiso mana abuurto mushahar.',
  ),
  'option.employees.emergencyContactName': message('Emergency contact name', 'Magaca xiriirka degdegga ah'),
  'option.employees.emergencyContactPhone': message('Emergency contact phone', 'Taleefanka xiriirka degdegga ah'),
  'option.employees.stepGuarantor': message(
    '2. Guarantor (Damiin) - separate from the emergency contact',
    '2. Damiin - ka gaar ah xiriirka degdegga ah',
  ),
  'option.employees.guarantorFullName': message('Guarantor full name', 'Magaca oo dhan ee Damiinka'),
  'option.employees.guarantorPhoneNumber': message('Guarantor phone number', 'Lambarka taleefanka ee Damiinka'),
  'option.employees.relationshipOptional': message('Relationship (optional)', 'Xidhiidhka (ikhtiyaari ah)'),
  'option.employees.stepDocuments': message('3. Documents', '3. Dukumentiyada'),
  'option.employees.documents': message('Documents', 'Dukumentiyada'),
  'warning.employees.newPositionHint': message(
    'Positions never carry a salary. The salary is entered for each employee.',
    'Jagooyinka marnaba mushahar ma leh. Mushaharka waxaa la geliyaa shaqaale kasta.',
  ),
  'option.employees.emergencyContact': message('Emergency contact', 'Xiriirka degdegga ah'),
  'option.employees.guarantor': message('Guarantor', 'Damiinka'),

  // Office Staff document groups (the label shown for each group key)
  'option.employees.documentGroup.identity': message('Identification document', 'Dukumentiga aqoonsiga'),
  'option.employees.documentGroup.employment': message('Employment / appointment document', 'Dukumentiga shaqaaleynta / magacaabista'),
  'option.employees.documentGroup.guarantor': message('Guarantor documents', 'Dukumentiyada Damiinka'),
  'option.employees.documentGroup.other': message('Other supporting documents', 'Dukumentiyada kale ee taageerada'),
});
