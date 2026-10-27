import test from 'node:test';
import assert from 'node:assert/strict';

import { CATEGORIES, categoryOf, placeholdersOf } from './catalog.ts';
import { AREAS, CATALOGUE } from './messages/index.ts';
import { NAV_GROUPS } from '../layouts/nav.config.ts';
import { DISCARD_TEXT, DISCARD_TITLE } from '../utils/unsavedChanges.ts';

const entries = Object.entries(CATALOGUE) as [string, { en: string; so: string; review: string; id?: string }][];

// ------------------------------------------------------------------ structure

test('every key starts with a known category and has a name', () => {
  for (const [key] of entries) {
    assert.ok((CATEGORIES as readonly string[]).includes(categoryOf(key)), `${key}: unknown category`);
    assert.ok(key.split('.').length >= 2 && key.split('.').every((part) => part !== ''), `${key}: needs <category>.<name>`);
  }
});

test('no key appears in two areas (a duplicate would silently override the earlier one)', () => {
  const seen = new Map<string, string>();
  for (const [area, messages] of Object.entries(AREAS)) {
    for (const key of Object.keys(messages)) {
      assert.ok(!seen.has(key), `${key} is in both "${seen.get(key)}" and "${area}"`);
      seen.set(key, area);
    }
  }
  assert.equal(seen.size, entries.length);
});

test('every message has an English text and a Somali text, and the two use exactly the same {placeholders}', () => {
  for (const [key, entry] of entries) {
    assert.ok(entry.en.trim() !== '', `${key}: English is empty`);
    assert.ok(entry.so.trim() !== '', `${key}: Somali is empty (every message must have its Somali text)`);
    assert.deepEqual(placeholdersOf(entry.so), placeholdersOf(entry.en), `${key}: placeholders differ between English and Somali`);
  }
});

test('plural forms come in pairs: every .one has an .other', () => {
  for (const [key] of entries) {
    if (key.endsWith('.one')) assert.ok(CATALOGUE[key.replace(/\.one$/, '.other') as keyof typeof CATALOGUE], `${key} has no .other form`);
  }
});

test('every owner-approved or owner-supplied message carries its review-list id', () => {
  for (const [key, entry] of entries) {
    if (entry.review === 'approved' || entry.review === 'owner-term') assert.match(entry.id ?? '', /^(HR-(NAV|STS|STG|ACT|OPT)-\d{3}[a-z]?|OWN-U1-\d{3})$/, `${key}: missing or malformed id`);
  }
});

// ------------------------------------------------------------------ the owner-approved wording, restated independently of the catalogue files

