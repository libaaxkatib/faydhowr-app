// Messages for Excel imports.
// Keys are `<category>.imports.<name>`; every entry holds its English text (exactly as the screen shows it today) and its Somali text.

import { defineMessages, message } from '../catalog.ts';

export const imports = defineMessages({
  // The import types offered in the "What are you importing?" list and the sentence that explains each one
  'option.imports.typeCompanies': message('Companies', 'Shirkadaha'),
  'option.imports.typeEmployeeRegistrations': message('New employee registrations', 'Diiwaangelinta shaqaale cusub'),
  'option.imports.typeWaitingList': message('Waiting list', 'Liiska sugitaanka'),
  'option.imports.typeActivationPreview': message('Activation preview (read-only)', 'Horu-aragga ka dhigidda Shaqeynaya (akhris oo keliya)'),
  'warning.imports.summaryCompanies': message(
    'Registers client companies and their other spellings. A company that already exists is skipped, never changed.',
    'Waxay diiwaangelisaa shirkadaha macaamiisha iyo qaababka kale ee loo qoro magacyadooda. Shirkad horey u jirtay waa la dhaafaa, marnaba lama beddelo.',
  ),
  'warning.imports.summaryEmployeeRegistrations': message(
    'Registers NEW people at the Application stage. An employee who already exists is skipped, never updated.',
    'Waxay diiwaangelisaa dad CUSUB marxaladda Codsade. Shaqaale horey u jiray waa la dhaafaa, marnaba lama cusboonaysiiyo.',
  ),
  'warning.imports.summaryWaitingList': message(
    'Moves EXISTING employees to Waiting when their name and registration phone both match. Anything unsure goes to manual review. Nobody is created or changed otherwise.',
    'Waxay shaqaale JIRA u wareejisaa Shaqa sugayaal marka magacooda iyo taleefanka diiwaangelinta labaduba is waafaqaan. Wax kasta oo aan hubaal ahayn waxay aadaan dib-u-eegis gacanta ah. Haddii kale qof lama sameeyo lamana beddelo.',
  ),
  'warning.imports.summaryActivationPreview': message(
    'Checks the approved employee plan and shows how each person would become Active. It never activates anyone.',
    'Waxay hubisaa qorshaha shaqaalaha la ansixiyey, waxayna muujisaa sida qof kastaa Shaqeynaya ku noqon lahaa. Waligeed qofna ma ka dhigto Shaqeynaya.',
  ),
  'warning.imports.uploadIntro': message(
    'Only a Super Admin or an HR Manager with the Import HR Data permission can import. Uploading only {checks} the file and shows a preview. Nothing is added or changed until you confirm, existing records are never updated, and the file itself is not kept.',
    'Kaliya Super Admin ama HR Manager haysta ogolaanshaha Import HR Data ayaa soo gelin kara. Soo gelinta faylku waxay kaliya {checks} faylka, waxayna muujisaa horu-aragga. Waxba lama kordhiyo lamana beddelo ilaa aad xaqiijiso, diiwaannada jira marnaba lama cusboonaysiiyo, faylka laftiisana lama hayo.',
  ),
  'warning.imports.uploadIntroChecks': message('checks', 'hubisaa'),
  'warning.imports.registrationPaymentNote': message(
    'An optional payment/account number is saved in its own field and is never shown in the preview. It is never used to match anyone: the registration phone is the only phone used for matching.',
    'Lambarka lacag-bixinta/akoonka ee ikhtiyaarka ah wuxuu ku keydsan yahay goobtiisa gaarka ah mana muuqdo horu-aragga. Marnaba looma isticmaalo in qof lagu waafajiyo: taleefanka diiwaangelinta ayaa ah kaliya taleefanka loo isticmaalo waafajinta.',
  ),

  // Headings, labels and hints of the upload card
  'option.imports.uploadHeading': message('1. Upload a workbook', '1. Soo geli faylka Excel'),
  'option.imports.whatImporting': message('What are you importing?', 'Maxaad soo gelinaysaa?'),
  'option.imports.fileLabel': message('Excel (.xlsx) or CSV file', 'Fayl Excel (.xlsx) ama CSV ah'),
  'warning.imports.fileHint': message('Up to 5 MB and 2,000 rows.', 'Ilaa 5 MB iyo 2,000 saf.'),
  'option.imports.requiredColumns': message('Required columns (first row):', 'Tiirarka waajibka ah (safka ugu horreeya):'),
  'option.imports.optionalColumns': message('Optional:', 'Ikhtiyaari ah:'),

  // Import history table
  'option.imports.historyHeading': message('Import history', 'Taariikhda soo gelinta'),
  'option.imports.colRows': message('Rows', 'Safafka'),
  'option.imports.colUploadedBy': message('Uploaded by', 'Soo geliyey'),
  'option.imports.colWhen': message('When', 'Goorta'),
  'option.imports.historyRows': message('{total} ({imported} imported)', '{total} ({imported} la soo geliyey)'),
  'option.imports.historyEmptyTitle': message('No imports yet', 'Soo gelinno weli ma jiraan'),
  'option.imports.historyEmptyBody': message('Uploaded workbooks and their results are listed here.', 'Faylasha la soo geliyey iyo natiijooyinkooda halkan ayaa lagu taxayaa.'),

  // Batch panel
  'option.imports.batchTitle': message('Import #{id} · {type}', 'Soo gelinta #{id} · {type}'),
  'option.imports.batchUploaded': message('Uploaded {when} by {name}', 'Waxaa soo geliyey {name} {when}'),
  'option.imports.batchUploadedConfirmed': message('Uploaded {when} by {name} · confirmed {confirmed}', 'Waxaa soo geliyey {name} {when} · waa la xaqiijiyey {confirmed}'),
  'option.imports.columnsNotImported': message('Columns that are not imported: {list}.', 'Tiirarka aan la soo gelin: {list}.'),
  'option.imports.tileTotal': message('Rows in the file', 'Safafka ku jira faylka'),
  'option.imports.tileDuplicate': message('Already exist / repeated', 'Horey u jiray / soo noqnoqday'),
  'option.imports.progressCounts': message('{imported} imported · {skipped} skipped · {failed} failed', '{imported} la soo geliyey · {skipped} la dhaafay · {failed} fashilmay'),
  'option.imports.activationReady': message('Activation-ready: {n}', 'U diyaar ah ka dhigidda Shaqeynaya: {n}'),
  'option.imports.genuineConflicts': message('Genuine conflicts (excluded until corrected): {n}', 'Khilaafaad dhab ah (waa laga saaray ilaa la saxo): {n}'),
  'warning.imports.genuineConflictsBody': message(
    'An employee whose number or name does not match the plan, whose status changed, or whose pay data is missing. Nothing was matched, created or changed. Download the report for the reason on each row.',
    'Shaqaale lambarkiisa ama magaciisa uusan qorshaha la mid ahayn, xaaladdiisu isbeddeshay, ama xogtiisa mushaharka ay maqan tahay. Waxba lama waafajin, lama sameyn, lamana beddelin. Soo deji warbixinta si aad sababta saf kasta u aragto.',
  ),
  'warning.imports.lookalikes': message(
    '{n} ready employee(s) have a look-alike record (same name or a different phone). They are included because their employee number and name match the plan; nothing is merged, archived or changed.',
    '{n} shaqaale oo diyaar ah ayaa leh diiwaan la mid ah (isla magac ama taleefan kale). Waa lagu daray maxaa yeelay lambarka iyo magaca shaqaalahoodu qorshaha way waafaqaan; waxba lama isku darin, lama kaydin, lamana beddelin.',
  ),
  'option.imports.howBecomeActive': message('How the employees would become Active (all rows checked)', 'Sida shaqaaluhu Shaqeynaya ku noqon lahaa (dhammaan safafka waa la hubiyey)'),
  'option.imports.warningsList': message('Warnings: {list}', 'Digniino: {list}'),
  'option.imports.colRow': message('Row', 'Safka'),
  'option.imports.colResult': message('Result', 'Natiijada'),
  'option.imports.colMeaning': message('What it means', 'Macnaheedu'),
  'option.imports.colDetails': message('Details', 'Faahfaahin'),
  'option.imports.detailsDeleted': message('Deleted', 'La tirtiray'),
  'option.imports.detailStatusToActive': message('{status} → active', '{status} → Shaqeynaya'),
  'option.imports.detailCandidates': message('(candidates: {list})', '(musharaxiin: {list})'),
  'option.imports.rowsEmpty': message('No rows in this view', 'Muuqaalkan safaf kuma jiraan'),

  // Buttons, toasts and notices on the page and the batch panel
  'action.imports.downloadReport': message('Download report (CSV)', 'Soo deji warbixinta (CSV)'),
  'notify.imports.chooseFileFirst': message('Choose a file first.', 'Marka hore dooro fayl.'),
  'notify.imports.fileChecked': message(
    'File checked. Review the preview below; nothing has been imported yet.',
    'Faylka waa la hubiyey. Dib u eeg horu-aragga hoose; weli waxba lama soo gelin.',
  ),
  'notify.imports.somethingWrong': message('Something went wrong.', 'Wax khalad ah ayaa dhacay.'),
  'notify.imports.finished': message(
    'Import finished: {imported} imported, {skipped} skipped, {failed} failed.',
    'Soo gelintii waa dhammaatay: {imported} la soo geliyey, {skipped} la dhaafay, {failed} fashilmay.',
  ),
  'notify.imports.cancelled': message('Import cancelled and its details deleted.', 'Soo gelinta waa la joojiyey faahfaahinteeduna waa la tirtiray.'),
  'warning.imports.previewOnlyTitle': message('Preview only.', 'Horu-aragga oo keliya.'),
  'warning.imports.previewOnlyBody': message(
    'This checks the approved employee plan and shows how each person would become Active. It never activates anyone and cannot be confirmed. Activating real employees needs its own separate approval.',
    'Tani waxay hubisaa qorshaha shaqaalaha la ansixiyey, waxayna muujisaa sida qof kastaa Shaqeynaya ku noqon lahaa. Waligeed qofna ma ka dhigto Shaqeynaya mana la xaqiijin karo. Ka dhigidda shaqaale dhab ah Shaqeynaya waxay u baahan tahay ansixin gooni ah.',
  ),
  'warning.imports.stopped': message('The import stopped before it finished.', 'Soo gelinta way istaagtay intaysan dhammaan.'),
  'warning.imports.detailsDeleted': message(
    'The personal details for this import have been deleted. Only the counts and result codes remain.',
    'Faahfaahinta shaqsiyeed ee soo gelintan waa la tirtiray. Kaliya tirooyinka iyo koodhadhka natiijada ayaa hadhay.',
  ),
  'warning.imports.deletedIn.one': message(
    'Personal details in this preview (names, phone numbers) are deleted automatically in {n} day.',
    'Faahfaahinta shaqsiyeed ee horu-aragganan (magacyada, lambarrada taleefanka) si toos ah ayaa loo tirtiraa {n} maalin gudahood.',
  ),
  'warning.imports.deletedIn.other': message(
    'Personal details in this preview (names, phone numbers) are deleted automatically in {n} days.',
    'Faahfaahinta shaqsiyeed ee horu-aragganan (magacyada, lambarrada taleefanka) si toos ah ayaa loo tirtiraa {n} maalmood gudahood.',
  ),

  // Confirmation dialogs
  'confirm.imports.importTitle': message('Import the ready rows?', 'Ma soo gelisaa safafka diyaarka ah?'),
  'confirm.imports.willImport.one': message('{n} ready row will be imported.', 'Safafka diyaarka ah ee la soo gelin doono: {n}.'),
  'confirm.imports.willImport.other': message('{n} ready rows will be imported.', 'Safafka diyaarka ah ee la soo gelin doono: {n}.'),
  'confirm.imports.willNotImport.one': message('{n} other row will not be imported.', 'Safafka kale ee aan la soo gelin: {n}.'),
  'confirm.imports.willNotImport.other': message('{n} other rows will not be imported.', 'Safafka kale ee aan la soo gelin: {n}.'),
  'confirm.imports.existingSkippedFinal': message(
    'Records that already exist are skipped and never changed. This cannot be undone from this screen.',
    'Diiwaannada horey u jiray waa la dhaafaa, marnaba lama beddelo. Tan lagama noqon karo shaashaddan.',
  ),
  'confirm.imports.cancelTitle': message('Cancel this import?', 'Ma joojisaa soo gelintan?'),
  'confirm.imports.cancelBody': message(
    'The preview and the personal details in it will be deleted now. Nothing has been imported. You can upload the file again later.',
    'Horu-aragga iyo faahfaahinta shaqsiyeed ee ku jirta hadda ayaa la tirtiri doonaa. Waxba lama soo gelin. Faylka mar dambe ayaad dib u soo geli kartaa.',
  ),

  // Batch status badge
  'status.imports.staged': message('Waiting for confirmation', 'Xaqiijin ayaa la sugayaa'),
  'status.imports.processing': message('Importing', 'Waa la soo gelinayaa'),
  'status.imports.completed': message('Completed', 'Waa dhammaaday'),
  'status.imports.cancelled': message('Cancelled', 'Waa la joojiyey'),

  // Checks made on the file before it is sent
  'validation.imports.fileType': message('Choose an .xlsx or .csv file.', 'Dooro fayl .xlsx ama .csv ah.'),
  'validation.imports.fileEmpty': message('The file is empty.', 'Faylku waa madhan yahay.'),
  'validation.imports.fileLarge': message('The file is larger than 5 MB.', 'Faylku wuu ka weyn yahay 5 MB.'),

  // Plain-language wording of the server's import error codes
  'notify.imports.errorFileTypeNotAllowed': message('Only .xlsx and .csv files can be imported.', 'Kaliya faylasha .xlsx iyo .csv ayaa la soo gelin karaa.'),
  'notify.imports.errorFileContentMismatch': message(
    'This file is not what its name says. Save the sheet again as .xlsx or .csv.',
    'Faylkani ma aha waxa magaciisu sheegayo. Mar kale u keydi xaashida sidii .xlsx ama .csv.',
  ),
  'notify.imports.errorFileTooLarge': message('The file is larger than 5 MB. Split it into smaller files.', 'Faylku wuu ka weyn yahay 5 MB. U qaybi faylal yaryar.'),
  'notify.imports.errorFileEmpty': message('The file has no data rows.', 'Faylku safaf xog ah ma laha.'),
  'notify.imports.errorFileUnreadable': message(
    'The file could not be read. Save it again as .xlsx or .csv and retry.',
    'Faylka lama akhrin karin. Mar kale u keydi sidii .xlsx ama .csv oo isku day mar kale.',
  ),
  'notify.imports.errorHeaderNotFound': message(
    'No header row was found. The first row must name the columns.',
    'Safka madaxa lama helin. Safka ugu horreeya waa inuu magacaabaa tiirarka.',
  ),
  'notify.imports.errorMissingColumns': message('The file is missing a required column.', 'Faylka wuxuu ka maqan yahay tiir waajib ah.'),
  'notify.imports.errorDuplicateColumn': message('A column appears more than once in the header row.', 'Tiir ayaa ka muuqda in ka badan hal mar safka madaxa.'),
  'notify.imports.errorTooManyRows': message(
    'The file has too many rows. Split it into smaller files (2,000 rows at most).',
    'Faylku wuxuu leeyahay safaf aad u badan. U qaybi faylal yaryar (ugu badnaan 2,000 saf).',
  ),
  'notify.imports.errorDuplicateImport': message(
    'This exact file was already uploaded. Open its existing import instead.',
    'Isla faylkan horey ayaa loo soo geliyey. Halkii, fur soo gelintiisii jirtay.',
  ),
  'notify.imports.errorImportBusy': message('This import is already being processed. Wait a moment.', 'Soo gelintan horey ayaa lagu shaqeynayaa. Wax yar sug.'),
  'notify.imports.errorImportNotConfirmed': message('Confirm the import first.', 'Marka hore xaqiiji soo gelinta.'),
  'notify.imports.errorImportCancelled': message('This import was cancelled.', 'Soo gelintan waa la joojiyey.'),
  'notify.imports.errorImportExpired': message(
    'The details of this import were deleted. Upload the file again.',
    'Faahfaahinta soo gelintan waa la tirtiray. Dib u soo geli faylka.',
  ),
  'notify.imports.errorPreviewOnly': message('This is a read-only preview. It cannot be confirmed.', 'Kani waa horu-aragga akhris oo keliya ah. Lama xaqiijin karo.'),
  'notify.imports.errorForbidden': message(
    'Imports are reserved for a Super Admin or an HR Manager who holds the Import HR Data permission.',
    'Soo gelinta waxaa u banaan Super Admin ama HR Manager haysta ogolaanshaha Import HR Data.',
  ),

  // Plain-language names of the row codes shown in the preview
  'validation.imports.rowNameRequired': message('Name missing', 'Magaca waa maqan yahay'),
  'validation.imports.rowPhoneInvalid': message('Phone is not a full number', 'Taleefanku ma aha lambar buuxda'),
  'validation.imports.rowGenderInvalid': message('Gender must be male or female', 'Jinsigu waa inuu noqdaa lab ama dheddig'),
  'validation.imports.rowLocationInvalid': message('Location missing', 'Goobta waa maqan tahay'),
  'validation.imports.rowCategoryInvalid': message('Category must be company or guri', 'Qaybtu waa inay noqotaa shirkad ama guri'),
  'validation.imports.rowCategoryUnknown': message('Unknown employee category', 'Qayb shaqaale oo aan la aqoon'),
  'validation.imports.rowDateInvalid': message('Date not understood', 'Taariikhda lama fahmin'),
  'validation.imports.rowDateInFuture': message('Date is in the future', 'Taariikhdu waa mustaqbalka'),
  'validation.imports.rowCompanyExists': message('Company already exists', 'Shirkaddan horey ayay u jirtay'),
  'validation.imports.rowAliasExists': message('Already a known spelling of a company', 'Horey waa qaab loo yaqaan magaca shirkad'),
  'validation.imports.rowEmployeeExists': message('Employee already registered', 'Shaqaalaha horey ayaa loo diiwaangeliyey'),
  'validation.imports.rowDuplicateInFile': message('Repeated earlier in the file', 'Faylka hore ayaa lagu soo celiyey'),
  'validation.imports.rowAmbiguousMatch': message('Matches more than one company', 'Waxay la mid tahay in ka badan hal shirkad'),
  'validation.imports.rowAliasBelongsToOtherCompany': message('Spelling belongs to another company', 'Qaabka magacu wuxuu iska leeyahay shirkad kale'),
  'validation.imports.rowAliasSharedInFile': message('Spelling shared with another row', 'Qaabka magaca waxaa la wadaagaa saf kale'),
  'validation.imports.rowPhoneSharedDifferentName': message('Phone belongs to someone with another name', 'Taleefankan wuxuu iska leeyahay qof magac kale leh'),
  'validation.imports.rowSameNameDifferentPhone': message('Same name, different phone', 'Isla magac, taleefan kale'),
  'validation.imports.rowNameConflict': message('Phone matches, name differs', 'Taleefanku waa isku mid, magacu way ka duwan yahay'),
  'validation.imports.rowNameOnlyMatch': message('Name matches, phone differs', 'Magacu waa isku mid, taleefanku way ka duwan yahay'),
  'validation.imports.rowNoMatch': message('No matching employee', 'Shaqaale ku habboon ma jiro'),
  'validation.imports.rowMultiplePhoneMatches': message('Several employees share this phone', 'Dhowr shaqaale ayaa wadaaga taleefankan'),
  'validation.imports.rowAlternatePhoneOnlyMatch': message('Only matches an alternate phone', 'Waxay la mid tahay taleefan beddel ah oo keliya'),
  'validation.imports.rowArchivedMatch': message('Only matches an archived record', 'Waxay la mid tahay diiwaan kaydsan oo keliya'),
  'validation.imports.rowAlreadyWaiting': message('Already on the waiting list', 'Horey ayuu ugu jiray liiska sugitaanka'),
  'validation.imports.rowTransitionNotAllowed': message('Status cannot move to Waiting', 'Xaaladda looma wareejin karo Shaqa sugayaal'),
  'validation.imports.rowRegistrationWorkflowIncomplete': message(
    'New registration - the guarantor, contract, uniform, training and practical steps are not complete',
    'Diiwaangelin cusub - tillaabooyinka Damiin, Hishiis, Uniform, Tababar iyo Baraatiko ma dhammaystirna',
  ),
  'validation.imports.alreadyActive': message('Already Active', 'Horey waa Shaqeynaya'),
  'validation.imports.rowIdentityMismatch': message('Employee number or name does not match the plan', 'Lambarka ama magaca shaqaalaha qorshaha kuma habboona'),
  'validation.imports.rowEmployeeNotFound': message('Employee not found', 'Shaqaalaha lama helin'),
  'validation.imports.rowEmployeeArchived': message('Employee is archived', 'Shaqaalaha waa kaydsan yahay'),
  'validation.imports.rowStatusChangedSincePlan': message('Status changed since the plan was made', 'Xaaladda waa isbeddeshay tan iyo markii qorshaha la sameeyey'),
  'validation.imports.rowEmployeeNumberConflict': message('Plan number belongs to a different employee record', 'Lambarka qorshaha wuxuu iska leeyahay diiwaan shaqaale oo kale'),
  'validation.imports.rowSalaryOrStartMissing': message('Salary or start date missing', 'Mushaharka ama taariikhda bilowga waa maqan yihiin'),
  'validation.imports.rowRetentionExpired': message('Not imported before the details were deleted', 'Lama soo gelin ka hor intaan faahfaahinta la tirtirin'),
  'validation.imports.rowMatchChanged': message('Employee changed after the preview', 'Shaqaalaha wuu isbeddelay ka dib horu-aragga'),
  'validation.imports.rowMatchLost': message('Matched employee no longer exists', 'Shaqaalihii la is waafajiyey mar dambe ma jiro'),
  'validation.imports.rowNeedsReviewSincePreview': message('A similar employee appeared after the preview', 'Shaqaale la mid ah ayaa soo muuqday ka dib horu-aragga'),
  'validation.imports.rowUnexpectedError': message('Unexpected error (rolled back)', 'Khalad lama filaan ah (dib loo celiyey)'),

  // How an employee would become Active (activation preview)
  'option.imports.pathNormalStep': message('Normal step (Waiting to Active)', 'Tillaabo caadi ah (Shaqa sugayaal ilaa Shaqeynaya)'),
  'option.imports.pathRehire': message('Rehire (Inactive to Active)', 'Dib u shaqaalaysiin (Aan Shaqeynayn ilaa Shaqeynaya)'),
  'option.imports.pathSuperAdminOverride': message('Needs Super Admin override with a reason', 'Waxay u baahan tahay beddelka Super Admin oo sabab leh'),
});
