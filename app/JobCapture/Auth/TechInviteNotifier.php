<?php

namespace App\JobCapture\Auth;

use App\Mail\TechCaptureInviteMail;
use App\Models\TechDevice;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers a tech's capture invite — the PWA link + one-time login code — to the address on their device.
 * Today the only channel is email (the operator still copies the link + code by hand when no address is on
 * file); a text/SMS channel would slot in here behind the same {@see send()} contract. Returns whether it
 * actually delivered, so the surfaces can tell the operator "emailed" vs "send it yourself".
 */
final class TechInviteNotifier
{
    /** Email the capture link + code to the tech; false = no address on file, operator delivers manually. */
    public function send(TechDevice $device, string $code, string $link): bool
    {
        $email = $device->email;
        if ($email === null || trim($email) === '') {
            return false;
        }

        Mail::to($email)->send(new TechCaptureInviteMail((string) $device->name, $code, $link, $this->brand($device)));

        return true;
    }

    /** White-label the invite to the tenant when resolvable, else the app name. */
    private function brand(TechDevice $device): string
    {
        $site = $device->site; // site_id is required on every device
        $branding = $site->account?->branding() ?? ['name' => (string) $site->brand_name];
        $name = (string) $branding['name'];

        return $name !== '' ? $name : (string) config('app.name');
    }
}
