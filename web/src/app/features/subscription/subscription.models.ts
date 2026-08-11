/** Mirrors the public pricing endpoint. */
export interface SubscriptionPlan {
  code: string;
  name: string;
  price: number;
  /** "Valued at $64.99" on the trial; the undiscounted total on longer plans. */
  compare_at_price: number | null;
  currency: string;
  interval_months: number;
  is_trial: boolean;
  per_month: number;
  /** Computed server-side from the prices, never written into copy. */
  saving_per_month: number | null;
}

export interface PlanList {
  items: SubscriptionPlan[];
  trial_offer_open: boolean;
}

/** What `POST /carrier/subscription/checkout` answers with. */
export interface CheckoutResult {
  subscription_id: number;
  plan: string;
  amount: number;
  currency: string;
  gateway: string;
  /** Present for a redirect gateway such as PayPal. */
  approval_url: string | null;
  /** The gateway's own id for the checkout, echoed back on return. */
  reference: string | null;
  /** Present for an offline arrangement instead. */
  payment_instructions: string | null;
}

export interface CurrentSubscription {
  id: number;
  plan: string | null;
  plan_code: string | null;
  is_trial: boolean;
  starts_on: string | null;
  ends_on: string | null;
  days_remaining: number | null;
}

export interface SubscriptionState {
  current: CurrentSubscription | null;
  pending: { id: number; plan: string | null; amount: number } | null;
  trial: { eligible: boolean; reason: string | null };
  gateway: string;
  payment_instructions: string | null;
  required_to_quote: boolean;
  history: {
    plan: string | null;
    status: string;
    starts_on: string | null;
    ends_on: string | null;
  }[];
}

/**
 * Shared empty result for an unknown plan code.
 *
 * A module constant rather than a `?? []` in the template: a fresh array
 * literal is a new reference on every change-detection pass, which makes
 * `@for` tear down and rebuild the list each time.
 */
const NO_FEATURES: readonly string[] = Object.freeze([]);

export function featuresFor(code: string): readonly string[] {
  return PLAN_FEATURES[code] ?? NO_FEATURES;
}

/** What each plan gets you, per the live pricing page. */
export const PLAN_FEATURES: Record<string, string[]> = {
  trial: ['Move loads for free', 'No credit card needed', 'Connect with shippers', 'Australia wide'],
  monthly: ['Connect with shippers', 'Get quotes', 'Australia wide'],
  quarterly: ['Connect with shippers', 'Get quotes', 'Australia wide'],
  annual: ['Connect with shippers', 'Get quotes', 'Australia wide'],
};
