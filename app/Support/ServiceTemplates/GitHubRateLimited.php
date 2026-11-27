<?php

namespace App\Support\ServiceTemplates;

use Illuminate\Validation\ValidationException;

/**
 * GitHub turned down a request for the rate limit. The dashboard then has
 * the machine read the repository instead, when its daemon can.
 */
final class GitHubRateLimited extends ValidationException {}
