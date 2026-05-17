<?php
/*
  LebanEASE AI configuration.
  Safe GitHub version.
  Do not place your real API key here.
*/

$envKey = getenv('OPENAI_API_KEY');

if (!defined('LEBANEASE_AI_API_KEY')) {
    define('LEBANEASE_AI_API_KEY', trim($envKey ?: ''));
}

if (!defined('LEBANEASE_AI_MODEL')) {
    define('LEBANEASE_AI_MODEL', 'gpt-4o-mini');
}
?>