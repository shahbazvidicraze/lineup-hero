<x-mail::message>
    # New Payment Notification

    A new payment has been successfully processed on {{ $appName }}.

    Payment Details:
    - Payment ID (Stripe): `{{ $payment->stripe_payment_intent_id }}`
    - Amount: {{ $amountFormatted }} {{ strtoupper($payment->currency) }}
    - Date: {{ $payment->paid_at->toFormattedDayDateString() }} at {{ $payment->paid_at->toTimeString() }}


    User Details:
    - User Name: {{ $user->first_name }} {{ $user->last_name }}
    - User Email: `{{ $user->email }}`

    Team Details:
    - Team Name: {{ $team->name }}

    Access for this team has been updated to 'paid_active'.

    Thanks,
    {{ $appName }} System
</x-mail::message>
