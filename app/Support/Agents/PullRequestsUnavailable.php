<?php

namespace App\Support\Agents;

use RuntimeException;

/** Agents could run, but not open pull requests where they were asked to: the task can go without them. */
final class PullRequestsUnavailable extends RuntimeException {}
