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
import { HrReportsPage } from '@/features/hrm/HrReportsPage';
import { MarketingDashboardPage } from '@/features/marketing/MarketingDashboardPage';
import { MarketingRecordsListPage } from '@/features/marketing/MarketingRecordsListPage';
import { MarketingRecordDetailPage } from '@/features/marketing/MarketingRecordDetailPage';
import { FollowUpsPage } from '@/features/marketing/FollowUpsPage';
import { TeamsPage } from '@/features/marketing/TeamsPage';
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
          path="/hr/recruitment"
          element={<EmployeesListPage fixedStatus="recruitment" title="Recruitment" breadcrumbLabel="Recruitment" />}
        />
        <Route
          path="/hr/practical"
          element={<EmployeesListPage fixedStatus="practical" title="Practical" breadcrumbLabel="Practical" />}
        />
        <Route
          path="/hr/waiting"
          element={<EmployeesListPage fixedStatus="waiting" title="Waiting" breadcrumbLabel="Waiting" />}
        />
        <Route path="/hr/departments" element={<DepartmentsPage />} />
        <Route path="/hr/positions" element={<PositionsPage />} />
        <Route path="/hr/reports" element={<HrReportsPage />} />

        <Route path="/marketing" element={<MarketingDashboardPage />} />
        <Route path="/marketing/xarun" element={<MarketingRecordsListPage type="xarun" title="XARUN" />} />
        <Route path="/marketing/xarun/:id" element={<MarketingRecordDetailPage />} />
        <Route path="/marketing/project" element={<MarketingRecordsListPage type="project" title="PROJECT" />} />
        <Route path="/marketing/project/:id" element={<MarketingRecordDetailPage />} />
        <Route path="/marketing/follow-ups" element={<FollowUpsPage />} />
        <Route path="/marketing/teams" element={<TeamsPage />} />
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
