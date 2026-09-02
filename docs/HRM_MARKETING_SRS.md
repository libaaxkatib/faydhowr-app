# FAYADHOWR.COM

## HRM & MARKETING WEB PANEL

### Software Requirements Specification (SRS)

**Version:** 1.0

**Date:** September 2026

**Platform:** Web Application

**Primary Departments:** Marketing & Human Resources

**Domain:** faydhowr.com

---

# 1. Project Overview

Fayadhowr Web Panel waa internal business management system loogu talagalay in shirkaddu ku maamusho:

1. Marketing Department
2. Human Resource Department

System-ku wuxuu beddelayaa habka hadda ku saleysan Excel, waraaqo iyo xasuus shaqaale, wuxuuna siinayaa maamulka hal meel oo lagu maamulo shaqada, xogta, follow-up-ka, reports-ka iyo performance-ka.

Marketing-ka hadda waxaa lagu diiwaangeliyaa xog ay ka mid yihiin company, contact person, phone, location, type, project size, completion date, next visit, status iyo feedback.

---

# 2. Objectives

System-ku waa inuu:

- Yareeyo ku tiirsanaanta Excel.
- Xogta Marketing ka dhigo centralized.
- Kala saaro XARUN iyo PROJECT.
- Kala saaro shaqada Team A iyo Team B.
- Siiyo team kasta report gaar ah.
- Siiyo management-ka combined report.
- Sameeyo automatic follow-up reminders.
- La socdo leads-ka aan weli diyaar noqon.
- Maamulo quotation iyo status.
- Xisaabiyo commission-ka Marketing employees.
- Sameeyo monthly commission reports.
- Diiwaangeliyo employees.
- Maamulo HR recruitment workflow.
- Kala saaro HR iyo Marketing permissions.

---

# 3. System Structure

FAYADHOWR.COM
│
└── WEB PANEL
    │
    ├── Dashboard
    │
    ├── Marketing Department
    │   ├── Dashboard
    │   ├── XARUN
    │   ├── PROJECT
    │   ├── Follow-ups
    │   ├── Quotations
    │   ├── Teams
    │   ├── Employees
    │   ├── Commission
    │   └── Reports
    │
    └── Human Resources
        ├── HR Dashboard
        ├── Employee Registration
        ├── Employees
        ├── Recruitment
        ├── Practical
        ├── Waiting
        ├── Employee Profiles
        ├── Departments
        ├── Positions
        └── HR Reports

---

# 4. User Roles & Access

System-ku wuxuu isticmaalaa Role-Based Access Control (RBAC).

## 4.1 Super Admin

Super Admin wuxuu awood u leeyahay:

- Inuu maamulo system-ka oo dhan.
- Inuu abuuro users.
- Inuu maamulo roles.
- Inuu maamulo departments.
- Inuu arko Marketing iyo HR.
- Inuu arko dhammaan reports.
- Inuu maamulo system settings.

## 4.2 Marketing Manager

Wuxuu maamuli karaa:

- Marketing teams.
- Marketing employees.
- XARUN.
- PROJECT.
- Assignments.
- Follow-ups.
- Quotations.
- Reports.
- Commission reports.

## 4.3 Marketing Employee

Wuxuu awood u leeyahay:

- Inuu arko shaqada loo assigned gareeyay.
- Inuu diiwaangeliyo XARUN/PROJECT.
- Inuu sameeyo follow-up.
- Inuu update gareeyo feedback.
- Inuu arko reminders-kiisa.

## 4.4 HR Manager

Wuxuu maamuli karaa:

- Employee registration.
- Recruitment.
- Practical.
- Waiting.
- Employee profiles.
- Employee status.
- HR reports.

## 4.5 HR Employee

Wuxuu qaban karaa HR tasks-ka loo oggolaaday.

---

# 5. MARKETING DEPARTMENT

## 5.1 Marketing Teams

Marketing work waxaa loo qaybinayaa teams.

Initial teams:

- Team A
- Team B

System-ka waa inuu mustaqbalka oggolaadaa teams kale.

Record kasta waa inuu leeyahay:

- Team
- Marketing employee
- Date
- Type
- Status

Sidaas darteed maamulka wuxuu kala arki karaa:

Team A Report
Team B Report
All Teams Report

---

# 6. Marketing Lead Types

Marketing-ka waxaa jira laba workflow oo aan isku mid ahayn:

MARKETING

