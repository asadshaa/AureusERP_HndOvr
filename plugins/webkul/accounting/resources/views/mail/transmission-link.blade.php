<x-mail::message>
# {{ $headline }}

{{ $senderName }} has sent you a document through AureusERP.

@if ($attachmentName)
A copy is attached to this email as **{{ $attachmentName }}**.
@endif

You can also open it securely online, where you can download the
machine-readable version your accounting software can import:

<x-mail::button :url="$claimUrl">
View document
</x-mail::button>

@if ($expiresAt)
This link expires on **{{ $expiresAt }}**.
@endif

If you weren't expecting this, you can ignore this email — the link will
stop working on its own.

Thanks,<br>
{{ $senderName }}
</x-mail::message>
