<?php
// Plain "I am running" answer for Render's health check or an uptime pinger. Does not touch the database.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'ok';
