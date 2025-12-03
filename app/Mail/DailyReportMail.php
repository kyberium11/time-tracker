<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DailyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $userName;
    public string $date;
    public array $entries;
    public float $totalWorkHours;
    public float $totalTaskHours;

    /**
     * Create a new message instance.
     */
    public function __construct(
        string $userName,
        string $date,
        array $entries,
        float $totalWorkHours,
        float $totalTaskHours
    ) {
        $this->userName = $userName;
        $this->date = $date;
        $this->entries = $entries;
        $this->totalWorkHours = $totalWorkHours;
        $this->totalTaskHours = $totalTaskHours;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        return $this->subject('Daily Time Report - ' . $this->date)
            ->view('emails.daily-report')
            ->with([
                'userName' => $this->userName,
                'date' => $this->date,
                'entries' => $this->entries,
                'totalWorkHours' => $this->totalWorkHours,
                'totalTaskHours' => $this->totalTaskHours,
            ]);
    }
}