const APPROVED_SOMALI: Record<string, string> = {
  // Batch 1: navigation (owner wording where the owner supplied it)
  'nav.hrGroup': 'Maamulka shaqaalaha',
  'nav.registration': 'Diiwaangelinta Shaqaalaha',
  'nav.employees': 'Shaqaalaha',
  'nav.damiinNeeded': 'Damiin Loo Baahan Yahay',
  'nav.contractPending': 'Hishiiska Shaqaalaha',
  'nav.uniformPending': 'Uniformka laga sugayo',
  'nav.needTraining': 'Ubaahan Tababar',
  'nav.needPractical': 'Ubaahan Baraatiko',
  'nav.practicalRepeat': 'KU celis Baraatiko',
  'nav.rejected': 'Dadka la kansalay',
  'nav.rejectedHistorical': 'Dadka mar hore la kansala',
  'nav.waiting': 'Shaqa sugayaal',
  'nav.workforceRequests': 'Codsiyada Shaqaalaha',
  'nav.trainingBatches': 'Kooxaha Tababarka isla qadanayo',
  'nav.practicalBatches': 'kooxaha Baraatikada isla qaadanayo',
  'nav.temporaryReplacements': 'Beddelaad Ku-meel-gaar ah',
  'nav.formerEmployees': 'Shaqaalah hore u shaqeyn jiray',
  'nav.supervisorPool': 'Kooxda Kormeerayaasha',
  'nav.officeStaff': 'Shaqaalaha Xafiiska',
  'nav.attendance': 'Xaadirinta',
  'nav.departments': 'Waaxaha',
  'nav.positions': 'Jagooyinka',
  'nav.categories': 'Qaybaha Shaqaalaha',
  'nav.companiesWorkplaces': 'Shirkadaha & Goobaha Shaqada',
  'nav.facilityInspections': 'Kormeerka Xarumaha',
  'nav.complaints': 'Cabashooyin',
  'nav.payroll': 'Mushaharka',
  'nav.excelImports': 'Soo Dejinta Excel',
  'nav.reports': 'Warbixinno',
  'nav.waitingAnalytics': 'Falanqaynta Sugitaanka',
  'nav.workforceRequestHistory': 'Taariikhda Codsiyada Shaqaalaha',
  'nav.temporaryReplacementHistory': 'Taariikhda Beddelaadaha Ku-meel-gaarka',
  'nav.leaveReport': 'Warbixinta Fasaxa',
  'nav.performanceReport': 'Warbixinta Waxqabadka',
  'nav.financialLedger': 'Diiwaanka Maaliyadeed',
  'nav.payrollRollup': 'Isku-soo-koobka Mushaharka',
  // Batch 1: statuses and the move-to-stage wording
  'status.applicant': 'Codsade',
  'status.recruitment': 'Qorista',
  'status.practical': 'Baraatiko',
  'status.approved': 'La Ansixiyay',
  'status.active': 'Shaqeynaya',
  'status.inactive': 'Aan Shaqeynayn',
  'status.stageMove.need_training': 'Ubaahan Tababar',
  'status.stageMove.need_practical': 'Ubaahan Baraatiko',
  'status.stageMove.waiting': 'Liiska Sugitaanka',
  // Batch 2A
  'action.registerEmployee': 'Diiwaangeli Shaqaale',
  'action.editEmployee': 'Wax ka beddel Shaqaalaha',
  'action.saveChanges': 'Keydi Isbeddellada',
  'action.moveToStage': 'U wareeji marxalad',
  'action.markInactive': 'Calaamadi inaanu shaqaynayn',
  'action.rehire': 'Dib u shaqaalaysii',
  'action.restoreEmployee': 'Soo celi Shaqaalaha',
  'action.save': 'Keydi',
  'action.verify': 'Hubi',
  'action.issueContract': 'Soo saar Hishiis',
  'action.markSigned': 'Calaamadi in la saxiixay',
  'action.confirmUniform': 'Xaqiiji Uniformka',
  'action.decisionApproved': 'La Ansixiyay',
  'action.decisionKuCelis': 'KU celis Baraatiko',
  'action.decisionRejected': 'La Kansalay',
  'action.addParticipant': 'Ku dar',
  'action.markBatchCompleted': 'Calaamadi in Kooxdu Dhammaatay',
  'action.newWorkforceRequest': 'Codsi Shaqaale Cusub',
  'action.confirmMatch': 'Xaqiiji Doorashada',
  'action.cancelRequest': 'Jooji Codsiga',
  // Batch 2B (the owner text wrote the variable as {Xaalad}; the variable name is kept as {status})
  'action.advanceTo': 'U gudub {status}',
  'action.addToSupervisorPool': 'Ku dar Kooxda Kormeerayaasha',
  'action.removeFromSupervisorPool': 'Ka saar Kooxda Kormeerayaasha',
  'action.overrideStatus': 'Beddel Xaaladda',
  'action.markPurchased': 'Calaamadi in la iibsaday',
  'action.markReceived': 'Calaamadi in la helay',
  'action.createTrainingBatch': 'Samee Koox Tababar',
  'action.createPracticalBatch': 'Samee Koox Baraatiko',
  'action.absent': 'Maqan',
  'action.viewCandidates': 'Eeg Musharaxiinta',
  'action.setWorkplace': 'Dooro Goobta Shaqada',
  'action.assign': 'U qoondee Shirkad',
  'action.assignBoth': 'Haa, labadaba u qoondee',
  'action.endAssignment': 'Soo Afjar Qoondaynta',
  'action.addEmployee': 'Ku dar shaqaale',
  'action.replace': 'Beddel',
  'action.reviewReplacement': 'Dib u eeg Beddelka',
  'action.confirmReplacement': 'Xaqiiji Beddelka',
  'action.returnOriginal': 'Dib ugu celi shaqaalihii asalka ahaa',
  'action.confirmReturn': 'Xaqiiji Soo celinta',
  // Batch 2E
  'action.stop': 'Jooji',
  'action.continue': 'Sii wad',
  'action.stopYes': 'Haa, jooji qoondaynta',
  'action.recordLeave': 'Diiwaangeli Fasax',
  'action.approve': 'Ansixi',
  'action.rejectLeave': 'Diid',
  'action.addReview': 'Ku dar Dib-u-eegis Waxqabad',
  'action.recordPayment': 'Diiwaangeli Lacag-bixin',
  'action.recordPenalty': 'Diiwaangeli Ganaax',
  'action.recordAdvance': 'Lacag hormaris',
  'action.newTemporaryReplacement': 'Beddelaad Ku-meel-gaar Cusub',
  'action.recordDays': 'Diiwaangeli maalmaha',
  'action.recordAndVerify': 'Diiwaangeli oo Hubi',
  'action.verifyWorkday': 'Hubi',
  'action.void': 'Ka noqo',
  'action.voidDay': 'Ka noqo',
  'action.approveCost': 'Ansixi Kharashka',
  'action.approveReplacementCost': 'Ansixi Kharashka',
  'action.endCoverage': 'Soo Afjar Beddelaadka',
  'action.generateDraft': 'Samee / Cusboonaysii Qabyo',
  'action.markReviewed': 'Calaamadi Dib loo eegay',
  'action.approvePayrollButton': 'Ansixi',
  'action.approvePayroll': 'Ansixi Mushaharka',
  'action.finalize': 'Dhammaystir',
  'action.finalizePayroll': 'Dhammaystir Mushaharka',
  'action.reopen': 'Dib u fur',
  'action.reopenPayroll': 'Dib u fur',
  'action.markPaid': 'Calaamadi in la bixiyey',
  'action.markAsPaid': 'Calaamadi in la bixiyey',
  'action.voidPayment': 'Ka noqo Lacag-bixinta',
  'action.recordAdjustment': 'Diiwaangeli Hagaajin',
  'action.checkFile': 'Hubi faylka oo muuji horu-aragga',
  // Owner rule 2026-10-22: Excel imports use "Soo geli Excel" and keep the row count (this replaces the earlier "Soo geli {n} safaf oo diyaar ah").
  'action.importRows.one': 'Soo geli Excel (safafka diyaarka ah: {n})',
  'action.importRows.other': 'Soo geli Excel (safafka diyaarka ah: {n})',
  'action.importYes': 'Haa, soo geli',
  'action.cancelImport': 'Jooji soo dejinta',
  'action.continueImport': 'Sii wad soo dejinta',
  'action.cancel': 'Jooji',
  'action.back': 'Dib u noqo',
  // Batch 2C
  'option.separation.resigned': 'Iska casilay',
  'option.separation.terminated': 'La eryay',
  'option.separation.contract_ended': 'Heshiiskii wuu dhammaaday',
  'option.separation.other': 'Kale',
  'option.rehireEligible': 'Mustaqbalka dib loogu shaqaalaysiin karo',
  'option.employmentType.permanent': 'Joogto',
  'option.employmentType.temporary': 'Ku-meel-gaar',
  'option.gender.female': 'Dheddig',
  'option.gender.male': 'Lab',
  'option.assignmentType.permanent': 'Joogto',
  'option.assignmentType.replacement': 'Beddelaad',
  'option.shift.day': 'Shaqada Maalinta',
  'option.shift.night': 'Shaqada Habeenka',
  'option.frequency.monthly': 'Bille',
  'option.frequency.weekly': 'Toddobaadle',
  'option.frequency.daily': 'Maalinle',
  'option.companyCategory.company': 'Shirkad',
  'option.companyCategory.guri': 'Guri',
  'option.leaveType.annual': 'Fasax sannadle',
  'option.leaveType.sick': 'Fasax jirro',
  // Batch 2D (Reviewed carries the owner's exact correction)
  'option.leaveType.unpaid': 'Fasax aan mushahar lahayn',
  'option.leaveType.other': 'Fasax kale',
  'option.attendance.present': 'Joogay',
  'option.attendance.late': 'Daahay',
  'option.attendance.absent': 'Maqnaa',
  'option.importRow.all': 'Dhammaan safafka',
  'option.importRow.valid': 'Diyaar',
  'option.importRow.review': 'U baahan dib-u-eegis',
  'option.importRow.duplicate': 'Hore ayay u jiraan',
  'option.importRow.invalid': 'Aan sax ahayn',
  'option.importRow.imported': 'La soo geliyey',
  'option.importRow.skipped': 'La dhaafay',
  'option.importRow.failed': 'Fashilmay',
  'option.payrollStatus.draft': 'Qabyo',
  'option.payrollStatus.reviewed': 'Dib u eegay',
  'option.payrollStatus.approved': 'La ansixiyey',
  'option.payrollStatus.finalized': 'La dhammaystiray',
  'option.chooseStage': 'Dooro marxalad',
  'option.chooseStatus': 'Dooro xaalad',
  'option.selectCompany': 'Dooro shirkad',
  'option.workplaceNotSet': 'Goobta shaqada weli lama dooran',
  'option.practicalBatchOptional': 'Koox Baraatiko (ikhtiyaari ah)',
  'option.addEligibleEmployee': 'Ku dar shaqaale u qalma…',
  'option.currentStage': 'Marxaladda hadda',
  'option.moveTo': 'U wareeji',
  'option.reasonRequired': 'Sabab (waajib)',
  'option.changeFrom': 'Ka beddel',
  'option.changeTo': 'U beddel',
};

