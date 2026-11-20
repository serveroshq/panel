@props(['padding' => '6px 22px'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F4F4F2" style="background-color:#F4F4F2; margin:8px 0 24px;">
    <tr>
        <td style="padding:{{ $padding }};">{{ $slot }}</td>
    </tr>
</table>