├── XARUN

└── PROJECT

XARUN iyo PROJECT ma isticmaalaan hal form oo isku mid ah.

---

# 7. XARUN Workflow

Marka marketer-ku doorto:

Type = XARUN

system-ku wuxuu soo bandhigayaa XARUN form.

## 7.1 XARUN Information

Fields-ka muhiimka ah:

- Date
- Facility / Company Name
- Manager / Contact Person
- Contact Title
- Phone Number
- Location Area
- Need / Service Required
- Description
- Feedback / What was encountered
- Next Follow-up
- Status
- Assigned Team
- Marketing Employee

---

# 8. XARUN Needs

Marketer-ku waa inuu qeexi karaa waxa xaruntu rabto.

Tusaale:

- General Cleaning
- Deep Cleaning
- Pest Control
- Tree Cutting
- Other Services

System-ku waa inuu oggolaadaa in baahiyo badan la doorto haddii xaruntu wax ka badan hal service rabto.

---

# 9. PROJECT Workflow

Marka:

Type = PROJECT

form-ku wuu ka duwan yahay XARUN.

## 9.1 Responsible Party

Company mar walba ma jiri karto.

Sidaas darteed system-ku waa inuu taageeraa:

- Construction Company
- Engineer
- Owner / Individual
- Other Responsible Person

Company Name ma aha required field.

---

# 10. PROJECT Information

Project form-ku wuxuu leeyahay:

- Date
- Responsible Party Type
- Company Name — haddii jiro
- Engineer / Responsible Person Name
- Phone
- Location
- Project Type / Building Type
- Project Size / Content Size
- Construction Completion Date
- Fayadhowr Work Date
- Description
- Feedback
- Next Follow-up
- Status
- Assigned Team
- Marketing Employee

---

# 11. Description

Description waa field muhiim ah.

Waxaan u isticmaali doonaa multiline field.

Waxaa lagu qorayaa faahfaahin aan fields-ka kale lagu qaban karin.

Description-ka waa inuu ka muuqdaa:

- Detail page
- Follow-up history
- Reports
- Employee activity

---

# 12. Feedback

Marketing employee-ku waa inuu diiwaangelin karaa:

Wixii uu kala soo kulmay customer/project-ka.

Feedback-ku waa inuu noqdaa usable field, mana aha in examples-ka loo hard-code gareeyo.

---

# 13. Marketing Status

System-ku waa inuu taageeraa status-yada hadda shaqada lagu isticmaalo:

- PENDING
- QUOTATION
- DONE
- CANCELLED

System-ku mustaqbalka waa inuu u oggolaadaa maamulka inuu ku daro status cusub haddii business-ku u baahdo.

---

# 14. Follow-up & Reminder System

Tani waa mid ka mid ah core features-ka system-ka.

System-ku waa inuusan ku ekaan:

"1 bil kadib"

oo note ahaan.

Waa inuu sameeyaa actual scheduled follow-up date.

---

## 14.1 Project Example

Marketing employee wuxuu booqday project.

Customer wuxuu yiri:

"Dhismaha hal bil kadib ayuu diyaar noqonayaa."

Employee-ku wuxuu gelinayaa:

Construction Completion:
30/09/2026

Fayadhowr Work:
05/10/2026

System-ku wuxuu abuuraa:

FOLLOW-UP
05/10/2026

Marka waqtigu gaaro:

Reminder

ayaa u muuqanaya team-ka ama employee-ka responsible-ka ah.

---

# 15. Reminder Actions

Marka reminder la gaaro employee-ku wuxuu awood u leeyahay:

- Call customer
- Visit
- WhatsApp/contact
- Add feedback
- Update status
- Set another follow-up date
- Mark follow-up completed

System-ku waa inuu hayaa Follow-up History.

---

# 16. Today's Follow-ups

Marketing Dashboard:

TODAY'S FOLLOW-UPS

Project G+3
Team A
Engineer: Ahmed
Phone: 61xxxxxxx

Reason:
Project ready

[Call]
[Update]
[Complete]
[Reschedule]

Sidoo kale:

- Today's Follow-ups
- Upcoming Follow-ups
- Overdue Follow-ups

waa in la kala arki karaa.

---

# 17. Marketing Assignment

Manager-ku wuxuu task/lead u assign gareyn karaa:

Team A
Employee Ahmed

ama:

Team B
Employee Mohamed

