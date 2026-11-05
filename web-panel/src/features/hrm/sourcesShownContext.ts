import { createContext } from 'react';

/**
 * True inside a Review / Finalize dialog that already shows the changes the server named when it refused (409 PAYROLL_SOURCES_CHANGED), so the warnings
 * panel under it does not list the same changes a second time.
 */
export const SourcesShownContext = createContext(false);
