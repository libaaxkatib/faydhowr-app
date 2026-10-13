export type ImportType = 'companies' | 'employee_registrations' | 'waiting_list' | 'activation_preview';

export type ImportBatchStatus = 'staged' | 'processing' | 'completed' | 'cancelled';

export type ImportRowStatus = 'valid' | 'invalid' | 'duplicate' | 'review' | 'imported' | 'skipped' | 'failed';

export interface ImportCounts {
  total: number;
  valid: number;
  invalid: number;
  duplicate: number;
  review: number;
  imported: number;
  skipped: number;
  failed: number;
}

export interface ImportBatch {
  id: number;
  type: ImportType;
  type_label: string;
  preview_only: boolean;
  status: ImportBatchStatus;
  created_by?: string | null;
  confirmed_by?: string | null;
  source_extension: string;
  source_size: number;
  counts: ImportCounts;
  confirmed_at: string | null;
  completed_at: string | null;
  retention_expires_at: string | null;
  details_deleted: boolean;
  details_deleted_at: string | null;
  created_at: string | null;
  pending_rows?: number;
  unrecognised_headers?: string[];
  activation_paths?: Record<string, number>;
  activation_warnings?: Record<string, number>;
  /** Employees on the activation-ready list (verified, with a transition path). */
  activation_ready?: number;
  activation_ready_by_path?: Record<string, number>;
  /** Rows excluded from activation until corrected, by reason code. */
  activation_excluded?: Record<string, number>;
  activation_excluded_total?: number;
  /** Rows that have a look-alike record. They are still INCLUDED (employee number and name match the plan). */
  activation_lookalikes_noted?: number;
}

export interface ImportRow {
  row_number: number;
  status: ImportRowStatus;
  code: string | null;
  message: string | null;
  record_type: string | null;
  record_id: number | null;
  /** Personal data from the sheet. Present only while the batch is retained (30 days). */
  details: Record<string, unknown> | null;
  meta: Record<string, unknown> | null;
  processed_at: string | null;
}
