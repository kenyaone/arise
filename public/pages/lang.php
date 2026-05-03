<?php
/**
 * ARISE Language Helper
 * Supports 'en' (English) and 'sw' (Kiswahili)
 * Language stored in $_SESSION['arise_lang'] (default 'en')
 */

if (session_status() === PHP_SESSION_NONE) session_start();

function t(string $key): string {
    static $translations = [
        'en' => [
            'home'           => 'Home',
            'modules'        => 'Modules',
            'forum'          => 'Forum',
            'ask_us'         => 'Ask Us',
            'help'           => 'Help',
            'sign_in'        => 'Sign In',
            'register'       => 'Register',
            'sign_out'       => 'Sign Out',
            'certificates'   => 'Certs',
            'dashboard'      => 'Dashboard',
            'pre_test'       => 'Pre-Test',
            'post_test'      => 'Post-Test',
            'submit'         => 'Submit',
            'take_quiz'      => 'Take Full Quiz',
            'back_to_module' => 'Back to Module',
            'correct'        => 'Correct',
            'your_answer'    => 'your answer',
            'no_questions'   => 'No test questions for this module yet.',
            'generate_code'  => 'Generate Code',
            'start_learning' => 'Start Learning',
            'complete'       => 'Complete',
        ],
        'sw' => [
            'home'           => 'Nyumbani',
            'modules'        => 'Moduli',
            'forum'          => 'Jukwaa',
            'ask_us'         => 'Tuulize',
            'help'           => 'Msaada',
            'sign_in'        => 'Ingia',
            'register'       => 'Jisajili',
            'sign_out'       => 'Toka',
            'certificates'   => 'Vyeti',
            'dashboard'      => 'Dashibodi',
            'pre_test'       => 'Mtihani wa Kwanza',
            'post_test'      => 'Mtihani wa Mwisho',
            'submit'         => 'Wasilisha',
            'take_quiz'      => 'Chukua Mchezo Kamili',
            'back_to_module' => 'Rudi kwa Moduli',
            'correct'        => 'Sahihi',
            'your_answer'    => 'jibu lako',
            'no_questions'   => 'Hakuna maswali ya mtihani kwa moduli hii bado.',
            'generate_code'  => 'Tengeneza Msimbo',
            'start_learning' => 'Anza Kujifunza',
            'complete'       => 'Kamili',
        ],
    ];

    $lang = $_SESSION['arise_lang'] ?? 'en';
    if ($lang !== 'en' && $lang !== 'sw') $lang = 'en';

    return $translations[$lang][$key] ?? $translations['en'][$key] ?? $key;
}
