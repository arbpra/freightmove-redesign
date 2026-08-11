/** Mirrors App\Enums\VerificationStatus. */
export type VerificationStatus = 'unverified' | 'pending' | 'verified' | 'rejected';

/** Mirrors App\Enums\DocumentStatus. */
export type DocumentStatus = 'pending' | 'approved' | 'rejected';

export interface VerificationDocument {
  id: number;
  document_type: string;
  /** The stored filename is randomised, so this is the only label a person recognises. */
  original_name: string | null;
  size_bytes: number | null;
  status: DocumentStatus;
  review_note: string | null;
  expires_at: string | null;
  has_lapsed: boolean;
  uploaded_at: string | null;
}

/** Mirrors App\Http\Resources\CarrierProfileResource. */
export interface CarrierProfile {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  avatar_url: string | null;

  company_name: string | null;
  abn_acn: string | null;
  business_type: string | null;
  address_line_1: string | null;
  address_line_2: string | null;
  city: string | null;
  state: string | null;
  postal_code: string | null;
  bio: string | null;

  fleet_size: number | null;
  service_radius_km: number | null;
  preferred_regions: string[];
  insurance_provider: string | null;
  insurance_policy_number: string | null;
  operating_since: number | null;

  /** Earned, not entered — the form never sends these back. */
  rating: number | null;
  completed_jobs_count: number;

  verification: {
    status: VerificationStatus;
    verified_at: string | null;
    note: string | null;
    documents?: VerificationDocument[];
  };
}

export interface DocumentTypeOption {
  key: string;
  label: string;
  required: boolean;
}

/** Served with the profile so the client never hardcodes the document list. */
export interface VerificationRequirements {
  document_types: DocumentTypeOption[];
  missing: string[];
  max_upload_kb: number;
  accepted_types: string[];
  required_to_quote: boolean;
}

export interface CarrierProfilePayload {
  profile: CarrierProfile;
  requirements: VerificationRequirements;
}

/** What the profile form may send. Deliberately excludes rating and verification. */
export interface CarrierProfileDraft {
  name?: string;
  phone?: string | null;
  company_name?: string | null;
  abn_acn?: string | null;
  business_type?: string | null;
  address_line_1?: string | null;
  city?: string | null;
  state?: string | null;
  postal_code?: string | null;
  bio?: string | null;
  fleet_size?: number | null;
  service_radius_km?: number | null;
  preferred_regions?: string[];
  insurance_provider?: string | null;
  insurance_policy_number?: string | null;
  operating_since?: number | null;
}

export const VERIFICATION_LABEL: Record<VerificationStatus, string> = {
  unverified: 'Not verified',
  pending: 'Under review',
  verified: 'Verified',
  rejected: 'Needs attention',
};

export const DOCUMENT_STATUS_LABEL: Record<DocumentStatus, string> = {
  pending: 'Under review',
  approved: 'Approved',
  rejected: 'Rejected',
};