test('every owner-approved Somali text is in the catalogue exactly as the owner wrote it', () => {
  for (const [key, somali] of Object.entries(APPROVED_SOMALI)) {
    const entry = CATALOGUE[key as keyof typeof CATALOGUE];
    assert.ok(entry, `${key} is missing from the catalogue`);
    assert.equal(entry.so, somali, key);
    assert.notEqual(entry.review, 'proposed', `${key} must be marked approved or owner-term`);
  }
});

test('the owner wording whose review is still open is marked owner-term, not final', () => {
  const open = [
    'nav.hrGroup', 'nav.contractPending', 'nav.uniformPending', 'nav.needTraining', 'nav.needPractical', 'nav.practicalRepeat', 'nav.rejected',
    'nav.rejectedHistorical', 'nav.waiting', 'nav.trainingBatches', 'nav.practicalBatches', 'nav.formerEmployees',
  ] as const;
  for (const key of open) assert.equal(CATALOGUE[key].review, 'owner-term', key);
});

test('Dashboard stays English: it has no entry and no key on the sidebar', () => {
  for (const [key, entry] of entries) assert.notEqual(entry.en, 'Dashboard', `${key} translates Dashboard`);
  const hr = NAV_GROUPS.find((group) => group.label === 'Human Resources')!;
  const dashboard = hr.items.find((item) => item.label === 'Dashboard')!;
  assert.equal(dashboard.labelKey, undefined);
});

