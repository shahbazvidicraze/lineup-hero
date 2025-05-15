<x-mail::message>
    # Payment Successful!

    Hi {{ $userName }},

    Great news! Your payment of {{ $currency }}{{ $amountFormatted }} for team "{{ $teamName }}" on {{ $appName }} has been successfully processed on {{ $paymentDate }}.

    Access to generate PDF lineups for "{{ $teamName }}" is now active!
    You can now access all features for your team within the app.

    Thank you for your payment!

    Best regards,
    The {{ $appName }} Team
</x-mail::message>
