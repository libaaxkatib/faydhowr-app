import { Navigate, Route, Routes } from 'react-router-dom';

import { ProtectedRoute } from '@/features/auth/ProtectedRoute';
import { RequirePermission } from '@/features/auth/RequirePermission';
import { LoginPage } from '@/features/auth/LoginPage';
import { AppLayout } from '@/layouts/AppLayout';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { CustomersListPage } from '@/features/customers/CustomersListPage';
import { CustomerDetailPage } from '@/features/customers/CustomerDetailPage';
import { HrDashboardPage } from '@/features/hrm/HrDashboardPage';
import { EmployeesListPage } from '@/features/hrm/EmployeesListPage';
import { OfficeStaffRegistrationPage } from '@/features/hrm/OfficeStaffRegistrationPage';
import { EmployeeDetailPage } from '@/features/hrm/EmployeeDetailPage';
import { DepartmentsPage } from '@/features/hrm/DepartmentsPage';
import { PositionsPage } from '@/features/hrm/PositionsPage';
import { CategoriesPage } from '@/features/hrm/CategoriesPage';
import { ClientCompaniesPage } from '@/features/hrm/ClientCompaniesPage';
import { CompanyEmployeesPage } from '@/features/hrm/CompanyEmployeesPage';
import { HrReportsPage } from '@/features/hrm/HrReportsPage';
import { TrainingBatchesPage } from '@/features/hrm/TrainingBatchesPage';
import { PracticalBatchesPage } from '@/features/hrm/PracticalBatchesPage';
import { WaitingQueuePage } from '@/features/hrm/WaitingQueuePage';
import { WorkforceRequestsPage } from '@/features/hrm/WorkforceRequestsPage';
import { TemporaryReplacementsPage } from '@/features/hrm/TemporaryReplacementsPage';
import { FormerEmployeesPage } from '@/features/hrm/FormerEmployeesPage';
import { AttendancePage } from '@/features/hrm/AttendancePage';
import { WaitingAnalyticsPage } from '@/features/hrm/WaitingAnalyticsPage';
import { WorkforceRequestHistoryPage } from '@/features/hrm/WorkforceRequestHistoryPage';
import { TemporaryReplacementHistoryPage } from '@/features/hrm/TemporaryReplacementHistoryPage';
import { LeaveReportPage } from '@/features/hrm/LeaveReportPage';
import { PerformanceReportPage } from '@/features/hrm/PerformanceReportPage';
import { FinancialLedgerPage } from '@/features/hrm/FinancialLedgerPage';
import { PayrollRollupPage } from '@/features/hrm/PayrollRollupPage';
import { PayrollRunsPage } from '@/features/hrm/PayrollRunsPage';
import { ExcelImportsPage } from '@/features/hrm/ExcelImportsPage';
import { FacilityInspectionsPage } from '@/features/hrm/FacilityInspectionsPage';
import { FacilityComplaintsPage } from '@/features/hrm/FacilityComplaintsPage';
import { FacilityReportPage } from '@/features/hrm/FacilityReportPage';
import { MarketingDashboardPage } from '@/features/marketing/MarketingDashboardPage';
import { MarketingRecordsListPage } from '@/features/marketing/MarketingRecordsListPage';
import { MarketingRecordDetailPage } from '@/features/marketing/MarketingRecordDetailPage';
import { FollowUpsPage } from '@/features/marketing/FollowUpsPage';
import { TeamsPage } from '@/features/marketing/TeamsPage';
import { MarketingEmployeesPage } from '@/features/marketing/MarketingEmployeesPage';
import { CommissionPage } from '@/features/marketing/CommissionPage';
import { MarketingReportsPage } from '@/features/marketing/MarketingReportsPage';
import { AdminUsersPage } from '@/features/system/AdminUsersPage';
import { RolesPermissionsPage } from '@/features/system/RolesPermissionsPage';
import { SettingsPage } from '@/features/system/SettingsPage';
import { AuditLogPage } from '@/features/system/AuditLogPage';
import { TranslationsPage } from '@/features/system/TranslationsPage';
import { RequireSuperAdmin } from '@/features/auth/RequireSuperAdmin';
import { ReconciliationListPage } from '@/features/system/ReconciliationListPage';
import { ReconciliationDetailPage } from '@/features/system/ReconciliationDetailPage';
import { PageState } from '@/components/ui/PageState';
import { useI18n } from '@/i18n/useI18n';

