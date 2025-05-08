<!DOCTYPE html>
<html lang="en">
<head><title>Payment Successful</title><style>body { font-family: sans-serif; padding: 20px; }</style></head>
<body>
    <h1>Payment Successful!</h1>
    <p>Thank you for your payment.</p>
    <p>Access for team '{{ $teamName ?? 'your team' }}' should be updated shortly. Please check back in a moment.</p>
    {{-- Add link back to team dashboard if applicable --}}
    {{-- @if($teamId) <a href="{{ route('team.dashboard', $teamId) }}">Go to Team Dashboard</a> @endif --}}
</body>
</html>
