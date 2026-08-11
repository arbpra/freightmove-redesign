<?php

namespace App\Support;

/**
 * What the client should do next to pay.
 *
 * Either send the carrier to `approvalUrl`, or show `instructions` and wait —
 * never both, and never neither.
 */
class CheckoutIntent
{
    private function __construct(
        public readonly string $gateway,
        public readonly ?string $approvalUrl = null,
        public readonly ?string $reference = null,
        public readonly ?string $instructions = null,
    ) {}

    /** A redirect gateway: the carrier approves the payment on their site. */
    public static function redirect(string $gateway, string $approvalUrl, string $reference): self
    {
        return new self($gateway, $approvalUrl, $reference);
    }

    /** An offline arrangement: nothing to redirect to, someone will confirm it. */
    public static function offline(string $gateway, ?string $instructions): self
    {
        return new self($gateway, instructions: $instructions);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'approval_url' => $this->approvalUrl,
            'reference' => $this->reference,
            'payment_instructions' => $this->instructions,
        ];
    }
}
