import { useQuery } from '@tanstack/react-query';

import { hrApi } from '@/api/hr';
import { companySegment } from '@/utils/payrollOverview';

/** The one query that reads a company's warnings: shared by the warnings panel and by the dialog that turns its confirm button off while a stale-draft notice is shown. */
export function useCompanyWarnings(period: string, company: number | null) {
  return useQuery({
    queryKey: ['payroll-warnings', period, companySegment(company)],
    queryFn: () => hrApi.payrollRuns.companyWarnings(period, company),
    // Always the current picture: a warning list that was true a minute ago may not be true now.
    staleTime: 0,
    gcTime: 0,
  });
}
