<?php

namespace App\Mail;

use App\Models\Payment;
use App\Models\User;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserPaymentSuccessMail extends Mailable // implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Payment $payment;
    public User $user;
    public Team $team;

    /**
     * Create a new message instance.
     */
    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
        $this->payment->loadMissing(['user', 'team']); // Ensure relationships are loaded
        $this->user = $this->payment->user;
        $this->team = $this->payment->team;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Payment to ' . config('app.name') . ' Was Successful!',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.user.payment-success',
            with: [
                'userName' => $this->user->first_name,
                'appName' => config('app.name'),
                'teamName' => $this->team->name,
                'amountFormatted' => number_format($this->payment->amount / 100, 2),
                'currency' => strtoupper($this->payment->currency),
                'paymentDate' => $this->payment->paid_at->toFormattedDayDateString(),
                // Optional: Link to team dashboard or relevant page
                // 'teamDashboardUrl' => route('web.team.dashboard', $this->team->id), // Example if web routes exist
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