export default function App() {
  const { t } = useI18n();

  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route
        element={
          <ProtectedRoute>
            <AppLayout />
          </ProtectedRoute>
        }
      >
        {/* No permission guard: every authenticated admin, of any role, sees this
            page — matches the backend's 'dashboard' module, which has permissions: null. */}
        <Route path="/dashboard" element={<DashboardPage />} />

        <Route path="/mobile-app/customers" element={<RequirePermission permission="customers.view"><CustomersListPage /></RequirePermission>} />
        <Route path="/mobile-app/customers/:id" element={<RequirePermission permission="customers.view"><CustomerDetailPage /></RequirePermission>} />

        <Route path="/hr" element={<RequirePermission permission="hr.dashboard.view"><HrDashboardPage /></RequirePermission>} />
        <Route path="/hr/employees" element={<RequirePermission permission="hr.view"><EmployeesListPage /></RequirePermission>} />
        <Route path="/hr/employees/:id" element={<RequirePermission permission="hr.view"><EmployeeDetailPage /></RequirePermission>} />
        <Route
          path="/hr/pipeline/damiin"
          element={
            <RequirePermission permission="hr.view">
              <EmployeesListPage filterDamiinActive title="Damiin Needed" breadcrumbLabel="Damiin Needed" />
            </RequirePermission>
          }
        />
        <Route
          path="/hr/pipeline/contracts"
          element={
            <RequirePermission permission="hr.view">
              <EmployeesListPage fixedPipelineStage="contract_pending" title="Contract Pending" breadcrumbLabel="Contract Pending" />
            </RequirePermission>
          }
        />
        <Route
          path="/hr/pipeline/uniform"
          element={
            <RequirePermission permission="hr.view">
              <EmployeesListPage fixedPipelineStage="uniform_pending" title="Uniform Pending" breadcrumbLabel="Uniform Pending" />
            </RequirePermission>
          }
        />
        <Route
          path="/hr/pipeline/training"
          element={
            <RequirePermission permission="hr.view">
              <EmployeesListPage fixedPipelineStage="need_training" title="Need Training" breadcrumbLabel="Need Training" />
            </RequirePermission>
          }
        />
        <Route
          path="/hr/pipeline/practical"
          element={
            <RequirePermission permission="hr.view">
              <EmployeesListPage fixedPipelineStage="need_practical" title="Need Practical" breadcrumbLabel="Need Practical" />
            </RequirePermission>
          }
        />
        <Route
          path="/hr/pipeline/practical-repeat"
          element={
            <RequirePermission permission="hr.view">
              <EmployeesListPage fixedPipelineStage="practical_repeat" title="Practical Repeat" breadcrumbLabel="Practical Repeat" />
            </RequirePermission>
          }
        />
        <Route
          path="/hr/pipeline/rejected"
          element={
            <RequirePermission permission="hr.view">
              <EmployeesListPage fixedPipelineStage="rejected" title="Rejected" breadcrumbLabel="Rejected" />
            </RequirePermission>
          }
        />
        <Route
          path="/hr/pipeline/rejected-historical"
          element={
            <RequirePermission permission="hr.view">
              <EmployeesListPage filterHistoricalRejected title="Rejected (Historical)" breadcrumbLabel="Rejected (Historical)" />
            </RequirePermission>
          }
        />
        <Route path="/hr/waiting" element={<RequirePermission permission="hr.view"><WaitingQueuePage /></RequirePermission>} />
        <Route path="/hr/workforce-requests" element={<RequirePermission permission="hr.view"><WorkforceRequestsPage /></RequirePermission>} />
        <Route path="/hr/training-batches" element={<RequirePermission permission="hr.view"><TrainingBatchesPage /></RequirePermission>} />
        <Route path="/hr/practical-batches" element={<RequirePermission permission="hr.view"><PracticalBatchesPage /></RequirePermission>} />
        <Route path="/hr/temporary-replacements" element={<RequirePermission permission="hr.view"><TemporaryReplacementsPage /></RequirePermission>} />
        <Route path="/hr/former-employees" element={<RequirePermission permission="hr.view"><FormerEmployeesPage /></RequirePermission>} />
        <Route
          path="/hr/supervisor-pool"
          element={
            <RequirePermission permission="hr.view">
              <EmployeesListPage filterIsSupervisor title="Supervisor Pool" breadcrumbLabel="Supervisor Pool" />
            </RequirePermission>
          }
        />
        <Route
          path="/hr/office-staff"
          element={
            <RequirePermission permission="hr.view">
              <EmployeesListPage filterOfficeOnly title="Office Staff" breadcrumbLabel="Office Staff" />
            </RequirePermission>
          }
        />
        <Route path="/hr/office-staff/new" element={<RequirePermission permission="hr.manage"><OfficeStaffRegistrationPage /></RequirePermission>} />
        <Route path="/hr/attendance" element={<RequirePermission permission="hr.view"><AttendancePage /></RequirePermission>} />
        <Route path="/hr/departments" element={<RequirePermission permission="hr.view"><DepartmentsPage /></RequirePermission>} />
        <Route path="/hr/positions" element={<RequirePermission permission="hr.view"><PositionsPage /></RequirePermission>} />
        <Route path="/hr/categories" element={<RequirePermission permission="hr.view"><CategoriesPage /></RequirePermission>} />
        <Route path="/hr/companies" element={<RequirePermission permission="hr.view"><ClientCompaniesPage /></RequirePermission>} />
        <Route path="/hr/companies/:id/employees" element={<RequirePermission permission="hr.view"><CompanyEmployeesPage /></RequirePermission>} />
        <Route path="/hr/facility-inspections" element={<RequirePermission permission="hr.inspections.view"><FacilityInspectionsPage /></RequirePermission>} />
        <Route path="/hr/facility-complaints" element={<RequirePermission permission="hr.inspections.view"><FacilityComplaintsPage /></RequirePermission>} />
        <Route path="/hr/payroll" element={<RequirePermission permission="hr.payroll.view"><PayrollRunsPage /></RequirePermission>} />
        <Route path="/hr/imports" element={<RequirePermission permission="hr.import"><ExcelImportsPage /></RequirePermission>} />
        <Route path="/hr/reports" element={<RequirePermission permission="hr.reports.view"><HrReportsPage /></RequirePermission>} />
        <Route path="/hr/reports/waiting-analytics" element={<RequirePermission permission="hr.reports.view"><WaitingAnalyticsPage /></RequirePermission>} />
        <Route path="/hr/reports/workforce-requests" element={<RequirePermission permission="hr.reports.view"><WorkforceRequestHistoryPage /></RequirePermission>} />
        <Route path="/hr/reports/temporary-replacements" element={<RequirePermission permission="hr.reports.view"><TemporaryReplacementHistoryPage /></RequirePermission>} />
        <Route path="/hr/reports/leaves" element={<RequirePermission permission="hr.reports.view"><LeaveReportPage /></RequirePermission>} />
        <Route path="/hr/reports/performance-reviews" element={<RequirePermission permission="hr.reports.view"><PerformanceReportPage /></RequirePermission>} />
        <Route path="/hr/reports/financial-ledger" element={<RequirePermission permission="hr.reports.view"><FinancialLedgerPage /></RequirePermission>} />
        <Route path="/hr/reports/facility" element={<RequirePermission permission="hr.reports.view"><RequirePermission permission="hr.inspections.view"><FacilityReportPage /></RequirePermission></RequirePermission>} />
        <Route path="/hr/reports/payroll-rollup" element={<RequirePermission permission="hr.reports.view"><PayrollRollupPage /></RequirePermission>} />

        <Route path="/marketing" element={<RequirePermission permission="marketing.view"><MarketingDashboardPage /></RequirePermission>} />
        <Route
          path="/marketing/xarun"
          element={
            <RequirePermission permission="marketing.view">
              <MarketingRecordsListPage type="xarun" title="XARUN" />
            </RequirePermission>
          }
        />
        <Route path="/marketing/xarun/:id" element={<RequirePermission permission="marketing.view"><MarketingRecordDetailPage /></RequirePermission>} />
        <Route
          path="/marketing/project"
          element={
            <RequirePermission permission="marketing.view">
              <MarketingRecordsListPage type="project" title="PROJECT" />
            </RequirePermission>
          }
        />
        <Route path="/marketing/project/:id" element={<RequirePermission permission="marketing.view"><MarketingRecordDetailPage /></RequirePermission>} />
        <Route path="/marketing/follow-ups" element={<RequirePermission permission="marketing.view"><FollowUpsPage /></RequirePermission>} />
        <Route path="/marketing/teams" element={<RequirePermission permission="marketing.view"><TeamsPage /></RequirePermission>} />
        <Route path="/marketing/employees" element={<RequirePermission permission="marketing.view"><MarketingEmployeesPage /></RequirePermission>} />
        <Route path="/marketing/commission" element={<RequirePermission permission="marketing.commission.view"><CommissionPage /></RequirePermission>} />
        <Route path="/marketing/reports" element={<RequirePermission permission="marketing.reports.view"><MarketingReportsPage /></RequirePermission>} />

        <Route path="/system/admins" element={<RequirePermission permission="admins.manage"><AdminUsersPage /></RequirePermission>} />
        <Route path="/system/roles" element={<RequirePermission permission="roles.manage"><RolesPermissionsPage /></RequirePermission>} />
        <Route path="/system/settings" element={<RequirePermission permission="settings.view"><SettingsPage /></RequirePermission>} />
        <Route path="/system/translations" element={<RequireSuperAdmin><TranslationsPage /></RequireSuperAdmin>} />
        <Route path="/system/audit-log" element={<RequirePermission permission="admins.manage"><AuditLogPage /></RequirePermission>} />
        <Route path="/system/data-issues" element={<RequirePermission permission="reconciliation.view"><ReconciliationListPage /></RequirePermission>} />
        <Route path="/system/data-issues/:id" element={<RequirePermission permission="reconciliation.view"><ReconciliationDetailPage /></RequirePermission>} />

        <Route
          path="*"
          element={
            <PageState
              icon="alert-triangle"
              title={t('warning.shell.notBuiltTitle')}
              description={t('warning.shell.notBuiltText')}
            />
          }
        />
      </Route>

      <Route path="/" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  );
}
