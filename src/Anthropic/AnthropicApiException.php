<?php

declare(strict_types=1);

namespace Verdikt\Anthropic;

/**
 * Any failure talking to the Anthropic API: transport errors, non-2xx
 * responses (code = HTTP status), or a response we cannot make sense of.
 */
final class AnthropicApiException extends \RuntimeException
{
}
