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
