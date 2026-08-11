New enquiry from the FreightMove website.

Name:    {{ $enquiry->name }}
Email:   {{ $enquiry->email }}
Phone:   {{ $enquiry->phone ?: '—' }}
I am a:  {{ $enquiry->role ?: '—' }}
Subject: {{ $enquiry->subject ?: 'General enquiry' }}
Sent:    {{ $enquiry->created_at?->timezone('Australia/Sydney')->format('D j M Y, g:ia') }} AEST
@if ($enquiry->user_id)
Account: signed in as user #{{ $enquiry->user_id }}
@endif

Message
-------
{{ $enquiry->message }}

--
Reply to this email to answer {{ $enquiry->name }} directly.
Reference: contact message #{{ $enquiry->id }}
