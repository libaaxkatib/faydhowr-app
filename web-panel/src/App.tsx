import { Navigate, Route, Routes } from 'react-router-dom';

import { ProtectedRoute } from '@/features/auth/ProtectedRoute';
import { LoginPage } from '@/features/auth/LoginPage';
import { AppLayout } from '@/layouts/AppLayout';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { CustomersListPage } from '@/features/customers/CustomersListPage';
import { CustomerDetailPage } from '@/features/customers/CustomerDetailPage';
import { HrDashboardPage } from '@/features/hrm/HrDashboardPage';
import { EmployeesListPage } from '@/features/hrm/EmployeesListPage';
import { EmployeeDetailPage } from '@/features/hrm/EmployeeDetailPage';
import { DepartmentsPage } from '@/features/hrm/DepartmentsPage';
import { PositionsPage } from '@/features/hrm/PositionsPage';
import { ClientCompaniesPage } from '@/features/hrm/ClientCompaniesPage';
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
import { MarketingDashboardPage } from '@/features/marketing/MarketingDashboardPage';
import { MarketingRecordsListPage } from '@/features/marketing/MarketingRecordsListPage';
import { MarketingRecordDetailPage } from '@/features/marketing/MarketingRecordDetailPage';
import { FollowUpsPage } from '@/features/marketing/FollowUpsPage';
import { TeamsPage } from '@/features/marketing/TeamsPage';
import { MarketingEmployeesPage } from '@/features/marketing/MarketingEmployeesPage';
import { CommissionPage } from '@/features/marketing/CommissionPage';
import { MarketingReportsPage } from '@/features/marketing/MarketingReportsPage';
import { PageState } from '@/components/ui/PageState';

export default function App() {
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
        <Route path="/dashboard" element={<DashboardPage />} />

        <Route path="/mobile-app/customers" element={<CustomersListPage />} />
        <Route path="/mobile-app/customers/:id" element={<CustomerDetailPage />} />

        <Route path="/hr" element={<HrDashboardPage />} />
        <Route path="/hr/employees" element={<EmployeesListPage />} />
        <Route path="/hr/employees/:id" element={<EmployeeDetailPage />} />
        <Route
          path="/hr/pipeline/damiin"
          element={<EmployeesListPage fixedPipelineStage="damiin_needed" title="Damiin Needed" breadcrumbLabel="Damiin Needed" />}
        />
        <Route
          path="/hr/pipeline/contracts"
          element={<EmployeesListPage fixedPipelineStage="contract_pending" title="Contract Pending" breadcrumbLabel="Contract Pending" />}
        />
        <Route
          path="/hr/pipeline/uniform"
          element={<EmployeesListPage fixedPipelineStage="uniform_pending" title="Uniform Pending" breadcrumbLabel="Uniform Pending" />}
        />
        <Route
          path="/hr/pipeline/training"
          element={<EmployeesListPage fixedPipelineStage="need_training" title="Need Training" breadcrumbLabel="Need Training" />}
        />
        <Route
          path="/hr/pipeline/practical"
          element={<EmployeesListPage fixedPipelineStage="need_practical" title="Need Practical" breadcrumbLabel="Need Practical" />}
        />
        <Route
          path="/hr/pipeline/practical-repeat"
          element={<EmployeesListPage fixedPipelineStage="practical_repeat" title="Practical Repeat" breadcrumbLabel="Practical Repeat" />}
        />
        <Route
          path="/hr/pipeline/rejected"
          element={<EmployeesListPage fixedPipelineStage="rejected" title="Rejected" breadcrumbLabel="Rejected" />}
        />
        <Route path="/hr/waiting" element={<WaitingQueuePage />} />
        <Route path="/hr/workforce-requests" element={<WorkforceRequestsPage />} />
        <Route path="/hr/training-batches" element={<TrainingBatchesPage />} />
        <Route path="/hr/practical-batches" element={<PracticalBatchesPage />} />
        <Route path="/hr/temporary-replacements" element={<TemporaryReplacementsPage />} />
        <Route path="/hr/former-employees" element={<FormerEmployeesPage />} />
        <Route path="/hr/supervisor-pool" element={<EmployeesListPage filterIsSupervisor title="Supervisor Pool" breadcrumbLabel="Supervisor Pool" />} />
        <Route path="/hr/office-staff" element={<EmployeesListPage filterOfficeOnly title="Office Staff" breadcrumbLabel="Office Staff" />} />
        <Route path="/hr/attendance" element={<AttendancePage />} />
        <Route path="/hr/departments" element={<DepartmentsPage />} />
        <Route path="/hr/positions" element={<PositionsPage />} />
        <Route path="/hr/companies" element={<ClientCompaniesPage />} />
        <Route path="/hr/reports" element={<HrReportsPage />} />
        <Route path="/hr/reports/waiting-analytics" element={<WaitingAnalyticsPage />} />
        <Route path="/hr/reports/workforce-requests" element={<WorkforceRequestHistoryPage />} />
        <Route path="/hr/reports/temporary-replacements" element={<TemporaryReplacementHistoryPage />} />
        <Route path="/hr/reports/leaves" element={<LeaveReportPage />} />
        <Route path="/hr/reports/performance-reviews" element={<PerformanceReportPage />} />
        <Route path="/hr/reports/financial-ledger" element={<FinancialLedgerPage />} />
        <Route path="/hr/reports/payroll-rollup" element={<PayrollRollupPage />} />

        <Route path="/marketing" element={<MarketingDashboardPage />} />
        <Route path="/marketing/xarun" element={<MarketingRecordsListPage type="xarun" title="XARUN" />} />
        <Route path="/marketing/xarun/:id" element={<MarketingRecordDetailPage />} />
        <Route path="/marketing/project" element={<MarketingRecordsListPage type="project" title="PROJECT" />} />
        <Route path="/marketing/project/:id" element={<MarketingRecordDetailPage />} />
        <Route path="/marketing/follow-ups" element={<FollowUpsPage />} />
        <Route path="/marketing/teams" element={<TeamsPage />} />
        <Route path="/marketing/employees" element={<MarketingEmployeesPage />} />
        <Route path="/marketing/commission" element={<CommissionPage />} />
        <Route path="/marketing/reports" element={<MarketingReportsPage />} />

        <Route
          path="*"
          element={
            <PageState
              icon="alert-triangle"
              title="Not built yet"
              description="This module is on the roadmap but hasn't been implemented in this phase. See the Web Panel Blueprint for the build order."
            />
          }
        />
      </Route>

      <Route path="/" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  );
}
