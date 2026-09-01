import { Navigate, Route, Routes } from 'react-router-dom';

import { ProtectedRoute } from '@/features/auth/ProtectedRoute';
import { LoginPage } from '@/features/auth/LoginPage';
import { AppLayout } from '@/layouts/AppLayout';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { CustomersListPage } from '@/features/customers/CustomersListPage';
import { CustomerDetailPage } from '@/features/customers/CustomerDetailPage';
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