System-ku waa inuu kaydiyaa:

- Who assigned it
- Team
- Employee
- Date assigned
- Date completed
- Status

---

# 18. Marketing Reports

Reports-ku waa inay taageeraan:

- Team A
- Team B
- All Teams
- Individual Employee
- Daily
- Weekly
- Monthly

---

# 19. Marketing Performance

Report-ku waa inuu soo bandhigi karaa xogta Marketing-ka.

Metrics-ka waxaa ka mid noqon kara:

- Xarumaha
- Projects
- Inta qiimeyn loo diray
- Inta quotation la sameeyay
- Inta project lala heshiiyay
- Inta xarun lala heshiiyay
- Shaqooyinka dheeraadka ah
- Wadar

Excel/report structures existing in the business may be used as reference where already represented in the system, but Google Sheets are NOT the source of truth for this implementation.

---

# 20. Commission System

Marketing employee kasta waxaa loo kaydin karaa:

Employee
Commission Rate
Status

Record kasta oo Marketing ahna wuxuu leeyahay:

Brought By:
Employee

Sidaas darteed system-ku wuxuu ogaanayaa qofkii keenay XARUN ama PROJECT.

---

# 21. Monthly Commission

Marka bil dhammaato manager-ku wuxuu dooran karaa:

August 2026

System-ku wuxuu soo saarayaa employee-level commission reporting.

Commission-ka waa inuu ku xirnaadaa XARUN/PROJECT uu employee-ku keenay iyo business rule-ka la ansixiyay.

Commission calculation formula waa TBD.

Developer-ku ma qiyaasi karo formula-kan ilaa management-ku ansixiyo.

---

# 22. HUMAN RESOURCE DEPARTMENT

HR wuxuu noqonayaa department gaar ah oo gudaha isla Web Panel-ka ah.

HR

├── Dashboard
├── Employee Registration
├── Employees
├── Recruitment
├── Practical
├── Waiting
├── Employee Profiles
├── Employee Status
├── Departments
├── Positions
└── Reports

---

# 23. Employee Categories

Workflow-ga HR wuxuu taageerayaa categories-ka:

- General Cleaning
- Cooking
- Waiter
- Barista
- Home Team

Category kasta waxaa lagu xiriirin karaa position/department haddii loo baahdo.

---

# 24. Recruitment Workflow

Employee cusub ma noqonayo ACTIVE isla marka registration la sameeyo.

Workflow-ga:

APPLICATION
↓
RECRUITMENT
↓
PRACTICAL / ASSESSMENT
↓
WAITING
↓
APPROVED
↓
EMPLOYEE
↓
ACTIVE

---

# 25. Employee Registration

Employee Registration wuxuu noqonayaa database-based registration system.

Waxaa jiri doona qaybaha:

Personal Information

- Employee ID
- Full Name
- Phone
- Alternative Phone
- Address
- Date of Birth
- Gender
- Other registration information

Employment Information

- Department
- Position
- Employee Category
- Employment Status
- Joining Date

Recruitment Information

- Application Date
- Recruitment Stage
- Practical Status
- Waiting Status

Additional Information

- Description / Notes
- Documents
- Other approved registration fields

The exact registration fields must be reconciled with the employee registration source when that source is available.

Do not invent mandatory fields.

---

# 26. Employee Profile

Employee kasta wuxuu leeyahay profile gaar ah.

Employee Profile

Employee ID
Full Name
Category
Position
Department
Status

Personal Information
Employment Information
Recruitment History
Practical Result
Waiting History
Documents
Notes
Status History

---

# 27. Employee Status

HR waa inuu awood u leeyahay inuu maamulo status-ka employee-ga.

Tusaale:

- Applicant
- Recruitment
- Practical
- Waiting
- Approved
- Active
- Inactive

Status history waa in la kaydiyaa.

---

# 28. HR Dashboard

HR Dashboard wuxuu soo bandhigi karaa:

- TOTAL EMPLOYEES
- ACTIVE EMPLOYEES
- INACTIVE EMPLOYEES
- NEW APPLICANTS
- PRACTICAL
- WAITING
- APPROVED

Iyo category breakdown:

- General Cleaning
- Cooking
- Waiter
- Barista
- Home Team

---

# 29. HR Reports

HR Manager wuxuu heli karaa:

- Employee list
- Active employees
- Inactive employees
- New registrations
- Recruitment report
- Practical report
- Waiting report
- Category report
- Department report
- Monthly HR report

