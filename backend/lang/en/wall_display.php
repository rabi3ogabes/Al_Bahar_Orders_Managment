<?php

/*
 | The view-only wall display account of the live board (docs/guides/SEARCH_AND_LIVE_BOARD.md, "Wall display account").
 */
return [
    'sign_out' => 'Sign out',
    'api_refused' => 'A wall display account can only show the live board.',
    'setting' => [
        'session_hours' => 'Wall display stays signed in for',
    ],
    'setting_hint' => [
        'session_hours' => 'A wall display account stays signed in while its board is open. If the screen has not been seen for this many hours it is signed out. It also has its own Sign out button.',
    ],
];
