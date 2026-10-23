<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google reCAPTCHA v3 verification for the public pre-registration form.
 */
class RecaptchaVerifier
{
    /**
     * Ask Google to verify the token and return its decoded answer (`success`, `score`, ...).
     * A body that is not a JSON object comes back as an empty or one-element array, which
     * the caller treats as unverified, as it did before this was extracted.
     *
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException when Google cannot be reached within the timeout
     */
    public function verify(?string $token, string $ip): array
    {
        return (array) Http::timeout(5)->asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => config('services.recaptcha.secret'),
            'response' => $token,
            'remoteip' => $ip,
        ])->json();
    }
}
