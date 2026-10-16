<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class BookingException extends RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }

    public static function slotTaken(): self
    {
        return new self('That time slot is no longer available. Please pick another.', 409);
    }

    public static function tooManyActive(): self
    {
        return new self('You have reached the limit of upcoming appointments. Cancel one to book another.');
    }

    public static function cannotCancel(string $message): self
    {
        return new self($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }

    /** These are expected business outcomes, so keep them out of the error log. */
    public function report(): bool
    {
        return true;
    }
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
