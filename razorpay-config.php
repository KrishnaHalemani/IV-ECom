<?php
declare(strict_types=1);

/*
 * Prefer environment variables in production:
 * RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET
 */
function razorpay_key_id(): string
{
    $env = trim((string) getenv('RAZORPAY_KEY_ID'));
    if ($env !== '') {
        return $env;
    }
    return 'rzp_live_SleCWnLam2JR3I';
}

function razorpay_key_secret(): string
{
    $env = trim((string) getenv('RAZORPAY_KEY_SECRET'));
    if ($env !== '') {
        return $env;
    }
    return 'JVamLTGnWRJo1Jd4l7qI261D';
}

