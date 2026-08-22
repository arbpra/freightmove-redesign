/** Mirrors the envelope in docs/06-api-spec.md section 9. */
export interface ApiEnvelope<T> {
  success: boolean;
  data: T;
  message: string;
}

export interface ApiError {
  success: false;
  message: string;
  errors: Record<string, string[]>;
}

export type UserRole = 'guest' | 'shipper' | 'carrier' | 'admin';
export type UserStatus = 'pending' | 'active' | 'suspended' | 'blocked';
export type VerificationStatus = 'unverified' | 'pending' | 'verified' | 'rejected';

export interface UserProfile {
  company_name: string | null;
  abn_acn: string | null;
  city: string | null;
  state: string | null;
  verification_status: VerificationStatus;
  rating: string | null;
  completed_jobs_count: number;
}

export interface User {
  id: number;
  name: string;
  /** The parts behind `name`. Forms ask for these separately. */
  first_name: string | null;
  last_name: string | null;
  email: string;
  phone: string | null;
  role: UserRole;
  status: UserStatus;
  avatar_url: string | null;
  timezone: string;
  locale: string;
  email_verified: boolean;
  /** True for accounts imported from the old site that have not yet chosen
   *  a password here. Drives the migration prompt; never blocks anything. */
  should_update_password: boolean;
  profile?: UserProfile;
  created_at: string | null;
}

export interface AuthPayload {
  token: string;
  user: User;
}

export interface ChangePasswordRequest {
  current_password: string;
  password: string;
  password_confirmation: string;
}

export interface LoginRequest {
  email: string;
  password: string;
  device_name?: string;
}

export interface RegisterRequest {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  role: 'shipper' | 'carrier';
  phone?: string;
  company_name?: string;
  /**
   * Required for carriers, and rejected for shippers — the subscription is
   * what a carrier is signing up for. Omit the key entirely for shippers
   * rather than sending it empty.
   */
  subscription_plan?: string;
}

/** Where each role lands after signing in. */
export const HOME_ROUTE_FOR_ROLE: Record<UserRole, string> = {
  guest: '/',
  shipper: '/shipper',
  carrier: '/carrier',
  admin: '/admin',
};
