<?php
/**
 * Logout Handler - The Bouncer at the Door
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is hands down the simplest file in the entire codebase, and honestly
 * it's kind of beautiful in its minimalism. It does exactly one thing: takes
 * the user's session out back and puts it down, then politely escorts them
 * to the login page. If every file in this project were this clean, we'd
 * all be sipping margaritas by now instead of debugging.
 */

// Boot up all the things -- yes, even for logout we need the full init.
// Seems like overkill, but the Auth class lives in there and we need it.
require_once 'includes/init.php';

// Grab the auth singleton -- because apparently singletons are still cool in PHP land
$auth = Auth::getInstance();

// Nuke the session from orbit. It's the only way to be sure.
$auth->logout();

// Aaaaand off you go back to the login page. Don't let the door hit you on the way out.
redirect('login.php');
