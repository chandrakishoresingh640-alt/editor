<?php
/**
 * Copy this file to config/environment.php and fill in only the values you use.
 *
 * environment.php lives outside the public web root. Do not commit real keys.
 * Empty values disable the optional integration and the app uses a local fallback.
 *
 * You can also set the same names as environment variables. Environment
 * variables override this file. See README.md.
 */
declare(strict_types=1);

return [
    // Public site name shown in the header.
    'app_name' => 'Quire',

    // Path prefix when the site is served from a subdirectory, for example '/quire'.
    // Leave empty when the domain points at the public/ directory.
    'base_path' => '',

    'timezone' => 'UTC',

    // Upload and processing limits. Keep these modest on shared hosting.
    'max_upload_bytes' => 26214400,
    'max_pdf_pages' => 30,
    'retention_minutes' => 60,

    // Rate limits. Unlock attempts are intentionally low so the tool cannot
    // be used to search for a password.
    'rate_upload_per_hour' => 40,
    'rate_process_per_hour' => 30,
    'rate_unlock_per_hour' => 12,
    'rate_ai_per_hour' => 20,

    // Optional OpenAI-compatible chat endpoint. Leave the key empty to use
    // the built-in extractive summary instead of an external model.
    'ai_api_key' => '',
    'ai_api_endpoint' => 'https://api.openai.com/v1',
    'ai_model' => 'gpt-4o-mini',
    'ai_timeout' => 45,

    // Optional YouTube Data API v3 key. Public oEmbed metadata still works
    // without a key. Caption download is not available from this key alone.
    'youtube_api_key' => '',

    // Optional Google OAuth client for Docs export and for captions of videos
    // the signed-in channel owns. Create the client in Google Cloud Console.
    'google_client_id' => '',
    'google_client_secret' => '',
    'google_redirect_uri' => '',

    // Contact address shown on the contact page. mail() is attempted when this
    // is set and the host allows it. Messages are also stored privately.
    'contact_email' => '',

    // Trust X-Forwarded-For only when a reverse proxy you control sets it.
    'trust_proxy' => false,

    // Keep false in production. Errors shown to visitors stay generic either way.
    'debug' => false,
];
