// Messages added by the UI audit remediation (package A: Employee Detail, company assignment dialogs, status override). Every message is `proposed`: the Somali
// was written for this implementation and awaits the owner's review. No existing message is reworded here - where older wording became wrong (for example
// "Only a Super Admin can record it") the screens now use the NEW key below and the old key stays defined, unchanged.

import { defineMessages, message } from '../catalog.ts';

export const uiAuditA = defineMessages({
  // ---------------------------------------------------------------- the replacement choice is optional
  'option.hr.successorQuestionOptional': message('Who takes over this Permanent position? (optional)', 'Yaa qaadanaya jagadan Joogto ah? (ikhtiyaari)'),
  'option.hr.successorHelpOptional': message(
    'A replacement is optional: you can give the position to someone later. If you choose one now, the replacement\'s Permanent assignment and salary start on {start} (that day is included).',
    'Beddelid ma aha qasab: jagada goor dambe ayaad qof siin kartaa. Haddii aad hadda mid dooratid, qoondaynta Joogto ah iyo mushaharka beddelaadku waxay bilaabmayaan {start} (maalintaas waa lagu daray).',
  ),
  'option.hr.successorNone': message('No replacement now', 'Beddelid ma jirto hadda'),

  // ---------------------------------------------------------------- closed payroll: who may record the correction (Super Admin AND HR Manager)
  'warning.company.correctionNoticeBoth': message(
    'This change reaches payroll that is already approved or finalized. A Super Admin or an HR Manager can record it, as a correction with a reason. The closed month stays exactly as it was; if someone was overpaid, the amount is deducted from their later salaries until it is repaid.',
    'Isbeddelkan wuxuu gaarayaa mushahar horey loo ansixiyey ama loo dhammaystiray. Super Admin ama HR Manager ayaa diiwaangelin kara, isagoo u diiwaangelinaya sixitaan leh sabab. Bisha xidhan sidii hore ayay u hadhaysaa; haddii qof lagu bixiyey lacag xad-dhaaf ah, qadarkaas waxaa laga jarayaa mushaharkiisa dambe ilaa laga soo celiyo.',
  ),
  'warning.company.startDateChangeBoth': message(
    'Changing the start date changes the payable days of every month from {month} on. The original date ({oldStart}) is kept in the history. If an approved or finalized payroll month is really affected, a Super Admin or an HR Manager can make the change, with a reason: the closed month stays as it was and any overpayment is deducted from later salaries.',
    'Beddelidda taariikhda bilowga waxay beddelaysaa maalmaha la bixinayo ee bil kasta laga bilaabo {month}. Taariikhdii asalka ahayd ({oldStart}) waxay ku hadhaysaa taariikhda. Haddii bil mushahar oo la ansixiyey ama la dhammaystiray runtii ay saameyso, Super Admin ama HR Manager ayaa beddeli kara, isagoo sabab bixinaya: bisha xidhan sidii hore ayay u hadhaysaa, lacag kasta oo xad-dhaaf ahna waxaa laga jarayaa mushaharrada dambe.',
  ),

  // ---------------------------------------------------------------- Employee Detail: assignment workflows
  'option.employeeDetail.salaryInheritedShort': message(
    'Salary is inherited from the Permanent employee whose position this Replacement holds; change it on that Permanent assignment.',
    'Mushaharka waxaa laga dhaxlaa shaqaalaha Joogtada ah ee jagadiisa Beddelaadkani haysto; ka beddel qoondaynta Joogtada ah.',
  ),
  'notify.employeeDetail.assignmentRowNotFound': message(
    'This assignment could not be loaded from its company list. Open it from the company\'s Employees page instead.',
    'Qoondayntan lama soo qaadi karin liiska shirkadda. Ka fur bogga Shaqaalaha shirkadda halkii.',
  ),
  'option.employeeDetail.awayConfirmServer': message(
    'The server asks you to confirm before this can be saved.',
    'Server-ku wuxuu kuu sheegayaa inaad xaqiijiso ka hor intan aan la kaydin.',
  ),
});
