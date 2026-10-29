// Pure helpers for finding lines in a payroll run (no React, no `@/` imports) so Node's test runner can import them. Display only: nothing here
// changes a payroll line, a total or a payment.

export type PayrollPaymentFilter = '' | 'paid' | 'unpaid';

interface FilterableLine {
  employee_name: string | null;
  employee_number: string | null;
  company_name: string | null;
  workplace_name: string | null;
  payment_status: string;
}

/** Lower case, single spaces: "  Ali   HASSAN " and "ali hassan" are the same search. */
export function normaliseSearch(value: string): string {
  return value.replace(/\s+/g, ' ').trim().toLowerCase();
}

/** The lines that match every word typed (in the employee's name or number, or the company or workplace) AND the payment filter. */
export function filterPayrollLines<T extends FilterableLine>(lines: readonly T[], search: string, payment: PayrollPaymentFilter): T[] {
  const words = normaliseSearch(search).split(' ').filter(Boolean);

  return lines.filter((line) => {
    if (payment !== '' && (payment === 'paid') !== (line.payment_status === 'paid')) return false;
    if (words.length === 0) return true;

    const haystack = normaliseSearch([line.employee_name, line.employee_number, line.company_name, line.workplace_name].filter(Boolean).join(' '));

    return words.every((word) => haystack.includes(word));
  });
}