---

# 30. Department Separation

HR iyo Marketing xogtooda waa la kala ilaalinayaa.

SUPER ADMIN

HR
HR Users

MARKETING
Marketing Users

HR user:

Ma arki karo Marketing records haddii aan loo oggolaan.

Marketing user:

Ma arki karo employee personal records haddii aan permission loo siin.

---

# 31. Audit Trail

System-ku waa inuu kaydiyaa actions muhiim ah:

User
Action
Record
Date/Time
Old Value
New Value

---

# 32. Search & Filters

Marketing:

- Search company
- Search contact
- Search phone
- Search location
- Filter XARUN
- Filter PROJECT
- Filter Team
- Filter Employee
- Filter Status
- Filter Date
- Filter Follow-up

HR:

- Search employee
- Employee ID
- Phone
- Category
- Department
- Position
- Status
- Recruitment stage

---

# 33. Dashboard Principle

Dashboard-ku waa inuu noqdaa action-oriented.

Marketing:

TODAY
├── Follow-ups
├── New Leads
├── Quotations
└── Overdue Follow-ups

HR:

TODAY
├── New Applicants
├── Practical
├── Waiting
└── Employee Actions

---

# 34. Data Model — High Level

Potential entities include:

users
roles
permissions
departments

marketing_teams
marketing_employees

marketing_records
xarun_details
project_details

follow_ups
follow_up_history

quotations
marketing_statuses

commission_rates
commission_records

employees
employee_categories
positions

recruitment_records
practical_assessments
waiting_records

employee_documents
employee_status_history

audit_logs

IMPORTANT:

These are high-level SRS concepts.

Before creating tables, inspect the existing database.

Reuse existing entities where appropriate.

Do not duplicate existing tables.

---

# 35. Core Business Flow

Marketing:

Create Lead
↓
Choose XARUN / PROJECT
↓
Specific Form
↓
Assign Team
↓
Assign Employee
↓
Visit / Marketing Activity
↓
Feedback + Description
↓
Quotation / Status
↓
Set Follow-up
↓
Reminder
↓
Follow-up
↓
Conversion / Done
↓
Commission
↓
Monthly Report

HR:

Employee Registration
↓
Recruitment
↓
Practical
↓
Waiting
↓
Approval
↓
Employee
↓
Active / Inactive
↓
HR Reports

---

# 36. Important Design Principles

XARUN ≠ PROJECT

Applicant ≠ Employee

Lead Brought ≠ Automatically Paid Commission

Note ≠ Scheduled Follow-up

The system must reflect Fayadhowr's actual business workflow.

---

# 37. Current Marketing Data Migration

The existing Marketing data may eventually be migrated into the new system.

Potential historical fields include:

- XARUN
- PROJECT
- Contact
- Phone
- Area
- Project size
- Completion
- Next visit
- Status
- Feedback

Create the system so historical migration can be supported later.

Do not perform a destructive migration.

---

# 38. SRS Scope — Version 1

Included:

- Fayadhowr Web Panel
- Authentication
- RBAC
- Marketing Department
- Team A / Team B
- XARUN workflow
- PROJECT workflow
- Engineer/Owner/Company flexibility
- Description
- Feedback
- Assignment
- Follow-up
- Automatic reminders
- Status management
- Quotation tracking
- Team reports
- Employee reports
- Monthly reports
- Commission tracking
- HR Department
- Employee Registration
- Recruitment
- Practical
- Waiting
- Employee profiles
- Employee status
- HR reports
- Audit trail
- Search & filters

---

# 39. Requirements Not Yet Final

These must NOT be decided by the developer without approval.

Commission calculation:

The final calculation rule is still TBD.

HR Registration exact fields:

The exact fields should be reconciled with the approved employee registration source when available.

Additional Marketing statuses:

Current approved statuses:

PENDING
QUOTATION
DONE
CANCELLED

Future statuses require management approval.

---

# 40. Final Product Vision

FAYADHOWR.COM
│
WEB PANEL
│
├── MARKETING
│   ├── XARUN
│   ├── PROJECT
│   ├── Follow-up
│   ├── Quotation
│   ├── Teams
│   ├── Commission
│   └── Reports
│
└── HR
    ├── Employees
    ├── Recruitment
    ├── Practical
    ├── Waiting
    ├── Departments
    ├── Positions
    └── Reports
