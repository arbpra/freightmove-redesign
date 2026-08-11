/** Mirrors App\Enums\JobStatus. */
export type JobStatus =
  | 'draft'
  | 'published'
  | 'matched'
  | 'quoted'
  | 'accepted'
  | 'completed'
  | 'cancelled'
  | 'disputed';

/** Mirrors App\Http\Resources\FreightJobResource. */
export type LoadAvailability = 'asap' | 'ready_now' | 'available_from' | 'planning';

export interface TaxonomyTerm {
  id: number;
  name: string;
  slug: string;
}

/** GET /public/taxonomy — one vocabulary shared by every picker. */
export interface FreightTaxonomy {
  categories: TaxonomyTerm[];
  truck_types: TaxonomyTerm[];
  availability: { value: LoadAvailability; label: string }[];
}

export interface FreightJob {
  id: number;
  title: string;
  description: string | null;
  pickup_location: string;
  delivery_location: string;
  pickup_date: string | null;
  delivery_date: string | null;
  availability: LoadAvailability | null;
  availability_label: string | null;
  load_category: string | null;
  /** Present on show/store/update; the list endpoint omits the relations. */
  categories?: TaxonomyTerm[];
  truck_types?: TaxonomyTerm[];
  weight_tons: number | null;
  vehicle_type_required: string | null;
  trailer_type_required: string | null;
  budget_min: number | null;
  budget_max: number | null;
  status: JobStatus;
  visibility: 'public' | 'private';
  images: string[];
  /** Present only on list responses, which count rather than load quotes. */
  quotes_count?: number;
  /** Carrier board only: has the signed-in carrier already priced this load? */
  quoted_by_me?: boolean;
  /** When the shipper last bumped it; the board sorts on this before created_at. */
  relisted_at?: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** Statuses where a load is on the board and can therefore be bumped. */
export const RELISTABLE_STATUSES: readonly JobStatus[] = ['published', 'matched', 'quoted'];

export type QuoteStatus = 'pending' | 'accepted' | 'rejected' | 'expired';

export interface QuoteCarrier {
  id: number;
  name: string;
  company_name?: string;
  rating?: string | null;
  completed_jobs_count?: number;
  verification_status?: string;
  /** Only returned once the quote is accepted. */
  email?: string;
  phone?: string;
}

export interface JobQuote {
  id: number;
  job_id: number;
  amount: number;
  currency: string;
  estimated_delivery_date: string | null;
  notes: string | null;
  status: QuoteStatus;
  carrier?: QuoteCarrier;
  created_at: string | null;
}

export interface QuotesForJob {
  items: JobQuote[];
  job: { id: number; title: string; status: JobStatus; can_decide: boolean };
}

export interface Paginated<T> {
  items: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface JobListQuery {
  status?: JobStatus | '';
  search?: string;
  page?: number;
  per_page?: number;
}

/**
 * What the post-a-load form sends. `status` decides whether the load is saved
 * as a draft or goes live immediately; the API rejects any other value.
 */
export interface JobDraft {
  title: string;
  description?: string | null;
  pickup_location: string;
  delivery_location: string;
  pickup_date?: string | null;
  delivery_date?: string | null;
  availability?: LoadAvailability | null;
  /** A load can suit several of each — most real loads do. */
  category_ids?: number[];
  truck_type_ids?: number[];
  weight_tons?: number | null;
  budget_min?: number | null;
  budget_max?: number | null;
  status?: 'draft' | 'published';
}

/** Display metadata for each status, used by the badge in the jobs list. */
export const JOB_STATUS_LABEL: Record<JobStatus, string> = {
  draft: 'Draft',
  published: 'Live',
  matched: 'Matched',
  quoted: 'Quoted',
  accepted: 'Booked',
  completed: 'Completed',
  cancelled: 'Cancelled',
  disputed: 'Disputed',
};