test('the stage labels keyed by stored value repeat the sidebar wording exactly', () => {
  const pairs: [string, string][] = [
    ['status.stage.damiin_needed', 'nav.damiinNeeded'],
    ['status.stage.contract_pending', 'nav.contractPending'],
    ['status.stage.uniform_pending', 'nav.uniformPending'],
    ['status.stage.need_training', 'nav.needTraining'],
    ['status.stage.need_practical', 'nav.needPractical'],
    ['status.stage.practical_repeat', 'nav.practicalRepeat'],
    ['status.stage.rejected', 'nav.rejected'],
    ['status.waiting', 'nav.waiting'],
  ];
  for (const [stage, nav] of pairs) {
    const a = CATALOGUE[stage as keyof typeof CATALOGUE];
    const b = CATALOGUE[nav as keyof typeof CATALOGUE];
    assert.equal(a.en, b.en, stage);
    assert.equal(a.so, b.so, stage);
  }
});

// ------------------------------------------------------------------ the sidebar: every group is translatable, and its English did not change

test('every sidebar group and item that has a key keeps its English label in the catalogue; only Dashboard, XARUN, PROJECT and CMS stay as written', () => {
  const asWritten = new Set(['Dashboard', 'XARUN', 'PROJECT', 'CMS']);
  for (const group of NAV_GROUPS) {
    assert.ok(group.labelKey, `${group.label} group has no key`);
    assert.equal(CATALOGUE[group.labelKey].en, group.label);
    for (const item of group.items) {
      if (asWritten.has(item.label)) {
        assert.equal(item.labelKey, undefined, `${group.label} / ${item.label} stays as written`);
        continue;
      }
      assert.ok(item.labelKey, `${group.label} / ${item.label} has no key`);
      assert.equal(CATALOGUE[item.labelKey].en, item.label, `${group.label} / ${item.label}`);
    }
  }
  const hr = NAV_GROUPS.find((group) => group.label === 'Human Resources')!;
  const untranslated = hr.items.filter((item) => !item.labelKey).map((item) => item.label);
  assert.deepEqual(untranslated, ['Dashboard'], 'in Human Resources only Dashboard stays English; Facility Report has proposed Somali (it was never on the owner review list)');
});

// ------------------------------------------------------------------ the shared dialogs keep their English text

test('the shared unsaved-changes dialog says exactly what it said before', () => {
  assert.equal(CATALOGUE['confirm.discard.title'].en, DISCARD_TITLE);
  assert.equal(CATALOGUE['confirm.discard.text'].en, DISCARD_TEXT);
  assert.equal(CATALOGUE['action.cancel'].en, 'Cancel');
  assert.equal(CATALOGUE['action.confirm'].en, 'Confirm');
});
